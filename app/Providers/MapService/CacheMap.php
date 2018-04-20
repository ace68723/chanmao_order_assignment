<?php
namespace App\Providers\MapService;

use Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class CacheMap{
    const PREFIX = "cmoa:map:";
    const KEEP_ALIVE_SEC = 3600*24;

    static public function set_dist_mat($dist_mat) {
        $key_prefix = self::PREFIX . "distMat:";
        $curTime = time();
        foreach ($dist_mat as $start_loc=>$rows) {
            $arr = [$key_prefix.$start_loc];
            foreach($rows as $end_loc=>$elem) {
                $arr[] = $end_loc;
                $arr[] = json_encode(['timestamp'=>$curTime, 'du'=>$elem[0], 'di'=>$elem[1]]);
            }
            Redis::hmset(...$arr);
        }
    }
    static public function get_dist_mat($origin_loc_arr, $end_loc_arr) {
        $dist_mat = [];
        if (empty($origin_loc_arr) || empty($end_loc_arr)) return $dist_mat;
        $curTime = time();
        $key_prefix = self::PREFIX . "distMat:";
        $n = 0;
        foreach ($origin_loc_arr as $start_loc) {
            $arr = [$key_prefix.$start_loc];
            foreach($end_loc_arr as $end_loc) {
                $arr[] = $end_loc;
            }
            $ret = Redis::hmget(...$arr);
            foreach ($end_loc_arr as $i=>$end_loc) {
                if (is_null($ret[$i])) continue;
                $cached = json_decode($ret[$i],true);
                if ($curTime - $cached['timestamp'] > self::KEEP_ALIVE_SEC) {
                    continue;
                }
                $dist_mat[$start_loc][$end_loc] = [$cached['du'],$cached['di']];
                $n++;
            }
        }
        Log::debug("read $n cached items:".json_encode($dist_mat));
        return $dist_mat;
    }
}
