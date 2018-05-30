<?php
namespace App\Providers\MapService;

use Log;
use App\Exceptions\CmException;

class MapService{

    public $consts;
    const CENTER = [43.78,-79.28];
    //['LAT'=>1,'LNG'=>1.385037];//1.0/cos($this->consts['CENTER']['LAT']/180.0*3.14159)];
    const DEG_TO_KM_RATIO = [111.1949,154.0091];//6371*1.0/180*3.14159
    const DEG_PRECISION = 4;
    const GRID_LEN_KM = 0.1;
    const HEURISTIC_RATIO = 11.875;
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
    static public function toLatLng($idx_arr) {
        $ret = $idx_arr;
        for($i=0; $i<2; $i++) {
            $ret[$i] += 0.5;
            $ret[$i] *= self::GRID_LEN_KM/self::DEG_TO_KM_RATIO[$i];
            $ret[$i] += self::CENTER[$i];
            $ret[$i] = round($ret[$i],self::DEG_PRECISION);
        }
        return $ret;
    }
    public function test() {
        $items = CacheMap::scan_item();
        $count = []; $total_ratio = [];
        foreach(['cgst','norm','_s'] as $i) {
            $count[$i] = 0;
            $total_ratio[$i] = 0;
        }
        $last = null;
        foreach($items as $rec) {
            foreach(['cgst','norm'] as $caseId) if (!empty($rec[$caseId])) {
                $count[$caseId] += 1;
                $total_ratio[$caseId] += $rec[$caseId][0]*1.0/$rec['_m'];
            }
            $count['_s'] += 1;
            $total_ratio['_s'] += $rec['_s'][0]*1.0/$rec['_m'];
            $last = $rec;
        }
        foreach(['cgst','norm','_s'] as $i) {
            if ($count[$i]>0) {
                $total_ratio[$i] /= $count[$i];
            }
        }
        return [$count, $total_ratio, $last];
    }
    private function aggregate_locs(&$loc_dict) {
        $grid_dict = [];
        foreach($loc_dict as $k=>$loc) {
            $grid_idx_arr = self::toGridIdx([$loc['lat'], $loc['lng']]);
            $gridId = implode(',', $grid_idx_arr);
            $latlng = implode(',', self::toLatLng($grid_idx_arr));
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
            $latlng=implode(',',self::toLatLng(explode(',',$gridId)));
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
        return [$grid_dict, $origin_loc_arr, $dest_loc_arr];
    }

    public function get_caseId($isHoliday=null) {
        $dt = new \DateTime('now', new \DateTimeZone('America/Toronto'));
        if ($isHoliday === null) {
            $weekday = $dt->format('w');
            $isHoliday = ($weekday == '0' || $weekday == '6');
        }
        if ($isHoliday) return 'norm';
        $dt->setTime(16,30);
        $diff_sec = time() - $dt->getTimestamp();
        if ($diff_sec >=0 && $diff_sec <= 7200) { //4:30pm ~ 6:30pm
            return 'cgst';
        }
        return 'norm';
    }
    public function learn_map(&$loc_dict, $isHoliday=null) {
        $caseId = $this->get_caseId($isHoliday);
        list($grid_dict, $origin_loc_arr, $dest_loc_arr) = $this->aggregate_locs($loc_dict);
        list($cached_mat, $missed) = CacheMap::get_mat($origin_loc_arr, $dest_loc_arr);
        list($sel_origins, $sel_dests) = $this->approx_select($missed);
        $sel_mat = GoogleMapProxy::get_gm_mat($sel_origins, $sel_dests);
        CacheMap::update_mat($sel_mat, $caseId);
    }
    /*
     * [in] $loc_dict: [{'lat':dobule, 'lng':double, 'addr':str}]
     * [out] $loc_dict: [{'lat':dobule, 'lng':double, 'addr':str, 'gridId':str, 'idx':int, 'adjustLatLng':string}]
     * return $dist_mat: 2-D double
     */
    public function get_dist_mat(&$loc_dict, $isHoliday=null) {
        $caseId = $this->get_caseId($isHoliday);
        list($grid_dict, $origin_loc_arr, $dest_loc_arr) = $this->aggregate_locs($loc_dict);
        list($dist_mat, $missed) = $this->dist_approx_from_cache($origin_loc_arr, $dest_loc_arr, $caseId);
        $this->verify($dist_mat,$missed,$caseId);
        $ret = [];
        foreach ($origin_loc_arr as $i=>$start_loc)
            foreach ($origin_loc_arr as $j=>$end_loc) {
                $ret[$i][$j] = $dist_mat[$start_loc][$end_loc] ?? -1;
            }
        return $ret;
    }
    private function dist_approx_from_cache($origin_loc_arr, $dest_loc_arr, $caseId) {
        list($dist_mat, $missed_pairs) = CacheMap::get_mat($origin_loc_arr, $dest_loc_arr);
        CacheMap::extractCase($caseId, $dist_mat);
        if (empty($missed_pairs)) return [$dist_mat,$missed_pairs];
        $sel_mat = CacheMap::query_near_batch($missed_pairs);
        CacheMap::extractCase($caseId, $sel_mat);
        foreach ($missed_pairs as $start_loc=>$missed_rows) {
            foreach ($missed_rows as $end_loc=>$missed_elem) {
                $dist_mat[$start_loc][$end_loc] = $this->weighted_approx($start_loc, $end_loc, $sel_mat);
            }
        }
        return [$dist_mat,$missed_pairs];
    }
    private function verify(&$dist_mat, $missed, $caseId) {
        list($sel_origins, $sel_dests) = $this->approx_select($missed);
        $sel_mat = GoogleMapProxy::get_gm_mat($sel_origins, $sel_dests);
        CacheMap::update_mat($sel_mat, $caseId);
        $errors = [];
        foreach($sel_mat as $start_loc=>$row) {
            foreach($row as $end_loc=>$elem) {
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
    private function weighted_approx($start_loc, $end_loc, $base_mat,
        $default_ratio=self::HEURISTIC_RATIO, $max_range_grid_l1=100) {
        $total_weight = 0;
        $total_ratio = 0;
        $xx = self::toGridIdx(explode(',',$start_loc));
        $yy = self::toGridIdx(explode(',',$end_loc));
        $ll = sqrt(($xx[0]-$yy[0])**2 + ($xx[1]-$yy[1])**2);
        $found = false;
        foreach ($base_mat as $start_loc=>$rows) {
            foreach($rows as $end_loc=>$elem) {
                if ($start_loc == $end_loc) continue;
                $dist_mat[$start_loc][$end_loc] = $elem;
                $x = self::toGridIdx(explode(',',$start_loc));
                $y = self::toGridIdx(explode(',',$end_loc));
                $l2_dist = sqrt(($x[0]-$y[0])**2 + ($x[1]-$y[1])**2);
                $ratio = $elem/$l2_dist;
                $expw = -abs($xx[0]-$x[0])-abs($xx[1]-$x[1])-abs($yy[0]-$y[0])-abs($yy[1]-$y[1]);
                if (-$expw > $max_range_grid_l1) {
                    continue;
                }
                $weight = exp($expw/100.0); // heuristic mean for 200*200 grid
                $found = true;
                $total_weight += $weight;
                $total_ratio += $weight * $ratio;
            }
        }
        return (int)round($ll * ($found ? $total_ratio/$total_weight : $default_ratio));
    }
    private function my_array_random($arr, $n) {
        //quick fix for the wierd return of array_rand; TODO: get a better one
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
}
