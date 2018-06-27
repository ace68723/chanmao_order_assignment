<?php
namespace App\Providers\MapService;

use Log;
use S2;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class CacheMap{
    const PREFIX = "cmoa:map:";
    const KEEP_ALIVE_SEC = 3600*24;
    const REDIS_BATCH_SIZE = 1000;
    const S2CELL_LEVEL = 20;//must >= 14 <30; lvl 20 cell is about 10m*10m
    const NEAR_LEVEL_MAX = 14;
    const NEAR_LEVEL_MIN = 12;
    const ACCU_MIN_INTER = 3600;
    const ACCU_MAX_INTER = 3600*24*7;
    const HEURISTIC_RATIO = 118.75;
    const HEURISTIC_FACTOR = ['lv2'=>1.5, 'lv4'=>1.8];

    static public function TokenToExtLoc($tok, $fmtStr='%.6f,%.6f') {
        return self::CellsToExtLoc(self::tokenToCells($tok), $fmtStr);
    }
    static public function ExtLocToCells($start_loc, $end_loc) {
        $cells = [];
        foreach([$start_loc,$end_loc] as $loc_str) {
            $arr = explode(',',$loc_str);
            $cells[] = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees(floatval($arr[0]), floatval($arr[1])))
                ->parent(self::S2CELL_LEVEL);
        }
        return $cells;
    }
    static public function CellsToExtLoc($cells, $fmtStr) {
        $ret = [];
        foreach($cells as $cell) {
            $latlng = $cell->toLatLng();
            $ret[] = sprintf($fmtStr, $latlng->latDegrees(),$latlng->lngDegrees());
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
    static public function get_mat($target_mat) {
        $key_prefix = self::PREFIX . "pair:";
        $nQuery = 0;
        $pairkeys = []; $idx = []; $missing = []; $dist_mat = [];
        foreach($target_mat as $start_loc=>$row) {
            foreach($row as $end_loc=>$elem) {
                if ($elem < 0) continue;
                $nQuery += 1;
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
        Log::debug(__FUNCTION__.':query '.$nQuery.' elems');
        return [$dist_mat, $missing];
    }
    static public function update_mat($dudi_mat, $caseid = 'base') {
        $key_prefix = self::PREFIX . "pair:";
        $curTime = time();
        $pairkeys = []; $idx = []; $dudis = [];
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
        $samples = self::check_dirty();
        self::remove_dirty($samples);
        return $samples;
        $ret = [];
        $pairs = [];
        $pairs[] = ["43,-79", "43,-80"];
        //$pairs[] = ["43.001,-79", "43,-80.001"];
        $pairs[] = ["43.01,-79", "43,-80.01"];
        //foreach($pairs as $pair) {
        $pair = $pairs[0];
        self::update_mat([$pair[0]=>[$pair[1]=>[40,50]]],'lv2');
        $target_mat = [$pair[0]=>[$pair[1]=>0]];
        list($mat, $missing) = self::get_mat($target_mat);
        self::extractCase('lv4',$mat);
        $ret[] = $mat;
        //}
        foreach($pairs as $pair) {
            $cells = self::ExtLocToCells(...$pair);
            $tok = self::cellsToToken($cells);
            $rcells = self::tokenToCells($tok);
            $ret[] = [$tok];
        }
        return $ret;
    }
    // $tuple = [avg_duration, avg_distance, lastUpdateTime, duration_range, dist_range]
    static private function accumlate(&$tuple, $dudi, $curTime) {
        if ($tuple[2]>0 && $curTime - $tuple[2] < self::ACCU_MIN_INTER) return false; //prevent frequent update
        if (count($tuple)<=3 || $tuple[2]<=0) {
            $tuple = [$dudi[0], $dudi[1], $curTime, [$dudi[0],$dudi[0]], [$dudi[1],$dudi[1]]];
            return true;
        }
        $ratio = 0.5;
        for($i=0; $i<2; $i++) {
            if ($tuple[$i] != $dudi[$i]) {
                $tuple[$i] *= $ratio;
                $tuple[$i] += (1-$ratio)*$dudi[$i];
                $tuple[$i] = (int)($tuple[$i]);
                $tuple[$i+3][0] = min($tuple[$i],$tuple[$i+3][0]);
                $tuple[$i+3][1] = max($tuple[$i],$tuple[$i+3][1]);
            }
        }
        $tuple[2] = $curTime;
        return true;
    }
    static private function update2d($keys, $idx, $dudis, $caseId) {
        Log::debug(__FUNCTION__.": updating cache map ".count($keys)." keys. ".$caseId);
        if (count($keys)) {
            for($i=0; $i<count($keys); $i++) {
                Log::debug(__FUNCTION__.": sample:".$keys[$i].":".json_encode($dudis[$i]));
            }
        }
        self::mget2d($keys, $idx, $olddata, $missing);
        $newdata = [];
        $curTime = time();
        foreach ($dudis as $i=>$elem) {
            $start_loc = $idx[$i][0];
            $end_loc = $idx[$i][1];
            $shouldUpdate = true;
            if (isset($olddata[$start_loc][$end_loc])) {
                $newitem = $olddata[$start_loc][$end_loc];
                if (empty($newitem[$caseId])) {
                    $newitem[$caseId] = [$elem[0], $elem[1], $curTime, [$elem[0],$elem[0]], [$elem[1],$elem[1]]];
                }
                else {
                    $shouldUpdate = self::accumlate($newitem[$caseId],$elem,$curTime);
                }
            }
            else {
                $newitem[$caseId] = [$elem[0], $elem[1], $curTime, [$elem[0],$elem[0]], [$elem[1],$elem[1]]];
                if ($caseId != 'base') {
                    $du = (int)($elem[0]*1.0/self::HEURISTIC_FACTOR[$caseId]);
                    $di = $elem[1];
                    $newitem['base'] = [$du, $di, -1, [$du, $du], [$di,$di]];
                }
            }
            if (!$shouldUpdate) continue;
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
            list($cursor, $keys) = Redis::scan($cursor, 'match', $key_pat, 'count', 500);
            if (empty($keys)) break;
            $items = Redis::mget(...$keys);
            foreach ($items as $i=>$item) {
                yield [$keys[$i], json_decode($item, true)];
            }
        } while ($cursor);
    }
    static public function remove_dirty($samples) {
        foreach($samples as $rec) {
            $key = $rec[0];
            Redis::del($key);
        }
    }
    static public function check_dirty() {
        $all_item = self::scan_item();
        $samples = [];
        foreach($all_item as $keyitem) {
            $item = $keyitem[1];
            if (!isset($item['lv4']) && !isset($item['lv2'])) continue;
            $mi = $item['base'];
            $ma = $item['base'];
            foreach(['lv2', 'lv4'] as $caseId) {
                if (!isset($item[$caseId])) continue;
                for($i=0; $i<2; $i++) {
                    $mi[$i] = min($mi[$i], $item[$caseId][$i]);
                    $ma[$i] = max($ma[$i], $item[$caseId][$i]);
                }
            }
            $abnormal = false;
            for($i=0; $i<2; $i++) {
                if ($ma[$i]>=2*$mi[$i]) {
                    $abnormal = true; break;
                }
            }
            if ($abnormal && count($samples)<1000) $samples[] = $keyitem;
        }
        return [count($samples), $samples];
    }
    static public function calc_ratio() {
        $all_item = self::scan_item();
        $total_ratio = ['lv2'=>0, 'lv4'=>0];
        $total_count = ['lv2'=>0, 'lv4'=>0];
        $samples = [];
        $n = 0;
        foreach($all_item as $keyitem) {
            $item = $keyitem[1];
            if (!isset($item['lv4'])) continue;
            foreach(['lv4'] as $caseId) {
                if (!isset($item[$caseId])) continue;
                $n += 1;
                if ($item['base'][2] > 0) {
                    $total_count[$caseId] += 1;
                    $total_ratio[$caseId] += $item[$caseId][0]*1.0/$item['base'][0];
                    if (count($samples)<100) $samples[] = $keyitem;
                }
            }
        }
        return [$n, $total_ratio, $total_count, $samples];
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
