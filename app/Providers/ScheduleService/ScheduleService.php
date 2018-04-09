<?php
namespace App\Providers\ScheduleService;

require_once  __DIR__."/lib/mycurl.php";
use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

class ScheduleService{

    public $consts;
    public $data;
    public function __construct()
    {
        $this->consts = array();
        $this->consts['MAXNLOCATIONS'] = 100;
    }

    private function getOrders() {
        return [];
    }
    private function getDrivers() {
        $data = [];
        $res = do_curl('https://www.chanmao.ca/index.php?r=MobAly10/DriverLoc', $data, 'GET');
        return $data;
    }
    public function reload() {
        $orders = $this->getOrders();
        $drivers = $this->getDrivers();
        return ['orders'=>$orders, 'drivers'=>$drivers];
    }
    public function get_today_summary($account_id) {
        $mchinfo = $this->get_merchant_info_by_id($account_id);
        $dt = new \DateTime('now', $this->create_datetimezone($mchinfo['timezone']??null));
        $now = time();
        $start_time = $now - 24*3600;
        $dt->setTimestamp($start_time);
        $data = $this->sp_oc->query_txns_by_time($account_id, $start_time, $now, 1, -1);
        $dict = [];
        foreach($data['recs'] as $txn) {
            $dict[$txn['ref_id']] = $txn;
        }
        $total = $refund = $tips = 0;
        foreach($data['recs'] as $txn) {
            if (!$txn['is_refund']) {
                $total += $txn['txn_fee_in_cent'];
                $tips += $txn['tips'] ?? 0;
            }
            else {
                $refund += $txn['txn_fee_in_cent'];
                $out_trade_no = $txn['txn_link_id'] ?? null;
                if (is_null($out_trade_no) && substr($txn['ref_id'],-2)=='R1') {
                    $out_trade_no = substr($txn['ref_id'], 0, -2);
                    Log::debug(__FUNCTION__.' utilizing generate_txn_ref_id rule. ref_id:'
                        .$txn['ref_id'].' out_trade_no:'.$out_trade_no);
                }
                $tips -= $dict[$out_trade_no]['tips'] ?? 0;
            }
        }
        $amount_due = $total - $refund;
        return [
            'start_time'=>$dt->format('Y-m-d H:i:s'),
            'total'=>$total/100,
            'count'=>count($data['recs']),
            'refund'=>$refund/100,
            'purchase'=>($amount_due-$tips)/100,
            'tips'=>$tips/100,
            'amount_due'=>$amount_due/100,
        ];
    }

}
