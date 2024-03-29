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
        $this->consts['REQUEST_PARAS']['run'] = [
            'area'=>[
                'checker'=>['is_int',],
                'required'=>true,
            ],
        ];

        if (!$this->check_api_def())
            throw new CmException('SYSTEM_ERROR', "ERROR SETTING IN API SCHEMA");
    }

    public function run(Request $request){
        $userObj = null;//$request->user('custom_token');
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_schedule_service');
        $ret = $sp->run_step($la_paras['area']);
        return $this->format_success_ret($ret);
    }
    public function reload(Request $request){
        $userObj = null;//$request->user('custom_token');
        $la_paras = $this->parse_parameters($request, __FUNCTION__, $userObj);
        $sp = app()->make('cmoa_schedule_service');
        $ret = $sp->reload($la_paras['area']);
        return $this->format_success_ret($ret);
    }

}
