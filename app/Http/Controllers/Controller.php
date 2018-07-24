<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Http\Request;
use Laravel\Lumen\Routing\Controller as BaseController;
use App\Exceptions\CmException;

class Controller extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }
    //
    public function check_api_def()
    {
        $hasIgnorePara = !empty($this->consts['IGNORED_REQ_PARAS']);
        if (empty($this->consts['REQUEST_PARAS']))
            return false;
        foreach($this->consts['REQUEST_PARAS'] as $api_name=>$api_paras_def) {
            foreach($api_paras_def as $para_key=>$item) {
                if (substr($para_key, 0, 1) == "_")
                    return false;
                if ($hasIgnorePara && in_array($para_key, $this->consts['IGNORED_REQ_PARAS']))
                    return false;
                foreach($item as $key=>$value) {
                    if (!in_array($key, ['checker', 'required', 'default_value','converter', 'child_obj', 'description']))
                        return false;
                    if (in_array($key, ['checker','converter'])) {
                        $tocheck = is_array($value) ? ($value[0]??null) : $value;
                        if (!is_callable($tocheck)) {
                            return false;
                        }
                    }
                }
            }
        }
        return true;
    }

    public function api_doc_md(Request $request) {
        $output = "";
        $prefix = $request->path();
        $rpos = strrpos($prefix,'/');
        if ($rpos !== false) {
            $prefix = substr($prefix, 0, $rpos);
        }
        foreach($this->consts['REQUEST_PARAS'] as $api_spec_name=>$api_spec) {
            $output .= "\n * [".$api_spec_name."](#".$api_spec_name.")";
        }
        $output .= "\n";
        foreach($this->consts['REQUEST_PARAS'] as $api_spec_name=>$api_spec) {
            $output .= "\n## ".$api_spec_name."\n\n";
            $output .= "|  Tables  |       说明       | 默认值  |\n";
            $output .= "| :------: | :------------: | :--: |\n";
            $output .= "|   URL    | /".$prefix.'/'.$api_spec_name."/ |      |\n";
            $output .= <<<doc
            | HTTP请求方式 |      POST      |      |
            |  是否需要登录  |       是        |      |
            |  授权访问限制  |     Token      |      |
            |  授权范围()  |      单次请求      |      |
            |   支持格式   |  JSON (utf-8)  |      |

            表头参数:

            | Tables       | 类型及其范围 | 说明               | 默认值  |
            | ------------ | ------ | ---------------- | ---- |
            | Content-Type | string | application/json |      |
            | Auth-Token | string | 登陆时返回的token |      |


            Body参数:

doc;
            $table_header = <<<headerStr

            | Tables             | 类型及其范围      | 必填   | 说明                            | 默认值/样例           |
            | ------------------ | ----------- | ---- | ----------------------------- | ---------------- |

headerStr;
            $output .= (empty($api_spec))? "无": $table_header;
            foreach($api_spec as $para_name=>$para_spec) {
                $type_str = $para_spec['checker'] ?? null;
                if (is_array($type_str))
                    $type_str = $type_str[0] ?? null;
                if (!is_string($type_str)) {
                    $type_str = "customized";
                }
                if (substr($type_str, 0, 3) == "is_")
                    $type_str = substr($type_str, 3);
                $checker_para = is_array($para_spec['checker'])? ($para_spec['checker'][1]??null) :null;
                if (!empty($checker_para)) {
                    if (is_array($checker_para))
                        $type_str .= "(". implode(",",$checker_para) .")";
                    else
                        $type_str .= "(". $checker_para .")";
                }
                $required_str = !empty($para_spec['required']) ? "是" : "否";
                $desc_str = $para_spec['description'] ?? "----------------";
                $default_str = $para_spec['default_value'] ?? "----------------";
                $output .= "| ". $para_name . " | ". $type_str . " | " . $required_str
                    ." | ". $desc_str . " | ". $default_str. " | ";
                $output .= "\n";
            }
            $output .= "\n";
        }
        return response($output)->header('Content-Type', 'text/markdown; charset=utf-8');
    }

    private function format_caller_str($request, $caller) {
        $ret = $request->method().'|'.$request->server('SERVER_NAME').'|'.$request->path().'|'.$request->ip();
        if (!empty($caller)) {
            if (!empty($caller->account_id)) {
                $ret .= '|A_ID:'.$caller->account_id.'|';
            }
            if (!empty($caller->uid)) {
                $ret .= '|U_ID:'.$caller->uid.'|';
            }
            if (!empty($caller->username)) {
                $ret .= '|USER:'.$caller->username.'|';
            }
        }
        return $ret;
    }

    public function do_check_para($api_paras_def, $la_paras) {
        $resolve_func_and_call = function ($func_spec, $value) {
            $b_extra_para = is_array($func_spec) && count($func_spec)>=2;
            $func = is_array($func_spec)? $func_spec[0]: $func_spec;
            if ($b_extra_para && ($func == 'is_int' || $func == 'is_numeric')) {
                $new_value = $func($value);
                if (is_int($func_spec[1][0]))
                    $new_value = $new_value && $value>=$func_spec[1][0];
                if (is_int($func_spec[1][1]))
                    $new_value = $new_value && $value<=$func_spec[1][1];
                $value = $new_value;
            }
            elseif ($b_extra_para && ($func == 'is_string' || $func == 'is_array')) {
                $new_value = $func($value);
                if ($new_value) {
                    $len = ($func=='is_string')? strlen($value):count($value);
                    if (is_array($func_spec[1])) {
                        if (is_int($func_spec[1][0]))
                            $new_value = $new_value && $len>=$func_spec[1][0];
                        if (is_int($func_spec[1][1]))
                            $new_value = $new_value && $len<=$func_spec[1][1];
                    }
                    else {
                        $new_value = $new_value && $len<=$func_spec[1];
                    }
                }
                $value = $new_value;
            }
            else
                $value = $b_extra_para ? $func($value, $func_spec[1]):$func($value);
            return $value;
        };
        $ret = array();
        $para_count = 0;
        foreach ($api_paras_def as $key=>$item) {
            $rename = $item['rename'] ?? $key;
            if (array_key_exists($key, $la_paras)) {
                $para_count += 1;
                if (isset($item['checker'])) {
                    if (!$resolve_func_and_call($item['checker'], $la_paras[$key]))
                        throw new CmException('INVALID_PARAMETER',
                            "check failed:".$key.", checker:".json_encode($item['checker']));
                }
                if (isset($item['child_obj'])) {
                    $value = [];
                    foreach($la_paras[$key] as $aItem) {
                        $value[] = $this->do_check_para($item['child_obj'], $aItem);
                    }
                }
                else
                    $value = $la_paras[$key];
                if (isset($item['converter'])) {
                    $value = $resolve_func_and_call($item['converter'], $value);
                }
                $ret[$rename] = $value;
            }
            elseif (!empty($item['required'])) {
                throw new CmException('INVALID_PARAMETER', " missing required:".$key);
            }
            elseif (array_key_exists('default_value', $item)) {
                $value = $item['default_value'];
                if (isset($item['converter'])) {
                    $value = $resolve_func_and_call($item['converter'], $value);
                }
                $ret[$rename] = $value;
            }
        }
        if (!empty($this->consts['IGNORED_REQ_PARAS'])) {
            foreach ($this->consts['IGNORED_REQ_PARAS'] as $ign_para)
                $para_count += array_key_exists($ign_para, $la_paras) ? 1:0;
        }
        if (count($la_paras) > $para_count) {
            throw new CmException('INVALID_PARAMETER_NUM', "has undefined parameter. find ".count($la_paras).'parameters while only defined '.$para_count);
        }
        return $ret;
    }
    public function parse_parameters(Request $request, $api_name, $callerInfoObj = null, $bSilent = false) {
        $api_paras_def = $this->consts['REQUEST_PARAS'][$api_name] ?? null;
        if (is_null($api_paras_def))
            throw new CmException('SYSTEM_ERROR', 'EMPTY_API_DEFINITION for '.$api_name);
        $la_paras = $request->json()->all();
        try {
            //$payload = ($api_name != 'login') ? json_encode($la_paras) : "hide for login";
            $payload = json_encode(array_except($la_paras,['password','pwd']));
            $caller_str = $this->format_caller_str($request, $callerInfoObj);
            if (!$bSilent) {
                Log::DEBUG($caller_str . " called ".$api_name." payload_noPWD:". $payload);
            }
        }
        catch (\Exception $e) {
            Log::DEBUG("Exception in logging parse_parameters:". $e->getMessage());
        }
        $ret = $this->do_check_para($api_paras_def, $la_paras);
        return $ret;
    }
    public function check_role($role, $api_name) {
        if (empty($this->consts['ALLOWED_ROLES'][$api_name]))
            throw new CmException('SYSTEM_ERROR', 'EMPTY_ROLE_CHECK_DEFINITION for '.$api_name);
        if (!in_array($role, $this->consts['ALLOWED_ROLES'][$api_name]))
            throw new CmException('PERMISSION_DENIED', [$role,$api_name]);
        return true;
    }
    public function format_success_ret($data, $bLog=false) {
        //if (env('APP_DEBUG',false))
        if (env('APP_DEBUG',false) && $bLog) {
            if (is_string($data)) {
            }
            elseif (is_array($data) && (isset($data[0]) || isset($data['recs']))) {
                Log::DEBUG("SUCCESS return: array(".count($data['recs'] ?? $data).
                    "), first item:".json_encode($data['recs'][0] ?? $data[0] ?? "null"));
            }
            else {
                Log::DEBUG("SUCCESS return:".json_encode($data));
            }
        }
        return [
            'ev_error'=>0,
            'ev_message'=>"",
            'ev_data'=>$data,
        ];
    }
}

