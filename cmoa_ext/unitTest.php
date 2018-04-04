<?php
$br = (php_sapi_name() == "cli")? "":"<br>";

$module_name = 'cmoa-ext';
if(!extension_loaded($module_name)) {
	dl("$module_name." . PHP_SHLIB_SUFFIX);
}
if (!extension_loaded($module_name)) {
	$str = "Module $module_name is not compiled into PHP";
	echo "$str\n";
}
$drivers = [
    [
    "did"=> "23",
    "curtask"=> null,
    "available"=> 0,
    "location"=> "Chanmao Inc.",
    "off"=> null,
    ],
    [
    "did"=> "24",
    "curtask"=> null,
    "available"=> 0,
    "location"=> "Chanmao Inc.",
    "off"=> null,
    ],
];

$tasks = 
[ 
[ "tid"=>"order1-fetch", "location"=>"Chanmao Inc.", "deadline"=>1432519683235, "did"=>"", "depend"=>null ], 
[ "tid"=>"order1-deliver", "location"=>"Client1", "deadline"=>1432519683235, "did"=>"", "depend"=>"order1-fetch" ], 
[ "tid"=>"order2-deliver", "location"=>"Client2", "deadline"=>1432519683235, "did"=>"", "depend"=>"" ] 
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

$input = [
    "drivers"=>$drivers,
    "tasks"=>$tasks,
    "paths"=>$paths,
];
$res = cmoa_schedule($input);
var_dump($res);
?>
