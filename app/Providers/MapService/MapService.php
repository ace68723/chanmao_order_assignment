<?php
namespace App\Providers\MapService;

use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

class MapService{

    public $consts;
    public $data;
    public function __construct()
    {
        $this->consts = array();
        $this->consts['MAXNLOCATIONS'] = 100;
        $this->consts['PICKUP_SEC'] = 60*5; //5 min to hand over to customer
        $this->consts['TIMEZONE'] = 'America/Toronto';
        $this->consts['PPTIME'] = [
            'default'=>60*20,
            '< 10' => 60*10,
            '20' => 60*20,
            '30' => 60*30,
            '> 40' => 60*50,
        ];
        $this->consts['AREA'] = [
            1=>'SC',
            2=>'NY',
            3=>'RH',
            4=>'MH',
            5=>'MH',
            6=>'DT',
            10=>'MI',
        ];
    }

    public function map_service_get_dist($drivers, $tasks, &$locations) {
        Log::debug("processed drivers:".json_encode($drivers));
        Log::debug("processed tasks:".json_encode($tasks));
        Log::debug("processed locations:".json_encode($locations));
        return [];
    }
}
