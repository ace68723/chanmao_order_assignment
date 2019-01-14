<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->group(['prefix'=>'api/v1/real_time_map', 'middleware'=>'auth:custom_token_cm'], function ($router)
{
    foreach(['get_drivers'] as $api_name) {
        $router->post('/'.$api_name.'/', ['uses'=>'DebugController@'.$api_name]);
    }
});
$router->group(['prefix'=>'api/v1/schedule', 'middleware'=>'auth:custom_token'], function ($router)
//$router->group(['prefix'=>'api/v1/web','middleware'=>['throttle:60,1','auth:custom_api']], function ($router)
{
    $api_names = [
        'reload','run',
    ];
    foreach($api_names as $api_name) {
        $router->post('/'.$api_name.'/', ['uses'=>'ScheduleController@'.$api_name]);
    }
    $api_names = [
        'get_drivers', 'get_orders', 'get_schedule', 'get_log', 'get_unicache',
        'test_map', 'learn_map', 'get_map_caseId', 'get_dist_mat',
        'calc_heat_map','get_heat_map','reset_heat_map',
        'modify_driver', 'get_driver_modifiers', 'get_areas',
        'get_drivers_info', 'debug_call','single_query','rr_dlexp_area',
    ];
    foreach($api_names as $api_name) {
        $router->post('/'.$api_name.'/', ['uses'=>'DebugController@'.$api_name]);
    }
});
//$router->get('api/v1/web/api_doc/', ['uses'=>'PubAPIController@api_doc_md']);

