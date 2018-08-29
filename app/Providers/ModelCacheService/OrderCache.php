<?php
namespace App\Providers\ModelCacheService;

use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class OrderCache{
    private $prefix;
    private $consts;
    const DEBUG_KEEP_SEC = 3600*4;
    const QUERY_INTERVAL_SEC = 60;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
        $this->consts['TIMEZONE'] = 'America/Toronto';
        $this->consts['FETCH_ORDER_AGO_HOURS'] = 4;
    }
    public function get_orders_from_remote($area=-1) {
        $timer = -microtime(true);
        $dt = new \DateTime($this->consts['FETCH_ORDER_AGO_HOURS'].' hour ago',
            new \DateTimeZone($this->consts['TIMEZONE']));
        $whereCond = [
            ['ob.status','>=', 10], ['ob.status','<=', 30],
            ['ob.created', '>', $dt->format('Y-m-d H:i:s')],
            ['ob.dltype','>=', 1], ['ob.dltype','<=', 3], ['ob.dltype', '!=', 2],
            ['rl.area','=',0],
            //['ob.rid','!=',5], //escape test merchant
        ];
        if (!is_null($area) && $area != -1) {
            $whereCond[] = ['rb.area','=',$area];
        }
        $sql = DB::table('cm_order_base as ob')
            ->join('cm_rr_base as rb', 'rb.rid','=','ob.rid')
            ->join('cm_rr_loc as rl', 'rl.rid','=','rb.rid')
            ->join('cm_user_address as ua', 'ua.uaid','=','ob.uaid')
            ->join('cm_order_trace as ot', 'ot.oid','=','ob.oid')
            ->select('ob.oid', 'ob.rid', 'ob.uid', 'ob.uaid', 'ob.pptime', 'ob.status',
                'ot.driver_id', 'ot.initiate', 'ot.rraction', 'ot.assign', 'ot.pickup',
                'rb.area','rl.rr_la as rr_lat', 'rl.rr_lo as rr_lng', 'rb.addr as rr_addr', 'rb.name as rr_name',
                'ua.addr as user_addr', 'ua.loc_la as user_lat', 'ua.loc_lo as user_lng'
            )
             ->where($whereCond);
        //Log::debug("sql:". $sql->toSql());
        $res = $sql->get();
        $timer += microtime(true);
        Log::debug("got ".count($res)." orders from remote for area ".$area. " takes:".$timer." secs");
        return $res;
    }
    public function reload() {
        $orders = $this->get_orders_from_remote();
        Redis::setex($this->prefix."updatedAt", self::DEBUG_KEEP_SEC, time());
        foreach($orders as $order) {
            $key = $this->prefix."order:".$order->oid;
            Redis::setex($key, 2+self::QUERY_INTERVAL_SEC, json_encode($order));
            //plus 2 is for the rare case of expire before reload
        }
    }
    public function get_orders($allow_test_orders = false) {
        $updatedAt = Redis::get($this->prefix. "updatedAt");
        $updatedAt = is_null($updatedAt) ? 0 : (int)$updatedAt;
        $curTime = time();
        if ($curTime - $updatedAt > self::QUERY_INTERVAL_SEC) {
            $this->reload();
        }
        $keys = Redis::keys($this->prefix."order:*");
        if (empty($keys)) return [];
        $rets = Redis::mget($keys);
        $orders = [];
        foreach($rets as $rows) if (!is_null($rows)) {
            $order = json_decode($rows, true);
            if (!$allow_test_orders && $order['rid'] == 5) continue;
            $orders[] = $order;
        }
        //Log::debug("read ".count($orders)." orders:".json_encode(array_pluck($orders,'oid')));
        return $orders;
    }
    public function calc_heat_map($recent_days = 7) {
        $dt = new \DateTime($recent_days.' days ago', new \DateTimeZone($this->consts['TIMEZONE']));
        $today = new \DateTime('now', new \DateTimeZone($this->consts['TIMEZONE']));
        $whereCond = [
            ['ob.created', '>', $dt->format('Y-m-d')],
            ['ob.created', '<', $today->format('Y-m-d')],
            ['ob.dltype','>=', 1], ['ob.dltype','<=', 3], ['ob.dltype', '!=', 2],
            ['rl.area','=',0],
            ['ob.rid','!=',5], //escape test merchant
        ];
        $sql = DB::table('cm_order_base as ob')
            ->join('cm_rr_loc as rl', 'rl.rid','=','ob.rid')
            ->join('cm_order_trace as ot', 'ot.oid','=','ob.oid')
            ->select('ob.oid', 'ob.rid', 'ob.status', 'ot.rraction',
                'rl.rr_la as rr_lat', 'rl.rr_lo as rr_lng')
             ->where($whereCond);
        $res = $sql->get();
        $count = [];
        foreach($res as $rec) {
            if ($rec->rraction<=0) continue;
            $t = new \DateTime('now', new \DateTimeZone($this->consts['TIMEZONE']));
            $t->setTimestamp($rec->rraction);
            $weekday = $t->format('w');
            $hour = $t->format('G');
            $latlng = sprintf('%.4f,%.4f', $rec->rr_lat, $rec->rr_lng);
            if (!isset($count[$weekday][$hour][$latlng])) {
                $count[$weekday][$hour][$latlng]=0;
            }
            $count[$weekday][$hour][$latlng]+=1;
        }
        return $count;
    }
    public function get_heat_map() {
        $dt = new \DateTime('now', new \DateTimeZone($this->consts['TIMEZONE']));
        $key = $this->prefix."heatMap:".$dt->format('w').":".$dt->format('G');
        $data = Redis::hgetall($key);
        return $data;
    }
    public function reset_heat_map() {
        $count = $this->calc_heat_map(90);
        foreach($count as $weekday=>$row) {
            foreach($row as $hour=>$heat_map) {
                $key = $this->prefix."heatMap:".$weekday.":".$hour;
                $contents = [];
                foreach($heat_map as $k=>$v) {$contents[] = $k; $contents[] = $v;}
                Redis::hmset($key, ...$contents);
            }
        }
    }
}
