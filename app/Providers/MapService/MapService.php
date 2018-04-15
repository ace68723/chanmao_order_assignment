<?php
namespace App\Providers\MapService;

use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use App\Exceptions\CmException;

class MapService{

    public $consts;
    public $data;
    public function __construct()
    {
        $this->consts = array();
        $this->consts['MAXNLOCATIONS'] = 100;
        $this->consts['CENTER'] = [43.78,-79.28];
        //$this->consts['DEG_TO_M_RATIO'] = ['LAT'=>1,'LNG'=>1.385037];//1.0/cos($this->consts['CENTER']['LAT']/180.0*3.14159)];
        $this->consts['DEG_TO_KM_RATIO'] = [111.1949,154.0091];//6371*1.0/180*3.14159
        $this->consts['DEG_PRECISION'] = 6;
        $this->consts['GRID_LEN_KM'] = 0.1;
        $this->consts['GOOGLE_API_KEY'] = env('GOOGLE_API_KEY');
    }
    public function toGridIdx($latlng_arr) {
        $ret = $latlng_arr;
        for($i=0; $i<2; $i++) {
            $ret[$i] = ($ret[$i]-$this->consts['CENTER'][$i])*$this->consts['DEG_TO_KM_RATIO'][$i]/$this->consts['GRID_LEN_KM'];
            $ret[$i] = intval(floor($ret[$i]));
        }
        return $ret;
    }
    public function toLatLng($idx_arr) {
        $ret = $idx_arr;
        for($i=0; $i<2; $i++) {
            $ret[$i] += 0.5;
            $ret[$i] *= $this->consts['GRID_LEN_KM']/$this->consts['DEG_TO_KM_RATIO'][$i];
            $ret[$i] += $this->consts['CENTER'][$i];
            $ret[$i] = round($ret[$i],$this->consts['DEG_PRECISION']);
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
            $grid_idx_arr = $this->toGridIdx([$loc['lat'], $loc['lng']]);
            $gridId = implode(',', $grid_idx_arr);
            $latlng = implode(',', $this->toLatLng($grid_idx_arr));
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
        foreach($grid_dict as $gid=>$v) {
            $latlng=implode(',',$this->toLatLng(explode(',',$gid)));
            $grid_dict[$gid]['idx'] = count($origin_loc_arr);
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
        return $dist_mat;
    }
    private function dist_approx($origin_loc_arr, $dest_loc_arr) {
        $cached_mat_dict = $this->cache_get_dist_dict($origin_loc_arr, $dest_loc_arr);
        $dist_mat_dict = $cached_mat;
        $missed_pairs = [];
        foreach($origin_loc_arr as $origin_loc) {
            foreach($dest_loc_arr as $dest_loc) {
                if (!isset($dist_mat_dict[$origin_loc][$dest_loc])) {
                    $missed_pairs[$origin_loc][$dest_loc] = 1;
                }
            }
        }
        if (empty($missed_pairs))
            return $dist_mat_dict;
        $quota = GoogleMapProxy::getQuota();
        if ($quota <= 5) {
            throw new CmException('SYSTEM_ERROR', "out of quota");
        }
        list($sel_origins, $sel_dests) = $this->approx_select($missed_pairs, $quota);
        $sel_mat = $this->google_map_dist($sel_origins, $sel_dests);
        $di_ratio = 0;
        $du_ratio = 0;
        $n = 0;
        foreach ($sel_mat as $rows) {
            foreach($rows as $elem) {
                list($i,$j) = $elem[0];
                $start_loc = $sel_origins[$i];
                $end_loc = $sel_dests[$j];
                if ($start_loc == $end_loc) continue;
                $dist_mat[$start_loc][$end_loc] = $elem[1];
                $x = $this->toGridIdx(explode(',',$start_loc));
                $y = $this->toGridIdx(explode(',',$end_loc));
                $l2_dist = sqrt(($x[0]-$y[0])**2 + ($x[1]-$y[1])**2);
                $n += 1;
                $du_ratio += $elem[1]/$l2_dist;
                $di_ratio += $elem[2]/$l2_dist;
            }
        }
        $du_ratio /= $n;
        $di_ratio /= $n;
        return;
    }
    private function cache_get_dist_dict($origin_loc_arr, $dest_loc_arr) {
        return [];
    }
    private function approx_select($missed_paris, $quota) {
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
        return [$sel_start, $sel_end];
    }
    private function count_cover($sel_start, $sel_end, $missed_pairs) {
        $nEle = 0;
        foreach($sel_start as $start_loc) {
            foreach($sel_end as $end_loc) {
                if (isset($missed_pairs)) $nEle += 1;
            }
        }
        return $nEle;
    }
}
