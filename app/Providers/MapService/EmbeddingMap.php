<?php
namespace App\Providers\MapService;

use Log;
use S2;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CmException;

class EmbeddingMap{
    const PREFIX = "cmoa:mapemb:";
    const DIM = 7;
    const WEIGHTS = [1, 0.7, 0.5, 0.4, 0.3, 0.2, 0.1]; //of length self::DIM

    static public function dotprod($startX, $endY) {
        $sum = 0;
        for ($i=0; $i<self::DIM; $i++) {
            $sum += self::WEIGHTS[$i]*abs($startX[$i]-$endY[$i]);
        }
        return $sum;
    }
}
