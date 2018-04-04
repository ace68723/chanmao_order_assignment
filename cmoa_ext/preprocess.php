<?php

$drivers = [
    [
    "did"=> "23",
    "availableTime"=> 0,
    "location"=> "Chanmao Inc.",
    "offTime"=> 120,
    ],
    [
    "did"=> "24",
    "availableTime"=> 0,
    "location"=> "Chanmao Inc.",
    "offTime"=> 123,
    ],
];
$tasks = 
[ 
    [ "tid"=>"order1-fetch", "location"=>"Chanmao Inc.", "deadline"=>1432519683235,
    "readyTime"=>10, "execTime"=>5,
    "did"=>"", "prevTask"=>null, "nextTask"=>"order1-deliver"], 
    [ "tid"=>"order1-deliver", "location"=>"Client1", "deadline"=>1432519683235,
    "readyTime"=>10, "execTime"=>5,
    "did"=>"", "prevTask"=>"order1-fetch" ], 
    [ "tid"=>"order2-deliver", "location"=>"Client2", "deadline"=>1432519683235,
    "readyTime"=>10, "execTime"=>5,
    "did"=>"", "prevTask"=>null ] 
];
$paths = 
[ 
[ "start"=>"Chanmao Inc.", "end"=>"Client1", "time"=>10],
[ "end"=>"Chanmao Inc.", "start"=>"Client1", "time"=>10],
[ "start"=>"Chanmao Inc.", "end"=>"Client2", "time"=>20],
[ "end"=>"Chanmao Inc.", "start"=>"Client2", "time"=>20],
[ "start"=>"Client1", "end"=>"Client2", "time"=>25],
[ "end"=>"Client1", "start"=>"Client2", "time"=>25]
];
$locations = [
    ['abid'=>'Chanmao Inc.'],
    ['abid'=>'Client1'],
    ['abid'=>'Client2'],
];
$distMat = [
    [ 0, 10, 20],
    [ 10, 0, 25],
    [ 20, 25, 0],
];
$input = [
    "drivers"=>$drivers,
    "tasks"=>$tasks,
    "locations"=>$locations,
    "distMat"=>$distMat,
];

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
function schedule($input) {
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
    $simple = [
        "drivers"=>$drArr,
        "tasks"=>$tkArr,
        "distMat"=>$input['distMat'],
        "nLocations"=>count($input['locations']),
    ];
    //var_dump($simple);
    $ret = cmoa_schedule($simple);
    var_dump($ret);
}
schedule($input);
?>
