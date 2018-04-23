<?php
namespace App\Providers\ModelCacheService;

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class LogCache{
    private $prefix;
    private $consts;
    const LOG_KEEP_SECS = 24*3600*3;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
    }
    public function log($key, $data) {
        $curTime = time();
        $fullkey = $this->prefix.$key.":lastLogTime";
        Redis::setex($fullkey, self::LOG_KEEP_SECS, $curTime);
        $fullkey = $this->prefix.$key.":".$curTime;
        Redis::setex($fullkey, self::LOG_KEEP_SECS, json_encode($data));
    }
    public function get_last($key) {
        $lastLogTime = Redis::get($this->prefix.$key.":lastLogTime");
        if (empty($lastLogTime)) {
            return null;
        }
        $fullkey = $this->prefix.$key.":".$lastLogTime;
        $dataStr = Redis::get($fullkey);
        return is_null($dataStr)? null: json_decode($dataStr,true);
    }
}
