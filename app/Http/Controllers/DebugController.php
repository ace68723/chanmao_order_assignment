<?php

namespace App\Http\Controllers;

use Log;
use S2;
use Illuminate\Http\Request;
use App\Exceptions\CmException;


class DebugController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->consts['REQUEST_PARAS']['get_orders'] = [
        ];
        $this->consts['REQUEST_PARAS']['get_drivers'] = [
            'area_id'=>[
                'checker'=>['is_int', ],
                'required'=>false,
                'description'=> '-1/null to get all',
                'default_value'=> -1,
            ],
        ];
        $this->consts['REQUEST_PARAS']['get_drivers_info'] = [
            'driver_ids'=>[
                'checker'=>['is_array', ],
                'required'=>true,
                'child_obj'=> [
                    'driver_id'=>[
                        'checker'=>['is_int',[1,'inf']],
                        'required'=>true,
                    ],
                ],
            ],
        ];
        $this->consts['REQUEST_PARAS']['get_driver_modifiers'] = [
            'driver_id'=>[
                'checker'=>['is_int', ],
                'required'=>false,
                'description'=> '-1/null to get all',
                'default_value'=> -1,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['get_schedule'] = [
            'driver_id'=>[
                'checker'=>['is_int', ],
                'required'=>false,
                'description'=> '-1/null to get all',
                'default_value'=> -1,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['get_log'] = [
            'log_type'=>[
                'checker'=>['is_string', ],
                'required'=>true,
            ],
            'timestamp'=>[
                'checker'=>['is_int', ],
                'required'=>true,
            ],
            'search_direction'=>[
                'checker'=>['is_string', ],
                'required'=>false,
                'description'=> 'enum("next","prev","at")',
                'default_value'=> 'at',
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['get_unicache'] = [
            'log_type'=>[
                'checker'=>['is_string', ],
                'required'=>true,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['modify_driver'] = [
            'driver_id'=>['checker'=>'is_string', 'required'=>true, ],
            'driver_name'=>['checker'=>'is_string', 'required'=>false, ],
            'hour'=>['checker'=>'is_string', 'required'=>false, ],
            'area'=>['checker'=>'is_string', 'required'=>false, ],
            'cell'=>['checker'=>'is_string', 'required'=>false, ],
            'valid_from'=>['checker'=>'is_numeric', 'required'=>false, ],
            'valid_to'=>['checker'=>'is_numeric', 'required'=>false, ],
            'timestamp'=>['checker'=>'is_numeric', 'required'=>false, ],
            'lat'=>['checker'=>'is_numeric', 'required'=>false, ],
            'lng'=>['checker'=>'is_numeric', 'required'=>false, ],
            'workload'=>['checker'=>'is_int', 'required'=>false, ],
            'areaId'=>['checker'=>'is_int', 'required'=>false, ],
            'expire_sec'=>['checker'=>'is_int', 'required'=>false, 'default_value'=>900],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['learn_map'] = [
            'area'=>[
                'checker'=>['is_int', ],
                'required'=>true,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters

        if (!$this->check_api_def())
            throw new CmException('SYSTEM_ERROR', "ERROR SETTING IN API SCHEMA");
    }

    public function get_orders(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('OrderCache');
        $ret = $sp->get_orders();
        return $this->format_success_ret($ret);
    }
    public function get_drivers(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('DriverCache');
        $ret = $sp->get_drivers($la_paras['area_id']);
        return $this->format_success_ret($ret);
    }
    public function get_schedule(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj, true);
        $sp = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        if ($la_paras['driver_id']<=0) $la_paras['driver_id'] = null;
        $ret = $sp->get_schedules($la_paras['driver_id']);
        return $this->format_success_ret($ret);
    }
    public function get_log(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('LogCache');
        if ($la_paras['search_direction'] == 'prev') {
            list($logTime, $ret) = $sp->get_prev($la_paras['log_type'], $la_paras['timestamp']);
        }
        elseif ($la_paras['search_direction'] == 'next') {
            list($logTime, $ret) = $sp->get_next($la_paras['log_type'], $la_paras['timestamp']);
        }
        else {
            list($logTime, $ret) = $sp->get_at($la_paras['log_type'], $la_paras['timestamp']);
        }
        if (!empty($ret)) {
            $ret['logTime'] = $logTime;
        }
        return $this->format_success_ret($ret);
    }
    public function get_unicache(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('UniCache');
        $ret = $sp->get($la_paras['log_type']);
        return $this->format_success_ret($ret);
    }
    public function learn_map(Request $request) {
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sche_sp = app()->make('cmoa_schedule_service');
        $ret = $sche_sp->learn_map($la_paras['area']);
        return $this->format_success_ret($ret);
    }
    public function get_map_caseId(Request $request){
        $userObj = null;
        //$la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_map_service');
        $ret = $sp->get_caseId();
        return $this->format_success_ret($ret);
    }
    public function test_map(Request $request) {
        $map_sp = app()->make('cmoa_map_service');
        $ret = $map_sp->test();
        return $this->format_success_ret($ret);
        $ret = [];
        $center = S2\S2CellId::fromLatLng(S2\S2LatLng::fromDegrees(45.234, -79.1111));
        $level = 16;
        $center = $center->parent($level);
        $bound = (new S2\S2Cell($center))->getRectBound();
        $lo = $bound->lo();
        $hi = $bound->hi();
        $ret = [$center, $lo->toStringDegrees(),$hi->toStringDegrees()];
        $dirs = [[0,1],[1,0],[-1,0],[0,-1]];
        $prec = 0.00001;
        //$ret = S2CellId::fromLatLng(S2LatLng::fromDegrees(45.234, -79.1111));
        //$ret = (new S2CellId(5560388600765437049))->toLatLng()->toStringDegrees();
        return $this->format_success_ret($ret);
    }
    public function get_heat_map(Request $request) {
        $sp = app()->make('cmoa_model_cache_service')->get('OrderCache');
        $ret = $sp->get_heat_map();
        return $this->format_success_ret($ret);
    }
    public function reset_heat_map(Request $request) {
        $sp = app()->make('cmoa_model_cache_service')->get('OrderCache');
        $ret = $sp->reset_heat_map();
        return $this->format_success_ret($ret);
    }
    public function calc_heat_map(Request $request) {
        $sp = app()->make('cmoa_model_cache_service')->get('OrderCache');
        $ret = $sp->calc_heat_map();
        return $this->format_success_ret($ret);
    }
    public function get_dist_mat(Request $request) {
        $input = $request->json()->all();
        $loc_dict = $input['loc_dict'];
        $task_dict = $input['task_dict'] ?? null;
        $driver_dict = $input['driver_dict'] ?? null;
        $sch_sp = app()->make('cmoa_schedule_service');
        //$map_sp = app()->make('cmoa_map_service');
        //list($origin_loc_arr, $dest_loc_arr) = $map_sp->aggregate_locs($loc_dict);
        //$target = $sch_sp->get_target_dist_mat($origin_loc_arr, $dest_loc_arr, $loc_dict, $task_dict, $driver_dict);
        $dist_mat = $sch_sp->get_dist_mat($loc_dict, $task_dict, $driver_dict);
        return $dist_mat;
    }
    public function modify_driver(Request $request) {
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('DriverCache');
        $expire_sec = $la_paras['expire_sec'];
        if (isset($la_paras['lat']) || isset($la_paras['lng'])) {
            $expire_sec = min(900, $expire_sec);
        }
        $ret = $sp->set_driver_modifier($la_paras['driver_id'], $la_paras, $expire_sec);
        return $this->format_success_ret($ret);
    }
    public function get_driver_modifiers(Request $request) {
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('DriverCache');
        $driver_ids = null;
        if ($la_paras['driver_id'] != -1) {
            $driver_ids = [$la_paras['driver_id']];
        }
        $ret = $sp->get_driver_modifiers($driver_ids);
        return $this->format_success_ret($ret);
    }
    public function get_drivers_info(Request $request) {
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('DriverCache');
        $ret = $sp->get_drivers_info($la_paras['driver_ids']);
        return $this->format_success_ret($ret);
    }
    public function debug_call(Request $request) {
        $input = $request->json()->all();
        $sp = app()->make('cmoa_map_service');
        return $sp->decodeToken($input['token']);
    }
}
