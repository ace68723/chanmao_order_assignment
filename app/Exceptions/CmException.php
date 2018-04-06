<?php

namespace App\Exceptions;

use Exception;

class CmException extends Exception
{
    protected $inner_code;
    protected $context;
    const INVALID_TOKEN         = 10001;
    const PERMISSION_DENIED     = 10004;
    const TOKEN_EXPIRE          = 10010;
    const TOKEN_KICKED          = 10011;
    const LOGIN_FAIL            = 10012;
    const TOO_MANY_ATTEMPTS     = 10013;
    const SIGN_ERROR            = 10014;
    const INVALID_PARAMETER     = 30002;
    const INVALID_PARAMETER_NUM = 30000;

    /*business errors for mcfpay
    const CHANNEL_NOT_ACTIVATED = 40020;
    const CHANNEL_NOT_SUPPORTED = 40021;
    const SYSTEM_ERROR          = 40023;
    const NOT_FOUND             = 40024;
    const QUERY_LATER           = 40025;
    const WX_ERROR_VALIDATION   = 40026;
    const WX_ERROR_RETRY        = 40026;
    const WX_ERROR_BIZ          = 40026;
    const WX_ERROR_RAW          = 40026;
    const AL_ERROR_VALIDATION   = 40027;
    const AL_ERROR_RETRY        = 40027;
    const AL_ERROR_BIZ          = 40027;
    const AL_ERROR_RAW          = 40027;
    const NOT_FOUND_REMOTE      = 40040;
     */

    public function __construct(string $const_name, $context = null) {
        parent::__construct($const_name, 1);
        if (defined('self::'.$const_name)) {
            $this->inner_code = constant('self::'.$const_name);
        }
        else {
        }
        $this->context = $context;
    }

    public function getInnerCode() {
        return $this->inner_code;
    }
    public function getContext() {
        return $this->context;
    }
}
