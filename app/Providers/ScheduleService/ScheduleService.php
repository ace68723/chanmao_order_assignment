<?php
namespace App\Providers\ScheduleService;

use Log;
use Illuminate\Http\Request;
use App\Exceptions\CmException;

class ScheduleService{

    public $consts;
    public function __construct()
    {
        $this->consts = array();
        $this->consts['MAXNLOCATIONS'] = 100;
        $this->consts['DEADLINE_SEC'] = 90*60; //expected complete time, from initiate to deliver
        $this->consts['EXT_DEADLINE'] = [
            'DIST_THRESHOLD_GRID'=> 30, //according to current setting 1 grid ~ 100m
            'SEC_PER_GRID'=> 12,
        ];
        $this->consts['HANDOVER_SEC'] = 60*4; //1~5 min for house, 10 min for condo
        $this->consts['PICKUP_SEC'] = 60*4; //4 min to pickup
        $this->consts['REWARD_ONETIME'] = ['D'=>0,'P'=>0]; // gap value in the evaluation function
        $this->consts['REWARD_PERSEC'] = ['D'=>1.0,'P'=>0];
        $this->consts['PENALTY_ONETIME'] = ['D'=>0,'P'=>0]; //maybe deprecate this
        $this->consts['PENALTY_PERSEC'] = ['D'=>3.0,'P'=>0.01];
        $this->consts['DRIVER_LIVE_SEC'] = 4*3600; //for how long the driver's location stays usable
        $this->consts['DRIVER_ADVANCE_SEC'] = 60*15; //consider driver available before its scheduled time
        $this->consts['PPTIME'] = [
            'default'=>60*20,
            '< 10' => 60*10,
            '20' => 60*20,
            '30' => 60*30,
            '> 40' => 60*50,
        ];
        $this->consts['EXT_ERROR'] = [
            1 => 'E_EXCEED_MAXN',
            7 => 'E_WRONG_INDEX',
            8 => 'E_CONFLICT_SETTING',
        ];
    }
    public function get_areas() {
        return AreaUtil::AREA_RECT;
    }

    // check consistency, remove unnecessary tasks/driver/locations, convert to the input for module
    private function preprocess($orders, $drivers) {
        $curTime = time();
        $tasks = [];
        $locations = [];
        $workload = [];
        foreach($orders as $order) {
            $prevTask = null;
            if (!empty($order['driver_id'])) {
                //Log::debug("order ".$order['oid']." assigned to driver ".$order['driver_id']);
                $workload[$order['driver_id']] = 1+($workload[$order['driver_id']]??0);
            }
            if ($order['status'] == 30) {
                if ($order['pickup'] <= 0) {
                    Log::debug("empty pickup time for order ".$order['oid']);
                }
            }
            else {
                if ($order['pickup'] > 0) {
                    Log::debug("non-empty pickup time for order ".$order['oid']. " with status ". $order['status']);
                }
                $pptime = $this->consts['PPTIME'][$order['pptime']]?? $this->consts['PPTIME']['default'];
                $locId = 'rr-'.$order['rid'];
                $locations[$locId] = [
                    'lat'=>$order['rr_lat'],'lng'=>$order['rr_lng'],'addr'=>$order['rr_addr'],
                ];
                $task_id = $order['oid']."P";
                $tasks[$task_id] = [
                    'oid'=>$order['oid'],
                    'task_id'=>$task_id,
                    'locId'=>$locId,
                    'deadline'=>$order['rraction']+$pptime,
                    'readyTime'=>$order['rraction']+$pptime,
                    'execTime'=>$this->consts['PICKUP_SEC'],
                    'driver_id'=>empty($order['driver_id'])?null:$order['driver_id'],
                    'prevTask'=>null,
                    'nextTask'=>$order['oid']."D",
                    'rwdOneTime'=>$this->consts['REWARD_ONETIME']['P'],
                    'pnlOneTime'=>$this->consts['PENALTY_ONETIME']['P'],
                    'rwdPerSec'=>$this->consts['REWARD_PERSEC']['P'],
                    'pnlPerSec'=>$this->consts['PENALTY_PERSEC']['P'],
                ];
                $prevTask = $task_id;
            }
            $locId = 'ua-'.$order['uaid'];
            $locations[$locId] = [
                'lat'=>$order['user_lat'],'lng'=>$order['user_lng'],'addr'=>$order['user_addr'],
            ];
            $task_id = $order['oid']."D";
            $tasks[$task_id] = [
                'oid'=>$order['oid'],
                'task_id'=>$task_id,
                'locId'=>$locId,
                'deadline'=>$order['initiate']+$this->consts['DEADLINE_SEC'],
                'readyTime'=>0,
                'execTime'=>$this->consts['HANDOVER_SEC'],
                'driver_id'=>empty($order['driver_id'])?null:$order['driver_id'],
                'prevTask'=>$prevTask,
                'nextTask'=>null,
                'rwdOneTime'=>$this->consts['REWARD_ONETIME']['D'],
                'pnlOneTime'=>$this->consts['PENALTY_ONETIME']['D'],
                'rwdPerSec'=>$this->consts['REWARD_PERSEC']['D'],
                'pnlPerSec'=>$this->consts['PENALTY_PERSEC']['D'],
            ];
        }
        $available_drivers = [];
        foreach($drivers as &$driver) {
            $driver['valid_from'] = intval($driver['valid_from']);
            $driver['valid_to'] = intval($driver['valid_to']);
            $driver['timestamp'] = intval($driver['timestamp']);
            if ($driver['timestamp'] == 1) continue;
            if ($curTime - $driver['timestamp'] > $this->consts['DRIVER_LIVE_SEC']) {
                Log::debug("ignore driver ".$driver['driver_id']." because of lost position");
                continue;
            }
            if ($curTime >= $driver['valid_to']
                || $curTime < $driver['valid_from']-$this->consts['DRIVER_ADVANCE_SEC'])
            {
                Log::debug("ignore driver ".$driver['driver_id']." because of off work");
                continue;
            }
            $thisworkload = ($workload[$driver['driver_id']]??0);
            $otherWorkload = 0;
            if ($driver['workload'] != $thisworkload) {
                Log::warn('driver '.$driver['driver_id'].' workload mismatch:'.$driver['workload'].' find orders:'.$thisworkload);
                $otherWorkload = max(0, $driver['workload']-$thisworkload);
            }
            $maxNOrder = ($driver['maxNOrder']??5)-$otherWorkload;
            if ($maxNOrder <= 0) {
                Log::debug("ignore driver ".$driver['driver_id']." because of workload");
                continue;
            }
            $locId = 'dr-'.$driver['driver_id'];
            $locations[$locId] = [
                'lat'=>floatval($driver['lat']),'lng'=>floatval($driver['lng']),'addr'=>null,
            ];
            $available_drivers[$driver['driver_id']] = [
                'driver_id'=>$driver['driver_id'],
                'areaId'=>$driver['areaId'],
                'passback_loc'=>[$driver['lat'],$driver['lng'],],
                'availableTime'=>max($curTime,$driver['valid_from']),
                'offTime'=>$driver['valid_to'],
                'locId'=>$locId,
                'maxNOrder'=>$maxNOrder,
                'distFactor'=>$driver['distFactor']??1.0,
                'pickFactor'=>$driver['pickFactor']??1.0,
                'deliFactor'=>$driver['deliFactor']??1.0,
            ];
        }
        //$curTasks = $this->get_curTasks($available_drivers);
        $curTasks = []; //should not fix curTasks if this is not the driver's choice
        $locIds = []; $task_ids = []; $fixed_task_ids = []; $temp = [];
        foreach($curTasks as $driver_id=>$fixedSche) {
            if (!isset($available_drivers[$driver_id])) {
                $temp[] = $driver_id;
                continue;
            }
            $task_changed = false;
            foreach($fixedSche['tasks'] as $fixedTask) {
                if (!isset($tasks[$fixedTask['task_id']])) {
                    $task_changed = true;
                    break;
                }
            }
            if ($task_changed) {
                $temp[] = $driver_id;
                continue;
            }
            foreach($fixedSche['tasks'] as $fixedTask) {
                $fixed_task_ids[$fixedTask['task_id']] = 1;
                $available_drivers[$driver_id]['availableTime'] = $fixedTask['completeTime'];
                $available_drivers[$driver_id]['locId'] = $fixedTask['locId'];
                if (!isset($locations[$fixedTask['locId']])) {
                    throw new CmException('SYSTEM_ERROR', 'unknown location introduced by curTask');
                    //$locations[$fixedTask['locId']] = array_only($fixedTask['location'], ['lat','lng','addr']);
                    //Log::debug("add location ".$fixedTask['locId']." from fixed task ".$fixedTask['task_id']);
                }
            }
        }
        foreach($temp as $driver_id) unset($curTasks[$driver_id]);
        foreach($tasks as $task) {
            if (isset($fixed_task_ids[$task['task_id']])) {
                //Log::debug("ignore fixed task ".$task['task_id']);
                continue;
            }
            if (!empty($task['driver_id']) && !isset($available_drivers[$task['driver_id']])) {
                //Log::debug("ignore task".$task['task_id']." assigned to unavailable drivers ".$task['driver_id']);
                continue;
            }
            $locIds[$task['locId']] = 1;
            $task_ids[$task['task_id']] = 1;
        }
        foreach($available_drivers as $driver) {
            $locIds[$driver['locId']] = 1;
        }
        $tasks = array_where($tasks, function($value, $key) use($task_ids) {return isset($task_ids[$key]);});
        $locations = array_where($locations, function($value, $key) use($locIds) {return isset($locIds[$key]);});
        return [$available_drivers, $tasks, $locations, $curTasks];
    }
    private function get_curTasks() {
        $scheCache = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        $prevSche = $scheCache->get_schedules();
        foreach($prevSche as $driver_id=>$sche) if (count($sche['tasks'])>1) {
            $prevSche[$driver_id]['tasks'] = array_slice($sche['tasks'],0,1);
        }
        return $prevSche;
    }
    private function keyToIdx($arr, $id_name) {
        $idToInt = [];
        $i = 0;
        foreach ($arr as $item) {
            if (isset($idToInt[$item[$id_name]])) {
                //duplicate id
                return null;
            }
            $idToInt[$item[$id_name]] = $i;
            $i += 1;
        }
        return $idToInt;
    }
    private function assign_test_orders($orders) {
        $test_orders = [];
        $normal_orders = [];
        foreach ($orders as $order) {
            if ($order['rid'] == 5)
                $test_orders[] = $order;
            else
                $normal_orders[] = $order;
        }
        if (empty($test_orders)) return $normal_orders;
        $schedules = [];
        $driver_id = 6;
        $unassigned_orders = [];
        $newDriverItem = [
            'driver_id'=>$driver_id,
            'areaId'=>1,
            'score'=>1,
            'passback_loc'=>['43','-79'],
            'meters_after_fixtask'=>0,
            'tasks'=>[]
        ];
        foreach($test_orders as $order) {
            if (!in_array($order['status'], [20,30])) continue;
            if ($order['status'] != 30) {
                $unassigned_orders[$order['oid']] = $driver_id;
                $newDriverItem['tasks'][] = [
                    'task_id'=>$order['oid'].'P',
                    'oid'=>$order['oid'],
                    'deadline'=>1,
                    'completeTime'=>1,
                ];
            }
            $newDriverItem['tasks'][] = [
                'task_id'=>$order['oid'].'D',
                'oid'=>$order['oid'],
                'deadline'=>1,
                'completeTime'=>1,
            ];
        }
        $schedules[$driver_id] = $newDriverItem;
        $scheCache = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        $scheCache->set_schedules($schedules, time());
        $this->apply_assigns($unassigned_orders);
        return $normal_orders;
    }
    public function reload($areaId, $orders=null, $drivers=null) {
        if (is_null($orders)) {
            $orderCache = app()->make('cmoa_model_cache_service')->get('OrderCache');
            $allow_test_orders = true;
            $orders = $orderCache->get_orders($allow_test_orders);
            if ($allow_test_orders) $orders = $this->assign_test_orders($orders);
        }
        if (is_null($drivers)) {
            $driverCache = app()->make('cmoa_model_cache_service')->get('DriverCache');
            $drivers = $driverCache->get_drivers($areaId);
        }
        $orders = AreaUtil::area_filter($orders, $drivers, $areaId);
        list($driver_dict, $task_dict, $basic_loc_dict, $curTasks) = $this->preprocess($orders, $drivers);
        Log::debug('preprocess returns '.count($driver_dict). ' drivers, '.
            count($task_dict).' tasks and '.count($basic_loc_dict).' locations');
        return ['task_dict'=>$task_dict, 'driver_dict'=>$driver_dict, 'basic_loc_dict'=>$basic_loc_dict,
            'curTasks'=>$curTasks];
    }
    private function calc_input_sign($input) {
        $signStr = '';
        $tasks = $input['task_dict'];
        ksort($tasks);
        foreach($tasks as $v) {
            $signStr .= json_encode(array_only($v,['task_id','driver_id']));
        }
        $drivers = $input['driver_dict'];
        ksort($drivers);
        foreach($drivers as $v) {
            $signStr .= json_encode(array_only($v,['driver_id','locId','maxNOrder']));
        }
        $uniCache = app()->make('cmoa_model_cache_service')->get('UniCache');
        $uniCache->set('cmoa_input_signStr', $signStr);
        return md5($signStr);
    }
    public function learn_map($areaId) {
        $this->run_step($areaId);
        return;
        $input = $this->reload($areaId);
        $task_dict = $input['task_dict'];
        $driver_dict = $input['driver_dict'];
        if (count($task_dict)==0 || count($driver_dict)==0) {
            return;
        }
        $uniCache = app()->make('cmoa_model_cache_service')->get('UniCache');
        $new_input_sign = $this->calc_input_sign($input);
        if ($uniCache->get('signLearnMapInput') != $new_input_sign) {
            $map_sp = app()->make('cmoa_map_service');
            $curTasks = $input['curTasks'];
            $loc_dict = $input['basic_loc_dict']; //will be enriched
            list($origin_loc_arr, $dest_loc_arr) = $map_sp->aggregate_locs($loc_dict);
            foreach($origin_loc_arr as $start_loc)
                foreach($dest_loc_arr as $end_loc)
                    $target_mat[$start_loc][$end_loc] = 1;
            $map_sp->learn_map($target_mat);
            $uniCache->set('signLearnMapInput', $new_input_sign);
        }
        return;
    }
    private function find_unassigned($tasks) {
        $oids = [];
        foreach($tasks as $task) {
            if (empty($task['driver_id'])) {
                $oids[$task['oid']] = -1;
            }
        }
        return $oids;
    }
    private function extract_assigns(&$oids, $schedules) {
        foreach($schedules as $driver_id=>$sche) {
            foreach($sche['tasks'] as $sche_task) if (isset($oids[$sche_task['oid']])) {
                $oids[$sche_task['oid']] = $driver_id;
            }
        }
    }
    public function test() {
        return $this->apply_assigns([]);
    }
    private function apply_assigns($order_assigns) {
        $header = [
            "Authortoken"=>"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1aWQiOiIxMTExOSIsImV4cGlyZWQiOjE1NDE0MDQ4MDAsImV4cGlyZWRfdGltZSI6MTU0MTQwNDgwMCwibGFzdGxvZ2luIjoxNTM1MzkxMTA1fQ.0vDTWQJBzbxKGnDH0XzBapMdnpE-qbI3xQpZQuYg4K8",
        ];
        $curl = app()->make('curl_service');
        $wid_map = ['6'=>'238', '88'=>'102'];
        foreach($order_assigns as $oid=>$driver_id) {
            if (!in_array($driver_id, [6,88])) continue;
            $payload = [
                "oid"=>$oid,
                "task"=>"assign",
                "value"=>$wid_map[$driver_id],
                "comment"=>"testing auto assign"
            ];
            Log::debug('applying assignment:'.$oid.'=>'.$driver_id);
            $result = $curl->do_curl('POST','https://chanmao.ca/index.php?r=MobMonitor/OrderChange', $payload, $header, 30, null, true);
            if (($result['ev_result'] ?? 1) != 0) {
                Log::debug('apply failed:'.json_encode($result));
            }
        }
    }
    public function get_dist_mat(&$loc_dict, $task_dict, $driver_dict) {
        $map_sp = app()->make('cmoa_map_service');
        list($origin_loc_arr, $dest_loc_arr) = $map_sp->aggregate_locs($loc_dict);
        $target_mat = $this->get_target_dist_mat($origin_loc_arr, $dest_loc_arr,
            $loc_dict, $task_dict, $driver_dict);
        list($dist_mat_dict, $mileage_mat_dict) = $map_sp->get_dist_mat($target_mat);
        $dist_mat = []; $meters_mat = [];
        foreach ($origin_loc_arr as $i=>$start_loc)
            foreach ($origin_loc_arr as $j=>$end_loc) {
                $dist_mat[$i][$j] = $dist_mat_dict[$start_loc][$end_loc] ?? -1;
                $meters_mat[$i][$j] = $mileage_mat_dict[$start_loc][$end_loc] ?? -1;
            }
        return [$dist_mat, $meters_mat];
    }
    public function get_target_dist_mat($origin_loc_arr, $dest_loc_arr, $loc_dict, $task_dict, $driver_dict) {
        foreach ($driver_dict as $driver_id=>$driver) {
            $loc_idxs = [];
            $start_idx = $loc_dict[$driver['locId']]['idx'];
            foreach ($task_dict as $task_id=>$task) {
                if (empty($task['driver_id']) || $task['driver_id'] == $driver_id) {
                    $end_idx = $loc_dict[$task['locId']]['idx'];
                    $loc_idxs[$end_idx] = 1;
                    if ($start_idx != $end_idx) {
                        $start_loc = $origin_loc_arr[$start_idx];
                        $end_loc = $origin_loc_arr[$end_idx];
                        $target_mat[$start_loc][$end_loc] = 1;
                    }
                }
            }
            foreach ($loc_idxs as $start_idx=>$elem1) {
                foreach ($loc_idxs as $end_idx=>$elem2) {
                    if ($start_idx != $end_idx) {
                        $start_loc = $origin_loc_arr[$start_idx];
                        $end_loc = $origin_loc_arr[$end_idx];
                        $target_mat[$start_loc][$end_loc] = 1;
                    }
                }
            }
        }
        return $target_mat;
    }
    public function calc_and_update_schedule($input, $force_redo=false) {
        $timer['total'] = -microtime(true);
        $task_dict = $input['task_dict'];
        $driver_dict = $input['driver_dict'];
        $uniCache = app()->make('cmoa_model_cache_service')->get('UniCache');
        $new_input_sign = $this->calc_input_sign($input);
        $schedules = null;
        if ($force_redo || $uniCache->get('signScheInput') != $new_input_sign) {
            $timer['map'] = -microtime(true);
            $curTasks = $input['curTasks'];
            $loc_dict = $input['basic_loc_dict']; //will be enriched in the get_dist_mat call
            list($dist_mat, $meters_mat) = $this->get_dist_mat($loc_dict, $task_dict, $driver_dict);
            $timer['map'] += microtime(true);
            $timer['schedule'] = -microtime(true);
            $schedules = $this->ext_wrapper($task_dict, $driver_dict, $loc_dict, $dist_mat, $meters_mat, $curTasks);
            $timer['schedule'] += microtime(true);
            $scheCache = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
            $scheCache->set_schedules($schedules, time());
            $uniCache->set('signScheInput', $new_input_sign);
        }
        $timer['total'] += microtime(true);
        Log::debug(__FUNCTION__.": timer:".json_encode($timer));
        return $schedules;
    }
    public function run_step($areaId, $orders = null, $drivers = null) {
        $input = $this->reload($areaId, $orders, $drivers);
        $unassigned_orders = $this->find_unassigned($input['task_dict']);
        if (count($input['task_dict'])>0 && count($input['driver_dict'])>0) {
            $this->calc_and_update_schedule($input);
        }
        $scheCache = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        $schedules = $scheCache->get_schedules(null, $areaId);
        $this->extract_assigns($unassigned_orders, $schedules);
        Log::debug(__FUNCTION__.": new_orders:".json_encode($unassigned_orders));
        $this->apply_assigns($unassigned_orders);
        return ['schedules'=>$schedules, 'new_order_assign'=>$unassigned_orders];
    }
    public function ext_wrapper($task_dict, $driver_dict, $loc_dict, $dist_mat, $meters_mat, $curTasks) {
        foreach ($task_dict as $task_id=>$task) {
            $task_dict[$task_id]['location'] = $loc_dict[$task['locId']]['idx'];
        }
        foreach ($driver_dict as $driver_id=>$driver) {
            $driver_dict[$driver_id]['location'] = $loc_dict[$driver['locId']]['idx'];
        }
        $task_arr = array_values($task_dict);
        $driver_arr = array_values($driver_dict);
        $tidMap = $this->keyToIdx($task_arr, 'task_id');
        $didMap = $this->keyToIdx($driver_arr, 'driver_id');
        foreach ($task_arr as $i=>$task) {
            $driver_id = ($didMap[$task["driver_id"]??null])??-1;
            $prevTask = $tidMap[$task["prevTask"]??null]??-1;
            $nextTask = $tidMap[$task["nextTask"]??null]??-1;
            $task_arr[$i]['prevTask'] = $prevTask;
            $task_arr[$i]['nextTask'] = $nextTask;
            $task_arr[$i]['did'] = $driver_id;
            $task_arr[$i]['tid'] = $tidMap[$task['task_id']];
        }
        foreach ($driver_arr as $i=>$driver) {
            $driver_arr[$i]['did'] = $didMap[$driver["driver_id"]];
        }
        $nLocations = count($dist_mat);
        foreach($dist_mat as $row) {
            if ($nLocations != count($row))
                throw new CmException('SYSTEM_ERROR','ill formatted dist matrix');
        }
        $input = [
            'tasks'=>$task_arr,
            'drivers'=>$driver_arr,
            'distMat'=>$dist_mat,
            'meterMat'=>$meters_mat,
            'nLocations'=>$nLocations,
        ];
        $logCache = app()->make('cmoa_model_cache_service')->get('LogCache');
        $logCache->log('cmoa_ext_input', ['input'=>$input,'loc_dict'=>$loc_dict]);
        $ret = cmoa_schedule($input);
        $logCache->log('cmoa_ext_output', $ret);
        if ($ret['ev_error'] != 0) {
            throw new CmException('SYSTEM_ERROR', 'extension_returned_error:'.$ret['ev_error']
                .':'.($this->consts[$ret['ev_error']]??'unknown error code'));
        }
        $schedules = [];
        if (is_null($ret['schedules'])) $ret['schedules'] = [];
        foreach($ret['schedules'] as $sche) {
            $driver = $driver_arr[$sche['did']];
            $newDriverItem = ['driver_id'=>$driver['driver_id'],
                'areaId'=>$driver['areaId'],
                'score'=>$sche['eva'],
                'passback_loc'=>$driver['passback_loc'],
                'meters_after_fixtask'=>$sche['meters']??0,
                'tasks'=>[]];
            if (!empty($curTasks[$driver['driver_id']]['tasks']))
                $newDriverItem['tasks'] = $curTasks[$driver['driver_id']]['tasks'];
            if (!empty($sche['tids'])) {
                foreach($sche['tids'] as $i=>$tid) {
                    $newTaskItem = [
                        'task_id'=>$task_arr[$tid]['task_id'],
                        'oid'=>$task_arr[$tid]['oid'],
                        'deadline'=>$task_arr[$tid]['deadline'],
                        'completeTime'=>$sche['completeTime'][$i],
                        'locId'=>$task_arr[$tid]['locId'],
                    ];
                    $newTaskItem['location'] = array_only($loc_dict[$newTaskItem['locId']],
                        ['lat','lng','addr','adjustLatLng','gridId',]);
                    $newDriverItem['tasks'][] = $newTaskItem;
                }
            }
            $schedules[$driver['driver_id']] = $newDriverItem;
        }
        return $schedules;
    }
}
