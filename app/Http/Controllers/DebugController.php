<?php

namespace App\Http\Controllers;

use Log;
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
        $this->consts['REQUEST_PARAS']['calc_mean_ratio'] = [
        ];
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
        $this->consts['REQUEST_PARAS']['get_schedule'] = [
            'driver_id'=>[
                'checker'=>['is_int', ],
                'required'=>false,
                'description'=> '-1/null to get all',
                'default_value'=> -1,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['get_lastlog'] = [
            'log_type'=>[
                'checker'=>['is_string', ],
                'required'=>true,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters
        $this->consts['REQUEST_PARAS']['get_unicache'] = [
            'log_type'=>[
                'checker'=>['is_string', ],
                'required'=>true,
            ],
        ]; // parameter's name MUST NOT start with "_", which are reserved for internal populated parameters

        if (!$this->check_api_def())
            throw new CmException('SYSTEM_ERROR', "ERROR SETTING IN API SCHEMA");
    }

    public function calc_mean_ratio(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_map_service');
        $ret = $sp->calc_mean_ratio();
        return $this->format_success_ret($ret);
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
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        if ($la_paras['driver_id']<=0) $la_paras['driver_id'] = null;
        $ret = $sp->get_schedules($la_paras['driver_id']);
        return $this->format_success_ret($ret);
    }
    public function get_lastlog(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('LogCache');
        $ret = $sp->get_last($la_paras['log_type']);
        return $this->format_success_ret($ret);
    }
    public function get_unicache(Request $request){
        $userObj = null;
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_model_cache_service')->get('UniCache');
        $ret = $sp->get($la_paras['log_type']);
        return $this->format_success_ret($ret);
    }

}
