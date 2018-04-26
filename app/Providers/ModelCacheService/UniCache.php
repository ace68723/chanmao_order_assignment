<?php
namespace App\Providers\ModelCacheService;

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class UniCache{
    private $prefix;
    const LOG_KEEP_SECS = 24*3600*7;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
    }
    public function set($key, $data) {
        $fullkey = $this->prefix.$key;
        Redis::setex($fullkey, self::LOG_KEEP_SECS, json_encode($data));
    }
    public function get($key) {
        $fullkey = $this->prefix.$key;
        $dataStr = Redis::get($fullkey);
        return is_null($dataStr)? null: json_decode($dataStr,true);
    }
}
