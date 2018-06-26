<?php
namespace App\Providers\MapService;

use Log;
use App\Exceptions\CmException;

class MapService{

    public $consts;
    const CENTER = [43.78,-79.28];
    //['LAT'=>1,'LNG'=>1.385037];//1.0/cos($this->consts['CENTER']['LAT']/180.0*3.14159)];
    const DEG_TO_KM_RATIO = [111.1949,154.0091];//6371*1.0/180*3.14159
    const DEG_PRECISION_FORMAT= "%.6f,%.6f";
    //although the grid is about 100m, we need its center precise enough for stable google map query response
    const GRID_LEN_KM = 0.1;
    public function __construct()
    {
        $this->consts = array();
        $this->consts['MAXNLOCATIONS'] = 100;
    }
    static public function toGridIdx($latlng_arr) {
        $ret = $latlng_arr;
        for($i=0; $i<2; $i++) {
            $ret[$i] = ($ret[$i]-self::CENTER[$i])*self::DEG_TO_KM_RATIO[$i]/self::GRID_LEN_KM;
            $ret[$i] = intval(floor($ret[$i]));
        }
        return $ret;
    }
    static public function toLatLngStr($idx_arr) {
        $ret = $idx_arr;
        for($i=0; $i<2; $i++) {
            $ret[$i] += 0.5;
            $ret[$i] *= self::GRID_LEN_KM/self::DEG_TO_KM_RATIO[$i];
            $ret[$i] += self::CENTER[$i];
        }
        return sprintf(self::DEG_PRECISION_FORMAT, $ret[0],$ret[1]);
    }
    public function test() {
        return CacheMap::test();
        //$sp = app()->make('cmoa_model_cache_service')->get('LogCache');
        //$sp->rewrite_all('cmoa_ext_output');
    }
    /*
     * [in] $loc_dict: [{'lat':dobule, 'lng':double, 'addr':str}]
     * [out] $loc_dict: [{'lat':dobule, 'lng':double, 'addr':str, 'gridId':str, 'idx':int, 'adjustLatLng':string}]
     */
    public function aggregate_locs(&$loc_dict) {
        $grid_dict = [];
        foreach($loc_dict as $k=>$loc) {
            $grid_idx_arr = self::toGridIdx([$loc['lat'], $loc['lng']]);
            $gridId = implode(',', $grid_idx_arr);
            $latlng = self::toLatLngStr($grid_idx_arr);
            $loc_dict[$k]['gridId'] = $gridId;
            $loc_dict[$k]['grid_idx_arr'] = $grid_idx_arr;
            $loc_dict[$k]['adjustLatLng'] = $latlng;
            if (explode('-',$k)[0] == 'dr' && !isset($grid_dict[$gridId]['type'])) {
                $grid_dict[$gridId]['type'] = 2;
            }
            else {
                $grid_dict[$gridId]['type'] = 1;
            }
        }
        $origin_loc_arr = [];
        $dest_loc_arr = [];
        foreach($grid_dict as $gridId=>$v) {
            $latlng=self::toLatLngStr(explode(',',$gridId));
            $grid_dict[$gridId]['idx'] = count($origin_loc_arr);
            $origin_loc_arr[] = $latlng;
            if ($grid_dict[$gridId]['type'] == 1) {
                $dest_loc_arr[] = $latlng;
            }
        }
        foreach($loc_dict as $k=>$v) {
            $loc_dict[$k]['idx'] = $grid_dict[$v['gridId']]['idx']?? -1;
        }
        Log::debug("len(origin):".count($origin_loc_arr)." len(dest):".count($dest_loc_arr));
        return [$origin_loc_arr, $dest_loc_arr];
    }

    public function get_caseId($isHoliday=null) {
        $dt = new \DateTime('now', new \DateTimeZone('America/Toronto'));
        if ($isHoliday === null) {
            $weekday = $dt->format('w');
            $isHoliday = ($weekday == '0' || $weekday == '6');
        }
        if ($isHoliday) return 'base';
        $dt->setTime(16,0);
        $diff_sec = time() - $dt->getTimestamp();
        if ($diff_sec >=0 && $diff_sec < 3600) { //4:00pm ~ 5:00pm
            return 'lv2';
        }
        if ($diff_sec >=3600 && $diff_sec < 7200) { //5:00pm ~ 6:00pm
            return 'lv4';
        }
        if ($diff_sec >=7200 && $diff_sec < 10800) { //6:00pm ~ 7:00pm
            return 'lv2';
        }
        return 'base';
    }
    public function learn_map($target_mat) {
        return; //close learn map; learn it in the get_dist_mat call's verify
    }
    public function get_dist_mat($target_mat) {
        $isHoliday=null;
        $caseId = $this->get_caseId($isHoliday);
        list($dist_mat, $missed) = $this->dist_approx_from_cache($target_mat, $caseId);
        $this->verify($dist_mat,$missed,$caseId);
        return $dist_mat;
    }
    private function dist_approx_from_cache($target_mat, $caseId) {
        $timer['total'] = $timer['get_mat'] = -microtime(true);
        list($dist_mat, $missed_pairs) = CacheMap::get_mat($target_mat);
        CacheMap::extractCase($caseId, $dist_mat);
        $timer['get_mat'] += microtime(true);
        if (empty($missed_pairs)) return [$dist_mat,$missed_pairs];
        $timer['approx_mat'] = -microtime(true);
        CacheMap::approx_mat($missed_pairs, $dist_mat, $caseId);
        $timer['approx_mat'] += microtime(true);
        $timer['total'] += microtime(true);
        Log::debug(__FUNCTION__.':timers:'.json_encode($timer));
        return [$dist_mat,$missed_pairs];
    }
    private function verify(&$dist_mat, $missed, $caseId) {
        list($sel_origins, $sel_dests) = $this->approx_select($missed);
        $sel_mat = GoogleMapProxy::get_gm_mat($sel_origins, $sel_dests);
        CacheMap::update_mat($sel_mat, $caseId);
        $errors = [];
        foreach($sel_mat as $start_loc=>$row) {
            foreach($row as $end_loc=>$elem) {
                if (!isset($dist_mat[$start_loc][$end_loc])) {
                    //Log::debug(__FUNCTION__.':not_set:'.$start_loc.$end_loc);
                    //not set because of unnecessary, i.e. not in the query target
                    continue;
                }
                $estm = $dist_mat[$start_loc][$end_loc];
                $real = $elem[0];
                if ($real == 0) {
                    Log::debug("zero real duration between:".$start_loc." and ".$end_loc);
                    continue;
                }
                $errors[$start_loc.":".$end_loc] = [$estm, $real, abs($real-$estm)*1.0/$real];
                $dist_mat[$start_loc][$end_loc] = $real;
            }
        }
        Log::debug(__FUNCTION__.": error".json_encode($errors));
    }
    private function my_array_random($arr, $n) {
        //quick fix for the wierd return of array_rand; TODO: use a better one
        return  ($n == 1) ? [array_rand($arr,$n)]:array_rand($arr,$n);
    }
    private function approx_select($missed_pairs) {
        $starts = [];
        $ends = [];
        $nMissed = 0;
        foreach($missed_pairs as $start_loc=>$rows) {
            foreach($rows as $end_loc=>$elem) {
                $starts[$start_loc] = ($starts[$start_loc] ?? 0)+1;
                $ends[$end_loc] = ($ends[$end_loc] ?? 0)+1;
                $nMissed += 1;
            }
        }
        Log::debug(__FUNCTION__.": #missed items=".$nMissed);
        if ($nMissed == 0) return [[], []];
        $nStart = min(count($starts),3);
        $nEnd = min(count($ends),4);
        $sel_start = $this->my_array_random($starts, $nStart);
        $sel_end = $this->my_array_random($ends, $nEnd);
        $nEle = $this->count_cover($sel_start, $sel_end, $missed_pairs);
        for($ntry = 0; $ntry < 5; $ntry++) {
            if ($nStart == count($starts) && $nEnd == count($ends)) break;
            if ($nEle == $nMissed) break;
            $temp_start = $this->my_array_random($starts, $nStart);
            $temp_end = $this->my_array_random($ends, $nEnd);
            $temp_nEle = $this->count_cover($temp_start, $temp_end, $missed_pairs);
            if ($temp_nEle > $nEle) {
                list($nEle, $sel_start, $sel_end) = [$temp_nEle, $temp_start, $temp_end];
            }
        }
        //Log::debug("from ".json_encode([$starts, $ends])." select ".json_encode([$sel_start, $sel_end]));
        return [$sel_start, $sel_end];
    }
    private function count_cover($sel_start, $sel_end, $missed_pairs) {
        $nEle = 0;
        foreach($sel_start as $start_loc) {
            foreach($sel_end as $end_loc) {
                if (isset($missed_pairs[$start_loc][$end_loc])) $nEle += 1;
            }
        }
        return $nEle;
    }
    public function decodeToken($tok) {
        return CacheMap::TokenToExtLoc($tok);
    }
}
