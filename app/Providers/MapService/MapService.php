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
        $this->consts['GRID_LEN_KM'] = 0.1;
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
            $ret[$i] = round($ret[$i],6);
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
        $this->dist_approx($origin_loc_arr);
        return [];
    }
    private function dist_approx($origin_loc_arr) {
        $dist_mat_dict = [];
        $n = min(count($origin_loc_arr), 7);
        $quota = Redis::get("map:quota");
        if (is_null($quota)) $quota = 2500;
        else $quota = intval($quota);
        if ($n*$n>$quota) {
            $n = intval(sqrt($quota));
        }
        if ($n <= 1) {
            throw new CmException('SYSTEM_ERROR', "dist_approx");
        }
        Redis::setex("map:quota", 24*3600, $quota - $n*$n);
        Log::debug($quota);
        return;
    }
}
