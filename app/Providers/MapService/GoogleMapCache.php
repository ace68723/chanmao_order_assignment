<?php
namespace App\Providers\MapService;

use Log;
use S2;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class GoogleMapCache{
    const PREFIX = "cmoa:googlemapcache:";
    const KEEP_ALIVE_SEC = 5*60;

    static public function single_query($start_loc, $end_loc) {
        $key_prefix = self::PREFIX . "pair:";
        $cells = CacheMap::ExtLocToCells($start_loc, $end_loc);
        $result = Redis::get($key_prefix.CacheMap::cellsToToken($cells));
        if (empty($result)) return null;
        return json_decode($result, true);
    }
    static public function update($dudi_mat) {
        $key_prefix = self::PREFIX . "pair:";
        foreach ($dudi_mat as $start_loc=>$rows) {
            foreach($rows as $end_loc=>$elem) {
                if ($start_loc == $end_loc) continue;
                $cells = CacheMap::ExtLocToCells($start_loc, $end_loc);
                $pairkey = $key_prefix.CacheMap::cellsToToken($cells);
                Redis::setex($pairkey, self::KEEP_ALIVE_SEC, json_encode($elem));
            }
        }
    }
}
