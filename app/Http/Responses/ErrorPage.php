<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Router;
use Inertia\ExceptionResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ErrorPage
{
    private const STATUSES = [403, 404, 500];

    public function __construct(private RefusedForm $forms) {}

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        $response = ($this->forms)($response, $exception, $request);
        $status = $response->getStatusCode();

        if (!in_array($status, self::STATUSES, true) || !$request->isMethodSafe() || $request->expectsJson() || ($status >= 500 && config('app.debug'))) {
            return $response;
        }

        try {
            return (new ExceptionResponse($exception, $request, $response, app(Router::class), app(Kernel::class)))
                ->render('Error', ['status' => $status, 'expired' => $exception instanceof InvalidSignatureException])
                ->toResponse($request);
        } catch (Throwable $failure) {
            report($failure);

            return $response;
        }
    }
}
