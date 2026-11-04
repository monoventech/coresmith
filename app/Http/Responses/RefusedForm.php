<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RefusedForm
{
    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if (!$request->inertia() || $request->isMethodSafe()) {
            return $response;
        }

        $message = match ($response->getStatusCode()) {
            419 => 'This page expired. Try again.',
            429 => 'Too many attempts. Try again in ' . $this->wait($response) . '.',
            default => null,
        };

        return $message === null ? $response : back(303)->with('error', $message);
    }

    private function wait(Response $response): string
    {
        $seconds = max(1, (int) $response->headers->get('Retry-After'));
        $minutes = (int) ceil($seconds / 60);

        return $seconds < 60
            ? $seconds . ' ' . Str::plural('second', $seconds)
            : $minutes . ' ' . Str::plural('minute', $minutes);
    }
}
