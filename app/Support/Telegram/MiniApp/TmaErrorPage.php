<?php

namespace App\Support\Telegram\MiniApp;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The error page of Mini App HTML requests (plan 20, security review L8): a
 * plain tma/error view inside layouts.tma (CSP-safe: no inline style or
 * script, no site layout, Livewire or webfont), in the locale already set
 * for the request (never the site's locale cookie). JSON requests, validation
 * errors, ready responses (HttpResponseException), authentication errors and
 * debug-mode 500s keep Laravel's default handling (null).
 * TmaHeaders adds the CSP and noindex to the response.
 */
final class TmaErrorPage
{
    public static function render(Throwable $exception, Request $request): ?Response
    {
        if (! $request->is('tma', 'tma/*') || $request->expectsJson() || $exception instanceof ValidationException
            || $exception instanceof HttpResponseException || $exception instanceof AuthenticationException) {
            return null;
        }

        $status = match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ThrottleRequestsException => 429,
            default => 500,
        };

        if ($status >= 500 && config('app.debug')) {
            return null;
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        return response()->view('tma.error', ['status' => $status], $status, $headers);
    }
}
