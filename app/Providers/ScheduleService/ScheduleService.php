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
        $this->consts['DEADLINE_SEC'] = 60*80; //promised time, from initiate to deliver: 1h20min
        $this->consts['HANDOVER_SEC'] = 60*7; //7 min to hand over to customer
        $this->consts['PICKUP_SEC'] = 60*5; //5 min to hand over to customer
        $this->consts['REWARD_ONETIME'] = ['D'=>100,'P'=>0];
        $this->consts['REWARD_PERSEC'] = ['D'=>0.1,'P'=>0];
        $this->consts['PENALTY_ONETIME'] = ['D'=>0,'P'=>0];
        $this->consts['PENALTY_PERSEC'] = ['D'=>0.1,'P'=>0.01];
        $this->consts['DRIVER_LIVE_SEC'] = 4*3600; //for how long the driver's location stays usable
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

    // check consistency, remove unnecessary tasks/driver/locations, convert to the input for module
    private function preprocess($orders, $drivers, $areaId) {
        $curTime = time();
        $tasks = [];
        $locations = [];
        $workload = [];
        $driverArea = [];
        foreach($drivers as $driver) { $driverArea[$driver['driver_id']] = $driver['areaId'];}
        foreach($orders as $order) {
            $order = (array)$order;
            if ($order['area'] != $areaId) {
                if (empty($order['driver_id'])) continue;
                $driver_id = $order['driver_id'];
                if (!isset($driverArea[$driver_id]) || $driverArea[$driver_id] != $areaId)
                    continue;
            }
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
            if ($curTime >= $driver['valid_to']) {
                Log::debug("ignore driver ".$driver['driver_id']." because of off work");
                continue;
            }
            //if ($curTime < $driver['valid_from']) continue; //??
            $thisworkload = ($workload[$driver['driver_id']]??0);
            $otherWorkload = 0;
            if ($driver['workload'] != $thisworkload) {
                Log::debug('driver '.$driver['driver_id'].' workload mismatch:'.$driver['workload'].' find orders:'.$thisworkload);
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
        $curTasks = $this->get_curTasks($available_drivers);
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
    public function reload($areaId) {
        $orderCache = app()->make('cmoa_model_cache_service')->get('OrderCache');
        $driverCache = app()->make('cmoa_model_cache_service')->get('DriverCache');
        $orders = $orderCache->get_orders();
        $drivers = $driverCache->get_drivers($areaId);
        list($driver_dict, $task_dict, $basic_loc_dict, $curTasks) = $this->preprocess($orders, $drivers, $areaId);
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
    public function run($areaId, $force_redo = false) {
        $input = $this->reload($areaId);
        $scheCache = app()->make('cmoa_model_cache_service')->get('ScheduleCache');
        $uniCache = app()->make('cmoa_model_cache_service')->get('UniCache');
        $new_input_sign = $this->calc_input_sign($input);
        if ($force_redo || $uniCache->get('signScheInput') != $new_input_sign) {
            $map_sp = app()->make('cmoa_map_service');
            $task_dict = $input['task_dict'];
            $driver_dict = $input['driver_dict'];
            $curTasks = $input['curTasks'];
            $loc_dict = $input['basic_loc_dict']; //enriched in the get_dist_mat call
            $dist_mat = $map_sp->get_dist_mat($loc_dict);
            $schedules = $this->ext_wrapper($task_dict, $driver_dict, $loc_dict, $dist_mat, $curTasks);
            $scheCache->set_schedules($schedules, time());
            $uniCache->set('interData', array_only($input, ['task_dict','driver_dict']));
            $uniCache->set('signScheInput', $new_input_sign);
        }
        else {
            $schedules = $scheCache->get_schedules(null, $areaId);
        }
        return $schedules;
    }
    public function ext_wrapper($task_dict, $driver_dict, $loc_dict, $dist_mat, $curTasks) {
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
            'nLocations'=>$nLocations,
        ];
        $logCache = app()->make('cmoa_model_cache_service')->get('LogCache');
        $logCache->log('cmoa_ext_input', $input);
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
                'passback_loc'=>$driver['passback_loc'],
                'tasks'=>[]];
            if (!empty($curTasks[$driver['driver_id']]['tasks']))
                $newDriverItem['tasks'] = $curTasks[$driver['driver_id']]['tasks'];
            if (!empty($sche['tids'])) {
                foreach($sche['tids'] as $i=>$tid) {
                    $newTaskItem = [
                        'task_id'=>$task_arr[$tid]['task_id'],
                        'completeTime'=>$sche['completeTime'][$i],
                        'locId'=>$task_arr[$tid]['locId'],
                    ];
                    $newTaskItem['location'] = array_only($loc_dict[$newTaskItem['locId']],
                        ['lat','lng','addr','adjustLatLng','gridId']);
                    $newDriverItem['tasks'][] = $newTaskItem;
                }
            }
            $schedules[$driver['driver_id']] = $newDriverItem;
        }
        return $schedules;
    }
}
