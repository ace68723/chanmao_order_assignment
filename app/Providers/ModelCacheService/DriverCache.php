<?php
namespace App\Providers\ModelCacheService;
require_once  __DIR__."/lib/mycurl.php";

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class DriverCache{
    private $prefix;
    private $consts;
    const DEBUG_KEEP_SEC = 3600*4;
    const QUERY_INTERVAL_SEC = 60;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
        $this->consts['AREA'] = [
            1=>'SC',
            2=>'NY',
            3=>'RH',
            4=>'MH',
            5=>'MH',
            6=>'DT',
            10=>'MI',
        ];
        foreach($this->consts['AREA'] as $k=>$v) {
            if (!isset($this->consts['AREACODE_MAP'][$v]))
                $this->consts['AREACODE_MAP'][$v] = $k;
        }
    }
    public function get_drivers_from_api() {
        $curTime = time();
        $timer = -microtime(true);
        $data = do_curl('https://www.chanmao.ca/index.php?r=MobAly10/DriverLoc', 'GET');
        $drivers = $data['drivers']??[];
        foreach($drivers as &$dr) {
            $dr['areaId'] = $this->consts['AREACODE_MAP'][$dr['area']] ?? -1;
        }
        $timer += microtime(true);
        Log::debug("got ".count($drivers)." drivers from remote API.". " takes:".$timer." secs");
        return $drivers;
    }
    public function reload() {
        $drivers = $this->get_drivers_from_api();
        Redis::setex($this->prefix."updatedAt", self::DEBUG_KEEP_SEC, time());
        foreach($drivers as $dr) {
            $key = $this->prefix."dr:".$dr['areaId'].":".$dr['driver_id'];
            Redis::setex($key, self::QUERY_INTERVAL_SEC, json_encode($dr));
            //we do not del keys we let keys expire
        }
        return $drivers;
    }
    public function get_drivers($area) {
        if (!isset($this->consts['AREA'][$area])) {
            Log::debug("unknown area code:$area");
            return [];
        }
        $updatedAt = Redis::get($this->prefix. "updatedAt");
        $updatedAt = is_null($updatedAt) ? 0 : (int)$updatedAt;
        $curTime = time();
        if ($curTime - $updatedAt > self::QUERY_INTERVAL_SEC) {
            $this->reload();
        }
        $keys = Redis::keys($this->prefix."dr:".$area.":*");
        if (empty($keys)) return [];
        $rets = Redis::mget($keys);
        $drivers = [];
        foreach($rets as $rows) if (!is_null($rows)) {
            $drivers[] = json_decode($rows, true);
        }
        //Log::debug("read ".count($drivers)." drivers for area $area:".json_encode(array_pluck($drivers,'driver_id')));
        return $drivers;
    }
}
