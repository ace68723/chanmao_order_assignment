<?php
namespace App\Providers\ModelCacheService;

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class LogCache{
    private $prefix;
    const LOG_KEEP_SECS = 24*3600*7;

    public function __construct($root_prefix="") {
        $this->prefix = $root_prefix.class_basename(__CLASS__).":";
    }
    public function log($key, $data) {
        $curTime = time();
        Log::debug('add log cache '.$key.':'.$curTime);
        $fullkey = $this->prefix."zset:".$key;
        Redis::zadd($fullkey, $curTime, json_encode($data));
        #Redis::zremrangebyscore($fullkey, '-inf', $curTime-self::LOG_KEEP_SECS);
    }
    public function get_at($key, $timestamp) {
        $fullkey = $this->prefix."zset:".$key;
        $res = Redis::zrangebyscore($fullkey, $timestamp, $timestamp, ['limit'=>[0,1]]);
        if (empty($res)) return [-1, null];
        return [$timestamp, json_decode($res[0],true)];
    }
    public function get_next($key, $timestamp) {
        $fullkey = $this->prefix."zset:".$key;
        $res = Redis::zrangebyscore($fullkey, '('.$timestamp, '+inf', ['withscores'=>true, 'limit'=>[0,1]]);
        if (empty($res)) return [-1, null];
        $timestamp = array_values($res)[0];
        return [$timestamp, json_decode(array_keys($res)[0],true)];
    }
    public function get_prev($key, $timestamp) {
        $fullkey = $this->prefix."zset:".$key;
        $res = Redis::zrevrangebyscore($fullkey, '('.$timestamp, '-inf', ['withscores'=>true, 'limit'=>[0,1]]);
        if (empty($res)) return [-1, null];
        $timestamp = array_values($res)[0];
        return [$timestamp, json_decode(array_keys($res)[0],true)];
    }
    private function rewrite_all($key) {
        $keys = Redis::keys($this->prefix.$key.':*');
        $fullkey = $this->prefix."zset:".$key;
        foreach($keys as $oldkey) {
            $ss = explode(":", $oldkey);
            $last = $ss[count($ss)-1];
            if (!is_numeric($last)) continue;
            $ts = (int)($last);
            $dataStr = Redis::get($oldkey);
            Redis::zadd($fullkey, $ts, $dataStr);
        }
    }
}
