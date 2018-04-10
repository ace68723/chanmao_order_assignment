<?php
namespace App\Providers\ScheduleService;

require_once  __DIR__."/lib/mycurl.php";
use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

class ScheduleService{

    public $consts;
    public $data;
    public function __construct()
    {
        $this->consts = array();
        $this->consts['MAXNLOCATIONS'] = 100;
        $this->consts['DEADLINE_SEC'] = 60*80; //promised time, from initiate to deliver: 1h20min
        $this->consts['HANDOVER_SEC'] = 60*7; //7 min to hand over to customer
        $this->consts['PICKUP_SEC'] = 60*5; //5 min to hand over to customer
        $this->consts['REWARD_ONETIME'] = ['D'=>100,'P'=>0];
        $this->consts['REWARD_PERSEC'] = ['D'=>0.1,'P'=>0];
        $this->consts['PENALTY_ONETIME'] = ['D'=>0,'P'=>0];
        $this->consts['PENALTY_PERSEC'] = ['D'=>0.1,'P'=>0.01];
        $this->consts['TIMEZONE'] = 'America/Toronto';
        $this->consts['ORDER_LIVE_HOURS'] = 4; //load orders created in the last x hours
        $this->consts['DRIVER_LIVE_SEC'] = 4*3600; //for how long the driver's location stays usable
        $this->consts['PPTIME'] = [
            'default'=>60*20,
            '< 10' => 60*10,
            '20' => 60*20,
            '30' => 60*30,
            '> 40' => 60*50,
        ];
        $this->consts['AREA'] = [
            1=>'SC',
            2=>'NY',
            3=>'RH',
            4=>'MH',
            5=>'MH',
            6=>'DT',
            10=>'MI',
        ];
    }

    private function getOrders($area) {
        $timer = -microtime(true);
        $dt = new \DateTime($this->consts['ORDER_LIVE_HOURS'].' hour ago',
            new \DateTimeZone($this->consts['TIMEZONE']));
        $whereCond = [
            ['ob.status','>=', 10], ['ob.status','<=', 30],
            ['ob.created', '>', $dt->format('Y-m-d H:i:s')],
            ['ob.dltype','>=', 1], ['ob.dltype','<=', 3], ['ob.dltype', '!=', 2],
            ['rl.area','=',0],
            ['rb.area','=',$area],
        ];
        $sql = DB::table('cm_order_base as ob')
            ->join('cm_rr_base as rb', 'rb.rid','=','ob.rid')
            ->join('cm_rr_loc as rl', 'rl.rid','=','rb.rid')
            ->join('cm_user_address as ua', 'ua.uaid','=','ob.uaid')
            ->join('cm_order_trace as ot', 'ot.oid','=','ob.oid')
            ->select('ob.oid', 'ob.rid', 'ob.uid', 'ob.uaid', 'ob.pptime', 'ob.status',
                'ot.driver_id', 'ot.initiate', 'ot.rraction', 'ot.assign', 'ot.pickup',
                'rb.area','rl.rr_la as rr_lat', 'rl.rr_lo as rr_lng', 'rb.addr as rr_addr',
                'ua.addr as user_addr','ua.loc_la as user_lat','ua.loc_lo as user_lng')
             ->where($whereCond);
        //Log::debug("sql:". $sql->toSql());
        $res = $sql->get();
        $timer += microtime(true);
        Log::debug("got ".count($res)." orders for area ".$area. " takes:".$timer." secs");
        return $res;
    }
    private function getDrivers($area) {
        if (!isset($this->consts['AREA'][$area])) {
            Log::debug("unknown area code:$area");
            return [];
        }
        $timer = -microtime(true);
        $data = do_curl('https://www.chanmao.ca/index.php?r=MobAly10/DriverLoc', 'GET');
        $drivers = $data['drivers']??[];
        $ret = [];
        foreach($drivers as $dr)
            if ($dr['area'] == $this->consts['AREA'][$area]){
                $ret[] = $dr;
            }
        $timer += microtime(true);
        Log::debug("got ".count($ret)." drivers for area ".$area. " takes:".$timer." secs");
        return $ret;
    }
    private function preprocess($orders, $drivers) {
        $curTime = time();
        $tasks = [];
        $locations = [];
        $workload = [];
        foreach($orders as $order) {
            $order = (array)$order;
            if (!empty($order['driver_id'])) {
                $workload[$order['driver_id']] = 1+($workload['driver_id']??0);
            }
            $locations['user'.$order['uaid']] = [
                'lat'=>$order['user_lat'],'lng'=>$order['user_lng'],'addr'=>$order['user_addr'],
            ];
            $tid = $order['oid']."D";
            $tasks[$tid] = [
                'oid'=>$order['oid'],
                'tid'=>$tid,
                'locId'=>'user'.$order['uaid'],
                'deadline'=>$order['initiate']+$this->consts['DEADLINE_SEC'],
                'readyTime'=>0,
                'execTime'=>$this->consts['HANDOVER_SEC'],
                'did'=>empty($order['driver_id'])?null:$order['driver_id'],
                'prevTask'=>$order['oid']."P",
                'nextTask'=>null,
                'rwdOneTime'=>$this->consts['REWARD_ONETIME']['D'],
                'pnlOneTime'=>$this->consts['PENALTY_ONETIME']['D'],
                'rwdPerSec'=>$this->consts['REWARD_PERSEC']['D'],
                'pnlPerSec'=>$this->consts['PENALTY_PERSEC']['D'],
            ];
            if ($order['status'] == 30) {
                if ($order['pickup'] <= 0) {
                    Log::debug("empty pickup time for order ".$order['oid']);
                }
                continue;
            }
            else {
                if ($order['pickup'] > 0) {
                    Log::debug("non-empty pickup time for order ".$order['oid']. " with status ". $order['status']);
                }
            }
            $pptime = $this->consts['PPTIME'][$order['pptime']]?? $this->consts['PPTIME']['default'];
            $locations['rr'.$order['rid']] = [
                'lat'=>$order['rr_lat'],'lng'=>$order['rr_lng'],'addr'=>$order['rr_addr'],
            ];
            $tid = $order['oid']."P";
            $tasks[$tid] = [
                'oid'=>$order['oid'],
                'tid'=>$tid,
                'locId'=>'rr'.$order['rid'],
                'deadline'=>$order['rraction']+$pptime,
                'readyTime'=>$order['rraction']+$pptime,
                'execTime'=>$this->consts['PICKUP_SEC'],
                'did'=>empty($order['driver_id'])?null:$order['driver_id'],
                'prevTask'=>null,
                'nextTask'=>$order['oid']."D",
                'rwdOneTime'=>$this->consts['REWARD_ONETIME']['P'],
                'pnlOneTime'=>$this->consts['PENALTY_ONETIME']['P'],
                'rwdPerSec'=>$this->consts['REWARD_PERSEC']['P'],
                'pnlPerSec'=>$this->consts['PENALTY_PERSEC']['P'],
            ];
        }
        $available_drivers = [];
        foreach($drivers as &$driver) {
            $driver['valid_from'] = intval($driver['valid_from']);
            $driver['valid_to'] = intval($driver['valid_to']);
            $driver['timestamp'] = intval($driver['timestamp']);
            if ($driver['timestamp'] == 1) continue;
            if ($curTime - $driver['timestamp'] > $this->consts['DRIVER_LIVE_SEC']) {
                Log::debug("ignore driver ".$driver['driver_id']." because of lost position");
                continue;
            }
            if ($curTime >= $driver['valid_to']) {
                Log::debug("ignore driver ".$driver['driver_id']." because of off work");
                continue;
            }
            //if ($curTime < $driver['valid_from']) continue; //??
            $thisworkload = ($workload[$driver['driver_id']]??0);
            $otherWorkload = 0;
            if ($driver['workload'] != $thisworkload) {
                Log::debug('driver '.$driver['driver_id'].' workload mismatch:'.$driver['workload'].' find orders:'.$thisworkload);
                $otherWorkload = max(0, $driver['workload']-$thisworkload);
            }
            $maxNOrder = ($driver['maxNOrder']??5)-$otherWorkload;
            if ($maxNOrder <= 0) {
                Log::debug("ignore driver ".$driver['driver_id']." because of other workload");
                continue;
            }
            $locations['dr'.$driver['driver_id']] = [
                'lat'=>floatval($driver['lat']),'lng'=>floatval($driver['lng']),'addr'=>null,
            ];
            $available_drivers[$driver['driver_id']] = [
                'did'=>$driver['driver_id'],
                'availableTime'=>max($curTime,$driver['valid_from']),
                'offTime'=>$driver['valid_to'],
                'locId'=>'dr'.$driver['driver_id'],
                'maxNOrder'=>$maxNOrder,
                'distFactor'=>$driver['distFactor']??1,
                'pickFactor'=>$driver['pickFactor']??1,
                'deliFactor'=>$driver['deliFactor']??1,
            ];
        }
        $this->fixCurTask($available_drivers, $tasks);
        $locIds = []; $tIds = [];
        foreach($tasks as $task) {
            if (!empty($task['did']) && !isset($available_drivers[$task['did']])) {
                Log::debug("ignoring task".$task['tid']." assigned to unavailable drivers ".$task['did']);
                continue;
            }
            $locIds[$task['locId']] = 1;
            $tIds[$task['tid']] = 1;
        }
        foreach($available_drivers as $driver) {
            $locIds[$driver['locId']] = 1;
        }
        Log::debug('tasks:'.json_encode($tIds));
        $tasks = array_where($tasks, function($value, $key) {return isset($tIds[$key]);});
        $filtered_locations = array_only($locations, $locIds);
        $this->map_service_get_dist($drivers, $tasks);
    }
    private function map_service_get_dist($drivers, $tasks) {
        Log::debug("processed drivers:".json_encode($drivers));
        Log::debug("processed tasks:".json_encode($tasks));
        return [];
    }
    private function fixCurTask(&$drivers, &$tasks) {
        return;
    }
    private function keyToIdx($drivers, $tasks) {
        return;
    }
    public function reload($area) {
        $orders = $this->getOrders($area);
        $drivers = $this->getDrivers($area);
        $this->preprocess($orders, $drivers);
        return ['orders'=>$orders, 'drivers'=>$drivers];
    }

}
