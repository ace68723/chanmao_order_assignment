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
        $this->consts['TIMEZONE'] = 'America/Toronto';
        $this->consts['ORDER_LIVE_HOURS'] = 4;
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
        $timer = -time(true);
        $dt = new \DateTime($this->consts['ORDER_LIVE_HOURS'].' hour ago',
            new \DateTimeZone($this->consts['TIMEZONE']));
        $whereCond = [
            ['ob.status','>=', 10], ['ob.status','<=', 30],
            ['ob.created', '>', $dt->format('Y-m-d H:i:s')],
            ['ob.dltype','>=', 1], ['ob.dltype','<=', 3], ['ob.dltype', '!=', 2],
            ['rl.area','=',0],
            ['rb.area','=',$area],
        ];
        $res = DB::table('cm_order_base as ob')
            ->join('cm_rr_base as rb', 'rb.rid','=','ob.rid')
            ->join('cm_rr_loc as rl', 'rl.rid','=','rb.rid')
            ->join('cm_user_address as ua', 'ua.uaid','=','ob.uaid')
            ->join('cm_order_trace as ot', 'ot.oid','=','ob.oid')
            ->select('ob.oid', 'ob.rid', 'ob.uid', 'ob.uaid', 'ob.pptime',
                'ot.driver_id', 'ot.assign', 'ot.pickup',
                'rb.area','rl.rr_la as rr_lat', 'rl.rr_lo as rr_lng', 'rb.addr as rr_addr',
                'ua.addr as user_addr','ua.loc_la as user_lat','ua.loc_lo as user_lng')
            ->where($whereCond)->get();
        $timer += time(true);
        Log::debug("got ".count($res)." orders for area $area". " takes:".$timer." secs");
        return $res;
    }
    private function getDrivers($area) {
        if (!isset($this->consts['AREA'][$area])) {
            Log::debug("unknown area code:$area");
            return [];
        }
        $timer = -time(true);
        $data = do_curl('https://www.chanmao.ca/index.php?r=MobAly10/DriverLoc', 'GET');
        $drivers = $data['drivers']??[];
        $ret = [];
        foreach($drivers as $dr)
            if ($dr['area'] == $this->consts['AREA'][$area]){
                $ret[] = $dr;
            }
        $timer += time(true);
        Log::debug("got ".count($ret)." drivers for area $area". " takes:".$timer." secs");
        return $ret;
    }
    public function reload($area) {
        $orders = $this->getOrders($area);
        $drivers = $this->getDrivers($area);
        return ['orders'=>$orders, 'drivers'=>$drivers];
    }

}
