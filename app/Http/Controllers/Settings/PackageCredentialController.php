<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PackageSource;
use App\Http\Controllers\Controller;
use App\Models\PackageCredential;
use App\Models\Team;
use App\Services\Packages\PackageCredentials;
use App\Services\Packages\StepSecretsBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PackageCredentialController extends Controller
{
    private const HOST = '/^(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}\z/D';

    private const REPOSITORY_ID = '/^[A-Za-z0-9._-]{1,128}\z/D';

    private const OWNER = '/^[A-Za-z0-9._-]{1,100}(?:\/[A-Za-z0-9._-]{1,100}){0,4}\z/D';

    private const SCOPE = '/^@?[a-z0-9][a-z0-9._-]{0,213}\z/D';

    private const SAVED = 'Saved. Installs and builds use it from the next deploy.';

    private const TARGET_MESSAGES = [
        'npm' => "Enter the registry's https:// address, like https://npm.pkg.github.com.",
        'maven' => 'Use the repository ID from pom.xml: letters, digits, dots, dashes and underscores.',
        'git' => 'Enter a host, with an owner if you like, such as github.com/acme.',
        'host' => 'Enter a host name, like nova.laravel.com.',
    ];

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team();
        $credential = $team->packageCredentials()->create([...$this->validated($request, $team, null), 'created_by' => $request->user()->id]);

        $this->audit($request, $team, $credential, 'package_credentials_added');

        return back()->with('success', self::SAVED);
    }

    public function update(Request $request, PackageCredential $packageCredential): RedirectResponse
    {
        $team = $this->team();

        abort_unless($packageCredential->team_id === $team->id, 404);

        $packageCredential->update(array_filter($this->validated($request, $team, $packageCredential), fn(mixed $value, string $key): bool => $key !== 'secret' || $value !== null, ARRAY_FILTER_USE_BOTH));

        $this->audit($request, $team, $packageCredential, 'package_credentials_updated');

        return back()->with('success', self::SAVED);
    }

    public function destroy(Request $request, PackageCredential $packageCredential): RedirectResponse
    {
        $team = $this->team();

        abort_unless($packageCredential->team_id === $team->id, 404);

        $packageCredential->delete();

        $this->audit($request, $team, $packageCredential, 'package_credentials_removed');

        return back()->with('success', 'Removed. The next deploy installs without it.');
    }

    /**
     * @return array{source: PackageSource, target: string, scope: ?string, username: ?string, secret: ?string, project_id: ?string}
     */
    private function validated(Request $request, Team $team, ?PackageCredential $existing): array
    {
        $source = PackageSource::tryFrom((string) $request->input('source'));
        $needsUsername = in_array($source, [PackageSource::Python, PackageSource::Maven], true);

        $validated = $request->validate([
            'source' => ['required', Rule::enum(PackageSource::class)],
            'target' => ['required', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'max:214'],
            'username' => [$needsUsername ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^\S+\z/D'],
            'secret' => [$existing === null ? 'required' : 'nullable', 'string', 'max:4096', 'regex:/^\S+\z/D'],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'id')->where('team_id', $team->id)],
        ], [
            'source.*' => 'Pick where the packages come from.',
            'target.*' => 'Enter where the packages come from.',
            'scope.*' => 'Scopes look like @acme. Separate several with commas.',
            'username.required' => 'Enter the username.',
            'username.*' => "A username can't contain spaces or line breaks.",
            'secret.required' => 'Enter the password or token.',
            'secret.max' => 'A password or token is at most 4096 characters.',
            'secret.*' => "A password or token can't contain spaces or line breaks.",
            'project_id.*' => "Pick one of this team's projects.",
        ]);

        $source = PackageSource::from($validated['source']);
        $target = $this->target($source, trim($validated['target']));

        if ($target === null) {
            throw ValidationException::withMessages(['target' => self::TARGET_MESSAGES[$source->value] ?? self::TARGET_MESSAGES['host']]);
        }

        $scope = $source === PackageSource::Npm ? $this->scopes($validated['scope'] ?? null) : null;

        $attributes = [
            'source' => $source,
            'target' => $target,
            'scope' => $scope,
            'username' => $source === PackageSource::Npm ? null : ($validated['username'] ?? null),
            'secret' => $validated['secret'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
        ];

        $this->ensureUnique($team, $existing, new PackageCredential($attributes));

        return $attributes;
    }

    private function target(PackageSource $source, string $target): ?string
    {
        return match ($source) {
            PackageSource::Npm => $this->registry($target),
            PackageSource::Maven => preg_match(self::REPOSITORY_ID, $target) === 1 ? $target : null,
            PackageSource::Git => $this->gitTarget($target),
            default => preg_match(self::HOST, strtolower($target)) === 1 ? strtolower($target) : null,
        };
    }

    private function registry(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return preg_match(self::HOST, strtolower((string) ($parts['host'] ?? ''))) === 1 ? StepSecretsBuilder::registry($url) : null;
    }

    private function gitTarget(string $target): ?string
    {
        [$host, $owner] = array_pad(explode('/', rtrim($target, '/'), 2), 2, null);
        $host = strtolower((string) $host);

        if (preg_match(self::HOST, $host) !== 1 || ($owner !== null && preg_match(self::OWNER, $owner) !== 1)) {
            return null;
        }

        return $owner === null ? $host : $host . '/' . $owner;
    }

    private function scopes(?string $input): ?string
    {
        $parts = preg_split('/[\s,]+/', trim((string) $input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            if (preg_match(self::SCOPE, strtolower($part)) !== 1) {
                throw ValidationException::withMessages(['scope' => 'Scopes look like @acme. Separate several with commas.']);
            }
        }

        $scopes = StepSecretsBuilder::scopes(implode(' ', $parts));

        return $scopes === [] ? null : implode(' ', $scopes);
    }

    private function ensureUnique(Team $team, ?PackageCredential $existing, PackageCredential $candidate): void
    {
        $key = PackageCredentials::key($candidate);

        $taken = $team->packageCredentials()
            ->where('project_id', $candidate->project_id)
            ->when($existing !== null, fn($query) => $query->whereKeyNot($existing->getKey()))
            ->get()
            ->contains(fn(PackageCredential $other): bool => PackageCredentials::key($other) === $key);

        if ($taken) {
            throw ValidationException::withMessages(['target' => 'Coresmith already has credentials for ' . PackageCredentials::label($candidate) . ' here. Replace those instead.']);
        }
    }

    private function audit(Request $request, Team $team, PackageCredential $credential, string $event): void
    {
        activity()->performedOn($team)->causedBy($request->user())->withProperties(['source' => $credential->source->value, 'target' => $credential->target])->log($event);
    }

    private function team(): Team
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('update', $team);

        return $team;
    }
}
