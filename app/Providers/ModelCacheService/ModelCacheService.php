<?php
namespace App\Providers\ModelCacheService;

use Log;
use App\Exceptions\CmException;

class ModelCacheService{

    const ROOT_PREFIX = "cmoa:";

    static public function get($name) {
        $clsName = __NAMESPACE__.'\\'.$name;
        return new $clsName(self::ROOT_PREFIX);
    }
}
