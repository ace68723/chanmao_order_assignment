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
    const REDIS_BATCH_SIZE = 1000;
    const S2CELL_LEVEL = 20;//must >= 14 <30; lvl 20 cell is about 10m*10m
    const NEAR_LEVEL_MAX = 14;
    const NEAR_LEVEL_MIN = 12;
    const ACCU_MIN_INTER = 3600;
    const ACCU_MAX_INTER = 3600*24*7;
    const HEURISTIC_RATIO = 118.75;
    const HEURISTIC_FACTOR = ['lv2'=>1.2, 'lv4'=>1.5];

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
    static public function extractCase($caseId, &$dist_mat) {
        foreach($dist_mat as $start_loc=>$row) {
            foreach($row as $end_loc=>$elem) {
                $dist_mat[$start_loc][$end_loc] = $elem[$caseId][0] ??
                    (int)($elem['base'][0]*self::HEURISTIC_FACTOR[$caseId]);
            }
        }
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
    static public function update_mat($dudi_mat, $caseid = 'base') {
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
        //$pairs[] = ["43.001,-79", "43,-80.001"];
        $pairs[] = ["43.01,-79", "43,-80.01"];
        //foreach($pairs as $pair) {
        $pair = $pairs[0];
            self::update_mat([$pair[0]=>[$pair[1]=>[40,50]]],'lv2');
            list($mat, $missing) = self::get_mat([$pair[0]],[$pair[1]]);
            self::extractCase('lv4',$mat);
            $ret[] = $mat;
        //}
        foreach($pairs as $pair) {
            $cells = self::ExtLocToCells(...$pair);
            $tok = self::cellsToToken($cells);
            $rcells = self::tokenToCells($tok);
            $rLocs = self::CellsToExtLoc($rcells);
            $ret[] = [$tok, $rLocs];
        }
        return $ret;
    }
    static private function accumlate(&$tuple, $dudi, $curTime) {
        $ratio = 0.9;
        if ($tuple[2]>0 && $curTime - $tuple[2] < self::ACCU_MIN_INTER) return false;
        if ($tuple[2]<=0) $ratio = 0;
        if ($curTime - $tuple[2] > self::ACCU_MAX_INTER) $ratio = 0.5;
        for($i=0; $i<2; $i++) {
            if ($tuple[$i] != $dudi[$i]) {
                $tuple[$i] *= $ratio;
                $tuple[$i] += (1-$ratio)*$dudi[$i];
                $tuple[$i] = (int)($tuple[$i]);
            }
        }
        $tuple[2] = $curTime;
        return true;
    }
    static private function update2d($keys, $idx, $dudis, $caseId) {
        Log::debug(__FUNCTION__.": updating cache map ".count($keys)." keys. ".$caseId);
        if (count($keys)) {
            Log::debug(__FUNCTION__.": sample:".$keys[0].":".json_encode($dudis[0]));
        }
        self::mget2d($keys, $idx, $olddata, $missing);
        $newdata = [];
        $curTime = time();
        foreach ($dudis as $i=>$elem) {
            $start_loc = $idx[$i][0];
            $end_loc = $idx[$i][1];
            if (isset($olddata[$start_loc][$end_loc])) {
                $newitem = $olddata[$start_loc][$end_loc];
                if (empty($newitem[$caseId])) {
                    $newitem[$caseId] = [$elem[0], $elem[1], $curTime];
                    $shouldUpdate = true;
                }
                else {
                    $shouldUpdate = self::accumlate($newitem[$caseId],$elem,$curTime);
                }
                if (!$shouldUpdate) continue;
            }
            else {
                $newitem[$caseId] = [$elem[0],$elem[1],$curTime];
                if ($caseId != 'base') {
                    $newitem['base'] = [
                        (int)($elem[0]*1.0/self::HEURISTIC_FACTOR[$caseId]),
                        $elem[1],-1
                    ];
                }
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
    static public function near_patterns_agg($cells, $level) {
        //assert($level < self::S2CELL_LEVEL);
        $pats = [];
        $neighbors = [[],[]];
        for($i=0; $i<2; $i++) {
            $cells[$i]->getVertexNeighbors($level, $neighbors[$i]);
        }
        foreach($neighbors[0] as $start) {
            foreach($neighbors[1] as $end) {
                $pat = self::cellsToToken([$start,$end], $level);
                $pats[substr($pat, 0, -1)] = 1;
            }
        }
        return array_keys($pats);
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
    static public function query_near_batch($missed_mat) {
        $key_prefix = self::PREFIX . "pair:";
        $agg_pats = [];
        foreach($missed_mat as $start_loc=>$row) {
            foreach($row as $end_loc=>$elem) {
                $cells = self::ExtLocToCells($start_loc, $end_loc);
                $level = self::NEAR_LEVEL_MAX;
                $pats = self::near_patterns_agg($cells, $level);
                foreach($pats as $pat) {
                    $agg_pats[$pat] = 1;
                }
            }
        }
        $data = [];
        foreach($agg_pats as $pat=>$elem) {
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
        return $data;
    }
    static private function dist_km($cell_a, $cell_b) {
        return $cell_a->toLatLng()->getEarthDistance($cell_b->toLatLng())/1000.0;
    }
    static public function near_filter_conv($keys, $center_cells, $nFirst) {
        $dists = []; $cell_dict=[];
        foreach($keys as $key) {
            $token = substr($key, strrpos($key,':')+1);
            $cells = self::tokenToCells($token);
            $sum_dist = 0;
            for($i=0;$i<2;$i++) {
                $sum_dist += self::dist_km($center_cells[$i], $cells[$i]);
            }
            $dists[$key] = $sum_dist;
            $cell_dict[$key] = $cells;
        }
        if ( count($dists) > $nFirst ) {
            asort($dists);
            $dists = array_slice($dists, 0, $nFirst);
        }
        return [$dists, $cell_dict]; //$dists may be shorter than $cell_dict
    }
    static public function weighted_approx($cell_pair, $ref_data, $caseId, $max_range_km=2.4)
    {
        $default_ratio = self::HEURISTIC_RATIO*(self::HEURISTIC_FACTOR[$caseId] ?? 1);
        $total_weight = 0;
        $total_ratio = 0;
        $ll = self::dist_km($cell_pair[0], $cell_pair[1]);
        $found = false;
        $count = ['nBase'=>0,'nFar'=>0];
        foreach ($ref_data as $ref_item) {
                $count['nBase'] += 1;
                $l2_dist = self::dist_km($ref_item['cells'][0],$ref_item['cells'][1]);
                $value = $ref_item['elem'][$caseId][0] ?? $ref_item['elem']['base'][0]*self::HEURISTIC_FACTOR[$caseId];
                $ratio = $value/$l2_dist;
                $expw = $ref_item['sum_diff_dist'];
                if (-$expw > $max_range_km) {
                    $count['nFar'] += 1;
                    continue;
                }
                $weight = exp($expw/$max_range_km);//100 is heuristic mean for 200*200 grid
                $found = true;
                //Log::debug(__FUNCTION__.":".self::cellsToToken($ref_item['cells'])." weight:".$weight." ratio:".$ratio);
                $total_weight += $weight;
                $total_ratio += $weight * $ratio;
        }
        //Log::debug(__FUNCTION__.":".self::cellsToToken($cell_pair)." count:".json_encode($count));
        return (int)round($ll * ($found ? $total_ratio/$total_weight : $default_ratio));
    }
    static public function approx_mat($missed_pairs, &$dist_mat, $caseId) {
        foreach ($missed_pairs as $start_loc=>$missed_rows) {
            foreach ($missed_rows as $end_loc=>$missed_elem) {
                $cell_pair = self::ExtLocToCells($start_loc, $end_loc);
                $ref_data = self::query_near($cell_pair);
                $dist_mat[$start_loc][$end_loc] = self::weighted_approx($cell_pair, $ref_data, $caseId);
            }
        }
    }
    static private function query_near($cell_pair, $nFirst=5) {
        $key_prefix = self::PREFIX . "pair:";
        for($level=self::NEAR_LEVEL_MAX; $level>=self::NEAR_LEVEL_MIN; $level--) {
            $pats = self::near_patterns_agg($cell_pair, $level);
            $data = []; $keys = [];
            foreach($pats as $pat) {
                $newkeys = Redis::keys($key_prefix.$pat.'*'); // this is limited by the pattern
                $keys = array_merge($keys,$newkeys);
            }
            if (empty($keys)) continue;
            list($dists, $cell_dict) = self::near_filter_conv($keys, $cell_pair, $nFirst);
            $keys = array_keys($dists);
            $values = Redis::mget(...$keys);
            foreach($values as $i=>$value) if (!empty($value)){
                $elem = json_decode($value, true);
                $data[] = [
                    'elem'=>$elem,
                    'sum_diff_dist'=> $dists[$keys[$i]],
                    'cells'=> $cell_dict[$keys[$i]],
                ];
            }
            if (!empty($data)) {
                //Log::debug(__FUNCTION__.":find ".count($data)." recs at level ".$level);
                return $data;
            }
        }
        return [];
    }
    static public function scan($pattern='*') {
        $key_pat = self::PREFIX."pair:".$pattern;
        $prefixlen = strlen(self::PREFIX."pair:");
        $cursor = 0;
        do {
            list($cursor, $keys) = Redis::scan($cursor, 'match', $key_pat, 'count', 1000);
            foreach ($keys as $key) {
                yield substr($key, $prefixlen);
            }
        } while ($cursor);
    }
    static public function scan_item($pattern='*') {
        $key_pat = self::PREFIX."pair:".$pattern;
        $prefixlen = strlen(self::PREFIX."pair:");
        $cursor = 0;
        do {
            list($cursor, $keys) = Redis::scan($cursor, 'match', $key_pat, 'count', 200);
            if (empty($keys)) break;
            $items = Redis::mget(...$keys);
            foreach ($items as $i=>$item) {
                yield [$keys[$i], json_decode($item, true)];
            }
        } while ($cursor);
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
