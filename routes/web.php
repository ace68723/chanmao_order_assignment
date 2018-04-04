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

/*
$router->group(['prefix'=>'api/v1/web','middleware'=>['throttle:60,1','auth:custom_api']], function ($router)
{
    $api_names = [
        'create_order',
        'check_order_status',
        'get_exchange_rate',
        'get_txn_by_id',
    ];
    foreach($api_names as $api_name) {
        $router->post('/'.$api_name.'/', ['uses'=>'PubAPIController@'.$api_name]);
    }
});
 */
//$router->get('api/v1/web/api_doc/', ['uses'=>'PubAPIController@api_doc_md']);
