<?php
namespace App\Providers\MapService;

use Log;
use S2;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class CacheMap{
    const PREFIX = "cmoa:map:";
    const KEEP_ALIVE_SEC = 3600*24;
    const DEG_PRECISION_FORMAT= "%.4f,%.4f";
    const REDIS_BATCH_SIZE = 50; //TODO change to a larger one, this is for test
    const S2CELL_LEVEL = 20;//must >= 14 <30; lvl 20 cell is about 10m*10m
    const ACCU_MIN_INTER = 3600;
    const ACCU_MAX_INTER = 3600*24*7;

    static public function ExtLocToCells($start_loc, $end_loc) {
        $cells = [];
        foreach([$start_loc,$end_loc] as $loc_str) {
            $arr = explode(',',$loc_str);
            $cells[] = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees(floatval($arr[0]), floatval($arr[1])))
                ->parent(self::S2CELL_LEVEL);
        }
        return $cells;
    }
    static public function CellsToExtLoc($cells) {
        $ret = [];
        foreach($cells as $cell) {
            $latlng = $cell->toLatLng();
            $ret[] = sprintf(self::DEG_PRECISION_FORMAT, $latlng->latDegrees(),$latlng->lngDegrees());
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
                $cells = self::ExtLocToCells($start_loc, $end_loc);
                $pairkeys[] = $key_prefix.self::cellsToToken($cells);
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
                $cells = self::ExtLocToCells($start_loc, $end_loc);
                $pairkeys[] = $key_prefix.self::cellsToToken($cells);
                $idx[] = [$start_loc, $end_loc];
                $dudis[] = $elem;
                if (count($pairkeys) >= self::REDIS_BATCH_SIZE) {
                    self::update2d($pairkeys, $idx, $dudis, $caseid);
                    $pairkeys = []; $idx = []; $dudis = [];
                }
            }
        }
        if (count($pairkeys)) {
            self::update2d($pairkeys, $idx, $dudis, $caseid);
            $pairkeys = []; $idx = []; $dudis = [];
        }
    }
    static public function test() {
        $ret = [];
        $pairs = [];
        $pairs[] = ["43,-79", "43,-80"];
        foreach($pairs as $pair) {
            self::update_mat([$pair[0]=>[$pair[1]=>[10,20]]]);
            $ret[] = self::get_mat([$pair[0]],[$pair[1]]);
        }
        //$pairs[] = ["43.0001,-79", "43,-80.0001"];
        //$pairs[] = ["43.001,-79", "43,-80.001"];
        $pairs[] = ["43.01,-79", "43,-80.01"];
        foreach($pairs as $pair) {
            self::update_mat([$pair[0]=>[$pair[1]=>[40,50]]]);
            $ret[] = self::get_mat([$pair[0]],[$pair[1]]);
            $ret[] = self::query_near($pair[0],$pair[1]);
        }
        foreach($pairs as $pair) {
            $cells = self::ExtLocToCells(...$pair);
            $tok = self::cellsToToken($cells);
            $rcells = self::tokenToCells($tok);
            $rLocs = self::CellsToExtLoc($rcells);
            $pats = self::near_patterns($cells, 12);
            $ret[] = [$tok, $rLocs, $pats];
        }
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
        self::mget2d($keys, $idx, $olddata, $missing);
        $newdata = [];
        $curTime = time();
        foreach ($dudis as $i=>$elem) {
            $start_loc = $idx[$i][0];
            $end_loc = $idx[$i][1];
            if (isset($olddata[$start_loc][$end_loc])) {
                $newitem = $olddata[$start_loc][$end_loc];
                $isNew = self::accumlate($newitem['_s'],$elem[0],$curTime);
                $shouldUpdate = $isNew;
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
            Redis::mset(...$newdata);
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
    static public function near_patterns($cells, $level) {
        //assert($level < self::S2CELL_LEVEL);
        $pats = [];
        $neighbors = [[],[]];
        for($i=0; $i<2; $i++) {
            $cells[$i]->getVertexNeighbors($level, $neighbors[$i]);
        }
        foreach($neighbors[0] as $start) {
            foreach($neighbors[1] as $end) {
                $pats[] = self::cellsToToken([$start,$end], $level);
            }
        }
        return $pats;
    }
    static public function query_near($start_loc, $end_loc) {
        $key_prefix = self::PREFIX . "pair:";
        $cells = self::ExtLocToCells($start_loc, $end_loc);
        $pats = self::near_patterns($cells, 12);
        $data = [];
        foreach($pats as $pat) {
            $keys = Redis::keys($key_prefix.$pat.'*'); // this is limited by the pattern
            if (!empty($keys)) {
                $values = Redis::mget(...$keys);
                foreach($values as $i=>$value) if (!empty($value)){
                    $token = substr($keys[$i], strrpos($keys[$i],':')+1);
                    $cells = self::tokenToCells($token);
                    $locs = self::CellsToExtLoc($cells);
                    $data[$locs[0]][$locs[1]] = json_decode($value, true);
                }
            }
        }
        Log::debug(__FUNCTION__.":find ".count($data)." recs.");
        return $data;
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
    static public function scan($pat='*') {
        $key_pat = self::PREFIX . "pair:".$pat;
        $res = Redis::scan(0, $key_pat, 20);
        return $res;
    }
    static private function cellsToToken($cells, $level=self::S2CELL_LEVEL) {
        $strs = [];
        for($i=0; $i<2; $i++) {
            $strs[$i] = decbin($cells[$i]->id());
            $l = strlen($strs[$i]);
            if ($l < 64) $strs[$i] = str_repeat('0',64-$l).$strs[$i];
            $strs[$i] = '0'.substr($strs[$i],0,63);
        }
        //Log::debug(__FUNCTION__.":".dechex($cells[0]->id()).":".dechex($cells[1]->id()).":".$level);
        $merged = "";
        for($i=0; $i<64; $i++) $merged .= $strs[0][$i].$strs[1][$i];
        $mergedhex = "";
        for($i=0; $i<4; $i++) {
            $h = bindec(substr($merged, 32*$i, 32));
            $strh = dechex($h); if (strlen($strh)<8) $strh = str_repeat('0',8-strlen($strh)).$strh;
            $mergedhex .= $strh;
        }
        //assert($mergedhex[16+$level-14] == 'c');
        $ret = substr($mergedhex, 0, 16+$level-14);
        //Log::debug(__FUNCTION__.":ret:".$ret);
        return $ret;
    }
    static private function tokenToCells($tok) {
        //Log::debug(__FUNCTION__.":tok:".$tok);
        $tok .= 'c';
        $tok .= str_repeat('0', 32-strlen($tok));
        $merged = "";
        for($i=0; $i<4; $i++) {
            $hl = substr($tok,8*$i,8);
            $hl = decbin(hexdec($hl));
            if (strlen($hl)<32) $hl=str_repeat('0',32-strlen($hl)).$hl;
            $merged .= $hl;
        }
        $strs = ['',''];
        for($i=0; $i<strlen($merged)/2; $i++) {
            $strs[0] .= $merged[$i*2];
            $strs[1] .= $merged[$i*2+1];
        }
        $cells = [];
        for($i=0; $i<2; $i++) {
            $strs[$i] = substr($strs[$i],1).'0';
            if ($strs[$i][0] == '0') {
                $id = bindec($strs[$i]);
            }
            else {
                $id = bindec(substr($strs[$i],1))+PHP_INT_MIN;
            }
            $cells[] = new S2\S2CellId($id);
        }
        //Log::debug(__FUNCTION__.":".dechex($cells[0]->id()).":".dechex($cells[1]->id()).":".$level);
        return $cells;
    }
}
