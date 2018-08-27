<?php
namespace App\Providers;

use Log;
use App\Exceptions\CmException;

class CurlService{

    public function do_curl($method, $url, $payload, $header=null, $second = 30, $pem_file_path=null, $bSilent=false)
    {
        $method = strtoupper($method);
        if (!$bSilent) {
            Log::debug(json_encode($payload)."\n");
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);

        /*
        if(WxPayConfig::CURL_PROXY_HOST != "0.0.0.0" && WxPayConfig::CURL_PROXY_PORT != 0){
        curl_setopt($ch,CURLOPT_PROXY, WxPayConfig::CURL_PROXY_HOST);
        curl_setopt($ch,CURLOPT_PROXYPORT, WxPayConfig::CURL_PROXY_PORT);
        }
         */
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,TRUE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);//严格校验
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, false); //disable header for the output, as we need to json_decode
        $header_arr = [];
        if ($method=='POST') {
            $header_arr[] = 'Content-Type:application/json';
        }
        if (!is_null($header)) {
            foreach($header as $k=>$v) {
                $header_arr[] = $k.": ".$v;
            }
        }
        if(!empty($header_arr)) curl_setopt($ch, CURLOPT_HTTPHEADER, $header_arr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        if(!empty($pem_file_path)){
            curl_setopt($ch, CURLOPT_SSLCERTTYPE,'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, $pem_file_path); 
            curl_setopt($ch, CURLOPT_SSLKEYTYPE,'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, $pem_file_path); 
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }
        if ($method=='POST') {
            //post提交方式
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        else {
            //get提交方式
        }
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        //运行curl
        $data = curl_exec($ch);
        if (!$bSilent) {
            $info = curl_getinfo($ch);
            Log::debug(json_encode($info['request_header']));
        }
        //返回结果
        if($data){
            if (!$bSilent) Log::debug('curl_response'.$data);
            curl_close($ch);
            return json_decode($data, true);
        } else {
            $error = curl_errno($ch);
            $errmsg = curl_strerror($error);
            curl_close($ch);
            if (!$bSilent) {
                // print("curl error:".$error.":".$errmsg."\n");
                throw new \Exception("curl error:". $errmsg, $error);
            }
        }
    }
}
