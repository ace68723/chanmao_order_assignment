<?php
namespace App\Providers\ScheduleService;

use Log;
use App\Exceptions\CmException;

class AreaUtil{

    const AREA_RECT = [
            1 => ['lat'=>[43.700, 43.840], 'lng'=>[-79.350, -79.150]], //1 for SC
        ];
    const USER_SLACK_LAT = 0.01; //0.01 lat = about 1km slackness for customer
    const USER_SLACK_LNG = 0.014;
    static private function is_in_rect($lat, $lng, $rect, $slack_lat=0, $slack_lng=0) {
        return ($lat+$slack_lat >= $rect['lat'][0] && $lat-$slack_lat <= $rect['lat'][1]
            && $lng+$slack_lng >= $rect['lng'][0] && $lng-$slack_lng <= $rect['lng'][1]);
    }
    static public function area_filter($orders, $drivers, $areaId) {
        $inAreaOrders = [];
        $driverArea = [];
        foreach($drivers as $driver) { $driverArea[$driver['driver_id']] = $driver['areaId'];}
        foreach($orders as $order) {
            if (!empty($order['driver_id'])) {
                $driver_id = $order['driver_id'];
                if (!isset($driverArea[$driver_id]) || $driverArea[$driver_id] != $areaId) continue;
            }
            else {
                $rect = self::AREA_RECT[$areaId] ?? null;
                if (empty($rect)) continue;
                if (!self::is_in_rect($order['rr_lat'],$order['rr_lng'],$rect) ||
                    !self::is_in_rect($order['user_lat'],$order['user_lng'],$rect,
                        self::USER_SLACK_LAT,self::USER_SLACK_LNG)
                    )
                    continue;
            }
            $inAreaOrders[] = $order;
        }
        return $inAreaOrders;
    }
}
