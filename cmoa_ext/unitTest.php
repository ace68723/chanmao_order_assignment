<?php
require_once __DIR__."/preprocess.php";

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
$module_name = 'cmoa-ext';
if (!extension_loaded($module_name)) {
	$str = "Module $module_name is not compiled into PHP";
	die("$str\n");
}
$input = prepare_input($input);
$ret = cmoa_schedule($input);
var_dump($ret);

