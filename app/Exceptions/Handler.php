<?php

namespace App\Exceptions;

use Exception;
use Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception  $e
     * @return void
     */
    public function report(Exception $e)
    {
        if ($e instanceof CmException) {
            $ls_result = [
                'ev_error' => $e->getInnerCode(),
                'ev_message' => $e->getMessage(),
                'ev_context' => $e->getContext(),
            ];
            Log::DEBUG($e->getFile().':'.$e->getLine().':CmException:'.
                json_encode($ls_result, JSON_PARTIAL_OUTPUT_ON_ERROR));
            return;
        }
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $e)
    {
        if ($e instanceof CmException) {
            $ls_result = array();
            //$ls_result['ev_error_phase'] = $e->getCode();
            $errcode = $e->getInnerCode();
            $ls_result['ev_error'] = $errcode;
            $ls_result['ev_message'] = $e->getMessage();
            if (env('APP_DEBUG', false) || ($errcode < 40000 && $errcode >= 30000)) {
                //always expose detail for INVALID_PARAMETER
                $ls_result['ev_context'] = $e->getContext();
            }
            $st_code = ($errcode < 20000 && $errcode >= 10000) ? 401:500;
            return response($ls_result, $st_code);
        }
        return parent::render($request, $e);
    }
}
