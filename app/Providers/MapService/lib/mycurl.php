<?php

function do_curl($url, $method, $timeout=30, $payload=null)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    if (strtoupper($method) == 'POST') {
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    }
    $data = curl_exec($ch);
    if($data !== false){
        //$ret_code = curl_getinfo($ch); if ($ret_code['http_code'] != 200) throw new Exception();
        curl_close($ch);
        $data = json_decode($data, true);
    }
    else {
        $error = curl_errno($ch);
        curl_close($ch);
        throw new Exception("curl error no: $error");
    }
    return $data;
}
