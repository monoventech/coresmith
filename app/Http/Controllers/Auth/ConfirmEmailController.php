<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse;

final class ConfirmEmailController extends Controller
{
    public function __invoke(Request $request, string $hash): VerifyEmailResponse
    {
        $user = $request->user();

        abort_unless(hash_equals(sha1((string) $user->getEmailForVerification()), $hash), 403);

        if (!$user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return app(VerifyEmailResponse::class);
    }
}
