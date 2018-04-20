<?php
namespace App\Providers\MapService;

use Log;
use App\Exceptions\CmException;

class MapService{

    public $consts;
    const CENTER = [43.78,-79.28];
    //['LAT'=>1,'LNG'=>1.385037];//1.0/cos($this->consts['CENTER']['LAT']/180.0*3.14159)];
    const DEG_TO_KM_RATIO = [111.1949,154.0091];//6371*1.0/180*3.14159
    const DEG_PRECISION = 5;
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

    /*
     * [in] $loc_dict: [{'lat':dobule, 'lng':double, 'addr':str}]
     * [out] $loc_dict: [{'lat':dobule, 'lng':double, 'addr':str, 'gridId':str, 'idx':int, 'adjustLatLng':string}]
     * return $dist_mat: 2-D double
     */
    public function get_dist_mat(&$loc_dict) {
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
        $dist_mat = $this->dist_approx($origin_loc_arr, $dest_loc_arr);
        $ret = [];
        foreach ($origin_loc_arr as $i=>$start_loc)
            foreach ($origin_loc_arr as $j=>$end_loc) {
                $ret[$i][$j] = $dist_mat[$start_loc][$end_loc] ?? -1;
            }
        return $ret;
    }
    private function dist_approx($origin_loc_arr, $dest_loc_arr) {
        $cached_mat = CacheMap::get_dist_mat($origin_loc_arr, $dest_loc_arr);
        $dist_mat = $cached_mat;
        $missed_pairs = [];
        $nn = 0;
        foreach($origin_loc_arr as $origin_loc) {
            foreach($dest_loc_arr as $dest_loc) {
                if ($origin_loc == $dest_loc) continue;
                if (!isset($dist_mat[$origin_loc][$dest_loc])) {
                    $missed_pairs[$origin_loc][$dest_loc] = 1;
                    $nn++;
                }
            }
        }
        Log::debug("cache missing ".$nn." pairs");
        if (empty($missed_pairs)) return $dist_mat;
        $quota = GoogleMapProxy::get_quota();
        if ($quota <= 5) {
            throw new CmException('SYSTEM_ERROR', "out of quota");
        }
        list($sel_origins, $sel_dests) = $this->approx_select($missed_pairs, $quota);
        $sel_mat = GoogleMapProxy::get_dist_mat($sel_origins, $sel_dests);
        CacheMap::set_dist_mat($sel_mat);
        foreach ($sel_mat as $start_loc=>$rows) {
            foreach($rows as $end_loc=>$elem) {
                if ($start_loc == $end_loc) continue;
                $dist_mat[$start_loc][$end_loc] = $elem[0];
                unset($missed_pairs[$start_loc][$end_loc]);
            }
        }
        Log::debug("after query, missing ".$nn." pairs");
        foreach ($missed_pairs as $start_loc=>$missed_rows) {
            foreach ($missed_rows as $end_loc=>$missed_elem) {
                $dist_mat[$start_loc][$end_loc] = $this->weighted_approx($start_loc, $end_loc, $sel_mat);
            }
        }
        return $dist_mat;
    }
    private function weighted_approx($start_loc, $end_loc, $base_mat) {
        $total_weight = 0;
        $total_ratio = [0,0];
        $xx = self::toGridIdx(explode(',',$start_loc));
        $yy = self::toGridIdx(explode(',',$end_loc));
        $ll = sqrt(($xx[0]-$yy[0])**2 + ($xx[1]-$yy[1])**2);
        foreach ($base_mat as $start_loc=>$rows) {
            foreach($rows as $end_loc=>$elem) {
                if ($start_loc == $end_loc) continue;
                $dist_mat[$start_loc][$end_loc] = $elem[0];
                $x = self::toGridIdx(explode(',',$start_loc));
                $y = self::toGridIdx(explode(',',$end_loc));
                $l2_dist = sqrt(($x[0]-$y[0])**2 + ($x[1]-$y[1])**2);
                $ratio = [$elem[0]/$l2_dist, $elem[1]/$l2_dist];
                $expw = -abs($xx[0]-$x[0])-abs($xx[1]-$x[1])-abs($yy[0]-$y[0])-abs($yy[1]-$y[1]);
                $weight = exp($expw/100.0); // heuristic mean for 200*200 grid
                $total_weight += $weight;
                for($i=0; $i<2; $i++){
                    $total_ratio[$i] += $weight * $ratio[$i];
                }
            }
        }
        return (int)round($ll * $total_ratio[0]/$total_weight);
    }
    private function approx_select($missed_pairs, $quota) {
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
        $nStart = min(count($starts),3);
        $nEnd = min(count($ends),4);
        $sel_start = array_rand($starts, $nStart);
        $sel_end = array_rand($ends, $nEnd);
        $nEle = $this->count_cover($sel_start, $sel_end, $missed_pairs);
        for($ntry = 0; $ntry < 5; $ntry++) {
            if ($nStart == count($starts) && $nEnd == count($ends)) break;
            if ($nEle == $nMissed) break;
            $temp_start = array_rand($starts, $nStart);
            $temp_end = array_rand($ends, $nEnd);
            $temp_nEle = $this->count_cover($temp_start, $temp_end, $missed_pairs);
            if ($temp_nEle > $nEle) {
                list($nEle, $sel_start, $sel_end) = [$temp_nEle, $temp_start, $temp_end];
            }
        }
        Log::debug("from ".json_encode([$starts, $ends])." select ".json_encode([$sel_start, $sel_end]));
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
