<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OffsiteBackupController extends Controller
{
    private const ENDPOINT = 'Use an https:// address, like https://s3.eu-central-1.amazonaws.com.';

    private const REGION = "Use the bucket's region, like eu-central-1.";

    private const BUCKET = "That isn't a bucket name. Use lowercase letters, digits, dots and hyphens.";

    private const PREFIX = "Use letters, digits and ! _ . * ' ( ) / -, with no .. and no leading /.";

    public function update(Request $request, PlanGate $plans): RedirectResponse
    {
        $team = $this->team();

        $plans->ensureIncludes($team, PlanFeature::OffsiteBackups, $request->user());

        $existing = $team->backupDestination()->first();

        $validated = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:255', 'url:https', $this->withoutCredentials(...)],
            'region' => ['required', 'string', 'regex:/^[a-z0-9-]{2,32}$/'],
            'bucket' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
            'prefix' => ['nullable', 'string', 'max:200', "regex:/^[A-Za-z0-9!_.*'()\\/-]{0,200}$/", 'not_regex:/\.\./', 'not_regex:/^\//'],
            'access_key_id' => ['required', 'string', 'max:128'],
            'secret_access_key' => [$existing instanceof BackupDestination ? 'nullable' : 'required', 'string', 'max:256'],
            'path_style' => ['nullable', 'boolean'],
        ], [
            'endpoint.*' => self::ENDPOINT,
            'region.*' => self::REGION,
            'bucket.*' => self::BUCKET,
            'prefix.*' => self::PREFIX,
            'access_key_id.required' => 'Enter the access key ID.',
            'access_key_id.string' => 'Enter the access key ID.',
            'access_key_id.max' => 'An access key ID is at most 128 characters.',
            'secret_access_key.required' => 'Enter the secret access key.',
            'secret_access_key.string' => 'Enter the secret access key.',
            'secret_access_key.max' => 'A secret access key is at most 256 characters.',
            'path_style.boolean' => 'Turn path-style addressing on or off.',
        ]);

        $team->backupDestination()->updateOrCreate([], array_filter([
            'endpoint' => isset($validated['endpoint']) ? rtrim($validated['endpoint'], '/') : null,
            'region' => $validated['region'],
            'bucket' => $validated['bucket'],
            'prefix' => isset($validated['prefix']) ? trim($validated['prefix'], '/') : null,
            'access_key_id' => $validated['access_key_id'],
            'secret_access_key' => $validated['secret_access_key'] ?? null,
            'path_style' => $request->boolean('path_style'),
        ], fn(mixed $value, string $key): bool => $key !== 'secret_access_key' || $value !== null, ARRAY_FILTER_USE_BOTH));

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['bucket' => $validated['bucket']])->log('offsite_backups_configured');

        return back()->with('success', 'Saved. Databases go to S3 every night at 02:30.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $team = $this->team();

        $team->backupDestination()->delete();

        activity()->performedOn($team)->causedBy($request->user())->log('offsite_backups_removed');

        return back()->with('success', 'Removed. Databases are no longer sent to S3.');
    }

    private function team(): Team
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('update', $team);

        return $team;
    }

    private function withoutCredentials(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null)) {
            $fail(self::ENDPOINT);
        }
    }
}
