<?php
namespace App\Providers\MapService;

use Log;
use S2;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class CacheMap{
    const PREFIX = "cmoa:map:";
    const KEEP_ALIVE_SEC = 3600*24;
    const DEG_PRECISION_FORMAT= "%.5f,%.5f";
    const REDIS_BATCH_SIZE = 50; //TODO change to a larger one, this is for test
    const S2CELL_LEVEL = 19;//must >= 14; lvl 19 cell is about 20m*20m
    const ACCU_MIN_INTER = 3600;
    const ACCU_MAX_INTER = 3600*24*7;

    static public function LatLngToPairId($start_lat, $start_lng, $end_lat, $end_lng) {
        $startCellId = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees($start_lat, $start_lng))
            ->parent(self::S2CELL_LEVEL)->id();
        $endCellId = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees($end_lat, $end_lng))
            ->parent(self::S2CELL_LEVEL)->id();
        return self::idsToToken([$startCellId, $endCellId]);
    }
    static public function ExtLocToPairId($start_loc, $end_loc) {
        $paras = [];
        foreach([$start_loc,$end_loc] as $loc_str) {
            $arr = explode(',',$loc_str);
            $paras[] = floatval($arr[0]);
            $paras[] = floatval($arr[1]);
        }
        return self::LatLngToPairId(...$paras);
    }
    static public function PairIdToExtLoc($pairId) {
        $ids = self::tokenToIds($pairId);
        $ret = [];
        foreach($ids as $cellId) {
            $latlng = (new S2\S2CellId($cellId))->toLatLng();
            $ret[] = sprintf(self::DEG_PRECISION_FORMAT, $latlng->latDegrees(),$latlng->latDegrees());
        }
        return $ret;
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
        $ret = [];
        $pairs = [];
        $pairs[] = ["43,-79", "43,-80"];
        $pairs[] = ["43.0001,-79", "43,-80.0001"];
        $pairs[] = ["43.001,-79", "43,-80.001"];
        $pairs[] = ["43.01,-79", "43,-80.01"];
        foreach($pairs as $pair) {
            $ret[] = self::ExtLocToPairId(...$pair);
        }
        $ret[] = self::PairIdToExtLoc($ret[0]);
        return $ret;
    }
    static private function accumlate(&$tuple, $value, $curTime) {
        $ratio = 0.9;
        if ($curTime - $tuple[1] < self::ACCU_MIN_INTER) return false;
        if ($curTime - $tuple[1] > self::ACCU_MAX_INTER) $ratio = 0.5;
        $tuple[0] *= $ratio;
        $tuple[0] += (1-$ratio)*$value;
        $tuple[0] = (int)($tuple[0]);
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
    static public function query_near($start_loc, $end_loc) {
        $key_prefix = self::PREFIX . "pair:";
        $tPairId = self::ExtLocToPairId($start_loc, $end_loc);
        for($level = 1; $level <= 8; $level++) {
            $pat = substr($tPairId,0,strlen($tPairId)-$level).str_repeat('?',$level);
            $keys = Redis::keys($key_prefix.$pat); // this is limited by the pattern
            if (!empty($keys)) {
                $values = Redis::mget($keys);
                $data = [];
                foreach($values as $i=>$value) if (!empty($value)){
                    $pairId = substr($keys[$i], strrpos(explode(':'),$keys[$i]));
                    $locs = self::PairIdToExtLoc($pairId);
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
    static private function idsToToken($ids) {
        $strs = [];
        for($i=0; $i<2; $i++) {
            $strs[$i] = decbin($ids[$i]);
            $l = strlen($strs[$i]);
            if ($l < 64) $strs[$i] = str_repeat('0',64-$l).$strs[$i];
        }
        $merged = "";
        for($i=0; $i<64; $i++) $merged .= $strs[0][$i].$strs[1][$i];
        $mergedhex = "";
        for($i=0; $i<2; $i++) {
            $h = bindec(substr($merged, 32*$i, 32));
            $strh = dechex($h); if (strlen($strh)<8) $strh = str_repeat('0',8-strlen($strh)).$strh;
            $mergedhex .= $strh;
        }
        $ret = $mergedhex. substr($merged, 64, 4*(self::S2CELL_LEVEL-14));
        return $ret;
    }
    static private function tokenToIds($tok) {
        $merged = "";
        for($i=0; $i<2; $i++) {
            $hl = substr($tok,8*$i,8);
            $hl = decbin(hexdec($hl));
            if (strlen($hl)<32) $hl=str_repeat('0',32-strlen($hl)).$hl;
            $merged .= $hl;
        }
        $tail = substr($tok, 16);
        if (strlen($tail)<64) $tail .= str_repeat('0',64-strlen($tail));
        $merged .= $tail; 
        $strs = ['',''];
        for($i=0; $i<strlen($merged)/2; $i++) {
            $strs[0] .= $merged[$i*2];
            $strs[1] .= $merged[$i*2+1];
        }
        $ids = [];
        for($i=0; $i<2; $i++) {
            if ($strs[$i][0] == '0') {
                $ids[$i] = bindec($strs[$i]);
            }
            else {
                $ids[$i] = bindec(substr($strs[$i],1))+PHP_INT_MIN;
            }
        }
        return $ids;
    }
}
