<?php

namespace App\Providers;

use App\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\GenericUser;
use Log;
use App\Exceptions\CmException;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        // Here you may define how you wish users to be authenticated for your Lumen
        // application. The callback which receives the incoming request instance
        // should return either a User instance or null. You're free to obtain
        // the User instance via an API token or any other method necessary.

        $this->app['auth']->viaRequest('custom_token', function ($request) {
            $sp = app()->make('user_auth_service');
            $token_info = $sp->check_token($request->header('Auth-Token'), false);
            //$token_info = $sp->decode_token($request->header('Auth-Token'));
            return new GenericUser([
                'uid'=>$token_info->uid,
                'role'=>$token_info->role,
                'username'=>$token_info->username,
                'account_id'=>$token_info->account_id,
            ]);
        });
    }
}
