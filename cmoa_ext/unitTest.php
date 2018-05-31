<?php

$module_name = 'cmoa-ext';
if (!extension_loaded($module_name)) {
	$str = "Module $module_name is not compiled into PHP";
	die("$str\n");
}
$input = json_decode(file_get_contents("data.json"),true);
$ret = cmoa_schedule($input);
var_dump($ret);

