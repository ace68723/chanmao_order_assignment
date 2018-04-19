<?php

function getIDMaps($arr, $id_name) {
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
function prepare_input($input) {
    $drArr = [];
    $tkArr = [];
    $tIdMap = getIDMaps($input['tasks'], 'tid');
    $dIdMap = getIDMaps($input['drivers'],'did');
    $locIdMap = getIDMaps($input['locations'],'abid');
    foreach ($input['drivers'] as $driver) {
        $drArr[] = [
            'did'=>$dIdMap[$driver['did']],
            'availableTime'=>$driver['availableTime'],
            'offTime'=>$driver['offTime'],
            'location'=>$locIdMap[$driver['location']],
            'maxNOrder'=>$driver['maxNOrder']??5,
            'distFactor'=>$driver['distFactor']??1,
            'pickFactor'=>$driver['pickFactor']??1,
            'deliFactor'=>$driver['deliFactor']??1,
        ];
    }
    foreach ($input['tasks'] as $task) {
        $did = ($dIdMap[$task["did"]??null])??-1;
        $prevTask = $tIdMap[$task["prevTask"]??null]??-1;
        $nextTask = $tIdMap[$task["nextTask"]??null]??-1;
        $tkArr[] = [
            'tid'=>$tIdMap[$task['tid']],
            'location' => $locIdMap[$task["location"]],
            'deadline' => $task["deadline"],
            'readyTime' => $task["readyTime"],
            'execTime' => $task["execTime"],
            'did'=> $did, 
            'prevTask'=>$prevTask,
            'nextTask'=>$nextTask,
        ];
    }
    $input = [
        "drivers"=>$drArr,
        "tasks"=>$tkArr,
        "distMat"=>$input['distMat'],
        "nLocations"=>count($input['locations']),
    ];
    //var_dump($simple);
    return $input;
}
?>
