<?php
namespace App\Providers\ScheduleService;

use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use App\Exceptions\CmException;

class ScheduleService{

    public $consts;
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
        $this->consts['EXT_ERROR'] = [
            1 => 'E_EXCEED_MAXN',
            7 => 'E_WRONG_INDEX',
            8 => 'E_CONFLICT_SETTING',
        ];
    }

    // check consistency, remove unnecessary tasks/driver/locations, convert to the input for module
    private function preprocess($orders, $drivers, $area) {
        $curTime = time();
        $tasks = [];
        $locations = [];
        $workload = [];
        foreach($orders as $order) {
            $order = (array)$order;
            if ($order['area'] != $area) {
                if (empty($order['driver_id'])) continue;
                $driver_id = $order['driver_id'];
                if (!isset($drivers[$driver_id]['area']) || $drivers[$driver_id]['area'] != $this->consts['AREA'][$area]) continue;
            }
            $prevTask = null;
            if (!empty($order['driver_id'])) {
                Log::debug("order ".$order['oid']." assigned to driver ".$order['driver_id']);
                $workload[$order['driver_id']] = 1+($workload[$order['driver_id']]??0);
            }
            if ($order['status'] == 30) {
                if ($order['pickup'] <= 0) {
                    Log::debug("empty pickup time for order ".$order['oid']);
                }
            }
            else {
                if ($order['pickup'] > 0) {
                    Log::debug("non-empty pickup time for order ".$order['oid']. " with status ". $order['status']);
                }
                $pptime = $this->consts['PPTIME'][$order['pptime']]?? $this->consts['PPTIME']['default'];
                $locId = 'rr-'.$order['rid'];
                $locations[$locId] = [
                    'lat'=>$order['rr_lat'],'lng'=>$order['rr_lng'],'addr'=>$order['rr_addr'],
                ];
                $task_id = $order['oid']."P";
                $tasks[$task_id] = [
                    'oid'=>$order['oid'],
                    'task_id'=>$task_id,
                    'locId'=>$locId,
                    'deadline'=>$order['rraction']+$pptime,
                    'readyTime'=>$order['rraction']+$pptime,
                    'execTime'=>$this->consts['PICKUP_SEC'],
                    'driver_id'=>empty($order['driver_id'])?null:$order['driver_id'],
                    'prevTask'=>null,
                    'nextTask'=>$order['oid']."D",
                    'rwdOneTime'=>$this->consts['REWARD_ONETIME']['P'],
                    'pnlOneTime'=>$this->consts['PENALTY_ONETIME']['P'],
                    'rwdPerSec'=>$this->consts['REWARD_PERSEC']['P'],
                    'pnlPerSec'=>$this->consts['PENALTY_PERSEC']['P'],
                ];
                $prevTask = $task_id;
            }
            $locId = 'user-'.$order['uaid'];
            $locations[$locId] = [
                'lat'=>$order['user_lat'],'lng'=>$order['user_lng'],'addr'=>$order['user_addr'],
            ];
            $task_id = $order['oid']."D";
            $tasks[$task_id] = [
                'oid'=>$order['oid'],
                'task_id'=>$task_id,
                'locId'=>$locId,
                'deadline'=>$order['initiate']+$this->consts['DEADLINE_SEC'],
                'readyTime'=>0,
                'execTime'=>$this->consts['HANDOVER_SEC'],
                'driver_id'=>empty($order['driver_id'])?null:$order['driver_id'],
                'prevTask'=>$prevTask,
                'nextTask'=>null,
                'rwdOneTime'=>$this->consts['REWARD_ONETIME']['D'],
                'pnlOneTime'=>$this->consts['PENALTY_ONETIME']['D'],
                'rwdPerSec'=>$this->consts['REWARD_PERSEC']['D'],
                'pnlPerSec'=>$this->consts['PENALTY_PERSEC']['D'],
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
                Log::debug("ignore driver ".$driver['driver_id']." because of workload");
                continue;
            }
            $locId = 'dr-'.$driver['driver_id'];
            $locations[$locId] = [
                'lat'=>floatval($driver['lat']),'lng'=>floatval($driver['lng']),'addr'=>null,
            ];
            $available_drivers[$driver['driver_id']] = [
                'driver_id'=>$driver['driver_id'],
                'availableTime'=>max($curTime,$driver['valid_from']),
                'offTime'=>$driver['valid_to'],
                'locId'=>$locId,
                'maxNOrder'=>$maxNOrder,
                'distFactor'=>$driver['distFactor']??1.0,
                'pickFactor'=>$driver['pickFactor']??1,
                'deliFactor'=>$driver['deliFactor']??1,
            ];
        }
        $this->fixCurTask($available_drivers, $tasks);
        $locIds = []; $task_ids = [];
        foreach($tasks as $task) {
            if (!empty($task['driver_id']) && !isset($available_drivers[$task['driver_id']])) {
                Log::debug("ignoring task".$task['task_id']." assigned to unavailable drivers ".$task['driver_id']);
                continue;
            }
            $locIds[$task['locId']] = 1;
            $task_ids[$task['task_id']] = 1;
        }
        foreach($available_drivers as $driver) {
            $locIds[$driver['locId']] = 1;
        }
        $tasks = array_where($tasks, function($value, $key) use($task_ids) {return isset($task_ids[$key]);});
        $locations = array_where($locations, function($value, $key) use($locIds) {return isset($locIds[$key]);});
        return [$available_drivers, $tasks, $locations];
    }
    private function map_service_get_dist($drivers, $tasks, $locations) {
        Log::debug("processed locations:".json_encode($locations));
        return [];
    }
    private function fixCurTask(&$drivers, &$tasks) {
        return;
    }
    private function keyToIdx($arr, $id_name) {
        $idToInt = [];
        $i = 0;
        foreach ($arr as $item) {
            if (isset($idToInt[$item[$id_name]])) {
                //duplicate id
                return null;
            }
            $idToInt[$item[$id_name]] = $i;
            $i += 1;
        }
        return $idToInt;
    }
    public function reload($area) {
        $orderCache = app()->make('cmoa_model_cache_service')->get('orderCache');
        $driverCache = app()->make('cmoa_model_cache_service')->get('driverCache');
        $orders = $orderCache->get_orders();
        $drivers = $this->getDrivers($area);
        list($driver_dict, $task_dict, $loc_dict) = $this->preprocess($orders, $drivers, $area);
        Log::debug('preprocess returns '.count($driver_dict). ' drivers, '.
            count($task_dict).' tasks and '.count($loc_dict).' locations');
        return ['tasks'=>$task_dict, 'drivers'=>$driver_dict, 'locations'=>$loc_dict];
    }
    public function sim($input) {
        $task_dict = $input['tasks'];
        $driver_dict = $input['drivers'];
        $loc_dict = $input['loc_dict'];
        $dist_mat = $input['dist_mat'];
        $schedules = $this->ext_wrapper($task_dict, $driver_dict, $loc_dict, $dist_mat);
        $schCache = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        $scheCache->set_schedules($schedules, time());
        return $schedules;
    }
    public function ext_wrapper($task_dict, $driver_dict, $loc_dict, $dist_mat) {
        foreach ($task_dict as $task_id=>$task) {
            $task_dict[$task_id]['location'] = $loc_dict[$task['locId']]['idx'];
        }
        foreach ($driver_dict as $driver_id=>$driver) {
            $driver_dict[$driver_id]['location'] = $loc_dict[$driver['locId']]['idx'];
        }
        $task_arr = array_values($task_dict);
        $driver_arr = array_values($driver_dict);
        $tidMap = $this->keyToIdx($task_arr, 'task_id');
        $didMap = $this->keyToIdx($driver_arr, 'driver_id');
        foreach ($task_arr as $i=>$task) {
            $driver_id = ($didMap[$task["driver_id"]??null])??-1;
            $prevTask = $tidMap[$task["prevTask"]??null]??-1;
            $nextTask = $tidMap[$task["nextTask"]??null]??-1;
            $task_arr[$i]['prevTask'] = $prevTask;
            $task_arr[$i]['nextTask'] = $nextTask;
            $task_arr[$i]['did'] = $driver_id;
            $task_arr[$i]['tid'] = $tidMap[$task['task_id']];
        }
        foreach ($driver_arr as $i=>$driver) {
            $driver_arr[$i]['did'] = $didMap[$driver["driver_id"]];
        }
        $nLocations = count($dist_mat);
        foreach($dist_mat as $row) {
            if ($nLocations != count($row))
                throw new CmException('SYSTEM_ERROR','ill formatted dist matrix');
        }
        $input = [
            'tasks'=>$task_arr,
            'drivers'=>$driver_arr,
            'distMat'=>$dist_mat,
            'nLocations'=>$nLocations,
        ];
        $logCache = app()->make('cmoa_model_cache_service')->get('LogCache');
        $logCache->log('cmoa_ext_input', $input);
        $ret = cmoa_schedule($input);
        $logCache->log('cmoa_ext_output', $ret);
        if ($ret['ev_error'] != 0) {
            throw new CmException('SYSTEM_ERROR', 'extension_returned_error:'.$ret['ev_error']
                .':'.($this->consts[$ret['ev_error']]??'unknown error code'));
        }
        $schedules = [];
        foreach($ret['schedules'] as $sche) {
            $driver = $driver_arr[$sche['did']];
            $newDriverItem = ['driver_id'=>$driver['driver_id'], 'tasks'=>[]];
            foreach($sche['tids'] as $i=>$tid) {
                $newTaskItem = [
                    'task_id'=>$task_arr[$tid]['task_id'],
                    'completeTime'=>$sche['completeTime'][$i],
                    'locId'=>$task_arr[$tid]['locId'],
                ];
                $newTaskItem['location'] = $loc_dict[$newTaskItem['locId']];
                $newDriverItem['tasks'][] = $newTaskItem;
            }
            $schedules[$driver['driver_id']] = $newDriverItem;
        }
        return $schedules;
    }
    public function to_dist_mat($input) {
        $loc_dict = $input['locations'];
        $map_sp = app()->make('cmoa_map_service');
        $dist_mat = $map_sp->get_dist_mat($loc_dict);
        return ['loc_dict'=>$loc_dict, 'dist_mat'=>$dist_mat];
    }
}
