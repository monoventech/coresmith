<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Models\Application;
use App\Services\Deployment\CronExpression;
use App\Services\Panel\CronJobs;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CronJobRequest extends FormRequest
{
    public const CONTROL = '/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'schedule' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = is_string($value) ? CronExpression::problem($value) : null;

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'command' => ['required', 'string', 'max:1000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match(self::CONTROL, $value) !== 0) {
                    $fail('The command has an invisible control character in it. Retype that part and save again.');
                }
            }],
            'description' => [
                'nullable',
                'string',
                'max:80',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && str_contains($value, "\n")) {
                        $fail('Keep the description on one line.');
                    }
                },
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_match(self::CONTROL, $value) !== 0) {
                        $fail('The description has an invisible control character in it. Retype it and save again.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'schedule.required' => 'Choose when it runs.',
            'command.required' => 'Enter the command to run, like php artisan backup:run.',
            'command.max' => 'Keep the command under 1,000 characters. Put a longer one in a script in your repository and run that.',
            'description.max' => 'Keep the description under 80 characters.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $problem = $this->listProblem();

                if ($problem !== null) {
                    $validator->errors()->add('command', $problem);
                }
            },
        ];
    }

    /**
     * @return array{schedule: string, command: string, description: string|null}
     */
    public function entry(): array
    {
        $validated = $this->validated();

        return [
            'schedule' => (string) $validated['schedule'],
            'command' => (string) $validated['command'],
            'description' => isset($validated['description']) ? (string) $validated['description'] : null,
        ];
    }

    protected function prepareForValidation(): void
    {
        $schedule = $this->input('schedule');
        $command = $this->input('command');
        $description = $this->input('description');

        $this->merge([
            'schedule' => is_string($schedule) ? trim((string) preg_replace('/\s+/', ' ', $schedule)) : $schedule,
            'command' => is_string($command) ? trim(str_replace(["\r\n", "\r"], "\n", $command)) : $command,
            'description' => is_string($description) ? (trim($description) === '' ? null : trim($description)) : $description,
        ]);
    }

    private function listProblem(): ?string
    {
        $application = $this->route('application');

        if (!$application instanceof Application) {
            return null;
        }

        $cron = app(CronJobs::class);
        $editing = $this->route('cronJob');
        $entries = $cron->entries($application);
        $others = collect($entries)->reject(fn(array $entry): bool => $entry['id'] === $editing);
        $schedule = $this->input('schedule');
        $command = $this->input('command');

        return match (true) {
            $editing === null && count($entries) >= CronJobs::MAX_ENTRIES => 'This app already has ' . CronJobs::MAX_ENTRIES . ' cron jobs. Remove one, or run several commands from one job.',
            $others->contains(fn(array $entry): bool => $entry['schedule'] === $schedule && $entry['command'] === $command) => 'That exact job is already on the list.',
            $cron->isScheduler(['command' => $command]) && $others->contains(fn(array $entry): bool => $cron->isScheduler($entry)) => 'The Laravel scheduler is already on the list. Edit that job instead.',
            default => null,
        };
    }
}
