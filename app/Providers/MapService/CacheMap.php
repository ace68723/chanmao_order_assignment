<?php
namespace App\Providers\MapService;

use Log;
use S2;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class CacheMap{
    const PREFIX = "cmoa:map:";
    const KEEP_ALIVE_SEC = 3600*24;
    const DEG_PRECISION = 5;
    const REDIS_BATCH_SIZE = 50; //TODO change to a larger one, this is for test
    const S2CELL_LEVEL = 19;//lvl 19 cell is about 20m*20m
    const ACCU_MIN_INTER = 3600;
    const ACCU_MAX_INTER = 3600*24*7;

    static public function LatLngToPairId($start_lat, $start_lng, $end_lat, $end_lng) {
        $startCellId = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees($start_lat, $start_lng))
            ->parent(self::S2CELL_LEVEL)->id();
        $endCellId = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees($end_lat, $end_lng))
            ->parent(self::S2CELL_LEVEL)->id();
        return dechex($startCellId)."-".dechex($endCellId);
    }
    static public function ToPairId($start_loc, $end_loc) {
        $paras = [];
        foreach([$start_loc,$end_loc] as $loc_str) {
            $arr = explode(',',$loc_str);
            $paras[] = floatval($arr[0]);
            $paras[] = floatval($arr[1]);
        }
        return self::LatLngToPairId(...$paras);
    }
    static public function get_mat($origin_loc_arr, $end_loc_arr) {
        $key_prefix = self::PREFIX . "pair:";
        $dist_mat = [];
        if (empty($origin_loc_arr) || empty($end_loc_arr)) return $dist_mat;
        $pairkeys = []; $idx = []; $missing = [];
        foreach($origin_loc_arr as $start_loc) {
            foreach($end_loc_arr as $end_loc) {
                if ($start_loc == $end_loc) continue;
                $pairkeys[] = $key_prefix.self::ToPaidId($start_loc,$end_loc);
                $idx[] = [$start_loc, $end_loc];
                if (count($pairkeys) >= self::REDIS_BATCH_SIZE) {
                    self::mget2d($pairkeys,$idx,$dist_mat,$missing);
                    $pairkeys = []; $idx = [];
                }
            }
        }
        if (count($pairkeys)) {
            self::mget2d($pairkeys,$idx,$dist_mat,$missing);
        }
        return [$dist_mat, $missing];
    }
    static public function update_mat($dudi_mat, $caseid = null) {
        $key_prefix = self::PREFIX . "pair:";
        $curTime = time();
        $pairkeys = []; $idx = []; $missing = []; $dudis = [];
        foreach ($dudi_mat as $start_loc=>$rows) {
            foreach($rows as $end_loc=>$elem) {
                if ($start_loc == $end_loc) continue;
                $pairkeys[] = $key_prefix.self::ToPairId($start_loc,$end_loc);
                $idx[] = [$start_loc, $end_loc];
                $dudis[] = $elem;
                if (count($pairkeys) >= self::REDIS_BATCH_SIZE) {
                    $self::update2d($keys, $idx, $dudis, $caseid);
                    $pairkeys = []; $idx = []; $dudis = [];
                }
            }
        }
        if (count($pairkeys)) {
            $self::update2d($keys, $idx, $dudis, $caseid);
            $pairkeys = []; $idx = []; $dudis = [];
        }
    }
    static public function test() {
        $start_loc = "43,-79";
        $end_loc = "43,-80";
        return self::ToPairId($start_loc,$end_loc);
    }
    static private function accumlate(&$tuple, $value, $curTime) {
        $ratio = 0.9;
        if ($curTime - $tuple[1] < self::ACCU_MIN_INTER) return false;
        if ($curTime - $tuple[1] > self::ACCU_MAX_INTER) $ratio = 0.5;
        $tuple[0] *= $ratio;
        $tuple[0] += (1-$ratio)*$value;
        $tuple[1] = $curTime;
        return true;
    }
    static private function update2d($keys, $idx, $dudis, $caseId) {
        self::mget2d($keys, $idx, $oldata, $missing);
        $newdata = [];
        $curTime = time();
        foreach ($dudis as $i=>$elem) {
            $start_loc = $idx[$i][0];
            $end_loc = $idx[$i][1];
            if (isset($olddata[$start_loc][$end_loc])) {
                $newitem = $olddata[$start_loc][$end_loc];
                $isNew = self::accumlate($newitem['_s'],$elem[0],$curTime);
                $shouldUpdate = $isnew;
                if ($elem[1] != $newitem['_m']) {
                    $newitem['_m'] = $elem[1];
                    $shouldUpdate = true;
                }
                if (!empty($caseId)) {
                    if (empty($newitem[$caseId])) {
                        $newitem[$caseId] = [$elem[0],$curTime];
                        $shouldUpdate = true;
                    }
                    else {
                        $isNew = self::accumlate($newitem[$caseId],$elem[0],$curTime);
                        $shouldUpdate = $shouldUpdate || $isNew;
                    }
                }
                if (!$shouldUpdate) continue;
            }
            else {
                $newitem = ['_m'=>$elem[1],'_s'=>[$elem[0],$curTime]];
                if (!empty($caseId)) $newitem[$caseId] = [$elem[0],$curTime];
            }
            $newdata[] = $keys[$i];
            $newdata[] = json_encode($newitem);
        }
        if (!empty($newdata)) {
            Redis::mset($newdata);
        }
    }
    static private function mget2d($keys, $idx, &$data, &$missing) {
        $results = Redis::mget(...$keys);
        foreach($results as $i=>$elem) {
            if (!empty($elem)) {
                $data[$idx[$i][0]][$idx[$i][1]] = json_decode($elem, true);
            }
            else {
                $missing[$idx[$i][0]][$idx[$i][1]] = 1;
            }
        }
    }
    static private function near_pattern($center, $level) {
        return substr($center,0,strlen($center)-$level).str_repeat('?',$level);
    }
    static public function query_near($start_loc, $end_loc) {
        for($level = 1; $level <= 3; $level++) {
            $x = self::near_pattern($start_loc, $level);
            $y = self::near_pattern($end_loc, $level);
            $keys = Redis::keys(self::PREFIX."pair:".$x.":".$y);
            if (!empty($keys)) {
                $values = Redis::mget($keys);
                $data = [];
                foreach($values as $i=>$value) if (!empty($value)){
                    $locs = array_slice($keys[$i]->explode(':'),-2);
                    $data[$locs[0]][$locs[1]] = json_decode($value, true);
                }
                if (!empty($data)) return $data;
            }
        }
        return [];
    }
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
        //Log::debug("read $n cached items:".json_encode($dist_mat));
        Log::debug(__FUNCTION__.":read $n cached items:");
        return $dist_mat;
    }
    /*
    static public function calc_mean_ratio($to_ratio) {
        $ratio = 0;
        $key_prefix = self::PREFIX . "distMat:";
        $fullkeys = Redis::keys($key_prefix."*");
        $n = 0;
        foreach ($fullkeys as $fullkey) {
            $start_loc = substr($fullkey, strrpos($fullkey, ":")+1);
            $ret = Redis::hgetall($fullkey);
            foreach($ret as $end_loc=>$jsondata) {
                $cached = json_decode($jsondata,true);
                $ratio += $to_ratio($start_loc, $end_loc, [$cached['du'],$cached['di']]);
                $n++;
            }
        }
        return ($n>0)? $ratio/$n : null;
    }
     */
}
