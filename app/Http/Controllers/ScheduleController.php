<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Http\Request;
use App\Exceptions\CmException;


/** should be dispatch controller
 */
class ScheduleController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->consts['REQUEST_PARAS']['reload'] = [
            'area'=>[
                'checker'=>['is_int',],
                'required'=>true,
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

        if (!$this->check_api_def())
            throw new CmException('SYSTEM_ERROR', "ERROR SETTING IN API SCHEMA");
    }

    public function reload(Request $request){
        $userObj = null;//$request->user('custom_token');
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_schedule_service');
        $ret = $sp->reload();
        return $this->format_success_ret($ret);
    }
    public function get_schedule(Request $request){
        $userObj = null;//$request->user('custom_token');
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_schedule_service');
        $ret = $sp->get_schedule($la_paras);
        return $this->format_success_ret($ret);
    }

}
