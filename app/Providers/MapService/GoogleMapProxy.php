<?php
namespace App\Providers\MapService;

use Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use App\Providers\MapService\GoogleMapFinalBytes\GoogleDistanceMatrix;
use App\Providers\MapService\GoogleMapFinalBytes\Response\Element;
use App\Exceptions\CmException;

class GoogleMapProxy{
    const  DAILY_QUOTA = 2500;

    static public function get_quota() : int 
    {
        $quota = Redis::get("cmoa:googlemap:quota");
        if (is_null($quota)) {
            $quota = self::DAILY_QUOTA;
            Redis::setex("cmoa:googlemap:quota", 7*24*3600, $quota);
        }
        else {
            $quota = intval($quota);
        }
        Log::debug("got google map quota:".$quota);
        return $quota;
    }
    static public function get_dist_mat($origin_loc_arr, $end_loc_arr) {
        $dist_mat_dict = [];
        $nElem = count($origin_loc_arr) * count($end_loc_arr);
        $quota = self::get_quota();
        if ($nElem>$quota) {
            Log::warn('google_map get dist mat fail because of quota limit');
            return $dist_mat_dict;
        }
        Redis::decrby("cmoa:googlemap:quota", $nElem);
        try {
            $sp = new GoogleDistanceMatrix(env('GOOGLE_API_KEY'));
            foreach($origin_loc_arr as $loc) {
                $sp->addOrigin($loc);
            }
            foreach($end_loc_arr as $loc) {
                $sp->addDestination($loc);
            }
            $respObj = $sp->sendRequest();
            foreach($respObj->getRows() as $i=>$row) {
                foreach($row->getElements() as $j=>$element) {
                    if ($element->getStatus() == Element::STATUS_OK) {
                        if ($origin_loc_arr[$i] == $end_loc_arr[$j]) continue;
                        $dist_mat_dict[$origin_loc_arr[$i]][$end_loc_arr[$j]] = [
                            $element->getDuration()->getValue(),
                            $element->getDistance()->getValue(),
                        ];
                    }
                }
            }
        }
        catch(\Exception $e) {
            Log::debug('google map api error:'.$e->getMessage());
        }
        //Log::debug("googlemap return:".json_encode($dist_mat_dict));
        return $dist_mat_dict;
    }
}
