<?php
namespace App\Providers\ModelCacheService;

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class ScheduleCache{
    private $prefix;
    private $key_table;
    private $key_updatedAt;
    const KEEP_SECS = 24*3600*7;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
        $this->key_table = $this->prefix."scheduleTable";
        $this->key_updatedAt = $this->prefix."updatedAt";
    }
    public function set_schedules($schedules, $updatedAt) {
        Redis::setex($this->key_updatedAt, self::KEEP_SECS, $updatedAt);
        foreach($schedules as $driver_id=>$sche) {
            $sche['updatedAt'] = $updatedAt;
            if ($sche['driver_id'] != $driver_id) {
                throw new CmException('SYSTEM_ERROR', "saving mismatch driver_id key and value in schedule");
            }
            Redis::setex($this->key_table.":".$driver_id, self::KEEP_SECS, json_encode($sche));
        }
    }
    public function get_schedules($driver_id=null) {
        if (is_null($driver_id)) {
            $keys = Redis::keys($this->key_table.":*");
            $dataArr = Redis::mget(...$keys);
            $data = [];
            foreach($dataArr as $dataStr) {
                if (is_null($dataStr)) continue;
                $sche = json_decode($dataStr,true);
                $data[$sche['driver_id']] = $sche;
            }
            return $data;
        }
        else {
            $dataStr = Redis::get($this->key_table.":".$driver_id);
            if (is_null($dataStr)) return null;
            $sche = json_decode($dataStr,true);
            return $sche;
        }
    }
}
