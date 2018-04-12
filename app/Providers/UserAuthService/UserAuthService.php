<?php
namespace App\Providers\UserAuthService;

use Log;
use \Firebase\JWT\JWT;
use Exception;
use App\Exceptions\CmException;

class UserAuthService{

    public $consts;
    public function __construct() {
        $this->consts['token_expire_sec'] = 7*24*60*60;
    }

    public function check_token($token, $b_for_mgt=false) {
        /*
        $a = debug_backtrace();
        $b = json_encode($a, JSON_PARTIAL_OUTPUT_ON_ERROR, 2);
        Log::DEBUG($b);
         */
        if (empty($token)) {
            throw new CmException('INVALID_TOKEN');
        }
        try {
            $token_info = JWT::decode($token, env('APP_KEY'), array('HS256'));
        }
        catch (Exception $e) {
            throw new CmException('INVALID_TOKEN');
        }
        if (empty($token_info->uid)
        )
        {
            throw new CmException('INVALID_TOKEN');
        }
        return $token_info;
    }
}
