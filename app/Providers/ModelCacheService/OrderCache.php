<?php
namespace App\Providers\ModelCacheService;

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class OrderCache{
    private $prefix;
    private $consts;
    const DEBUG_KEEP_SEC = 3600*4;
    const QUERY_INTERVAL_SEC = 3600;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
    }
    public function get_orders_from_remote($area=-1) {
        $timer = -microtime(true);
        $dt = new \DateTime($this->consts['ORDER_LIVE_HOURS'].' hour ago',
            new \DateTimeZone($this->consts['TIMEZONE']));
        $whereCond = [
            ['ob.status','>=', 10], ['ob.status','<=', 30],
            ['ob.created', '>', $dt->format('Y-m-d H:i:s')],
            ['ob.dltype','>=', 1], ['ob.dltype','<=', 3], ['ob.dltype', '!=', 2],
            ['rl.area','=',0],
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
                'rb.area','rl.rr_la as rr_lat', 'rl.rr_lo as rr_lng', 'rb.addr as rr_addr',
                'ua.addr as user_addr','ua.loc_la as user_lat','ua.loc_lo as user_lng')
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
            Redis::setex($key, self::QUERY_INTERVAL_SEC, json_encode($order));
            //we do not del keys we let keys expire
        }
    }
    public function get_orders() {
        $updatedAt = Redis::get($this->prefix. "updatedAt");
        $updatedAt = is_null($updatedAt) ? 0 : (int)$updatedAt;
        $curTime = time();
        if ($curTime - $updatedAt > self::QUERY_INTERVAL_SEC) {
            $this->reload();
        }
        $keys = Redis::keys($this->prefix."order:*");
        $rets = Redis::mget($keys);
        $orders = [];
        foreach($rets as $rows) if (!is_null($rows)) {
            $orders[] = json_decode($rows, true);
        }
        Log::debug("read ".count($orders)." orders:".json_encode($orders));
        return $orders;
    }
}
