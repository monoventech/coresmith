<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;

final class AppTags
{
    public const MAX = 5;

    public const LENGTH = 24;

    public const PATTERN = '/^[\pL\pN][\pL\pN ._-]*$/u';

    public const SUGGESTIONS = 50;

    /**
     * @return list<string>
     */
    public static function normalize(mixed $input): array
    {
        $seen = [];
        $tags = [];

        foreach (is_array($input) ? $input : [] as $tag) {
            $tag = is_string($tag) ? trim((string) preg_replace('/\s+/u', ' ', $tag)) : '';
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * @return list<string>
     */
    public static function suggestions(User $viewer, Team $team): array
    {
        $seen = [];

        foreach (app(TeamAccess::class)->applications($viewer, $team)->whereNotNull('tags')->orderBy('id')->get(['id', 'tags']) as $application) {
            foreach ($application->tags as $tag) {
                $seen[mb_strtolower($tag)] ??= $tag;
            }
        }

        $tags = array_values($seen);
        usort($tags, fn(string $a, string $b): int => strcasecmp($a, $b));

        return array_slice($tags, 0, self::SUGGESTIONS);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'tags' => ['nullable', 'array', 'max:' . self::MAX],
            'tags.*' => ['string', 'max:' . self::LENGTH, 'regex:' . self::PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'tags.max' => 'An app can have up to ' . self::MAX . ' tags.',
            'tags.*.max' => 'Keep each tag to ' . self::LENGTH . ' characters or fewer.',
            'tags.*.regex' => 'Start each tag with a letter or number, then use letters, numbers, spaces, dots, dashes or underscores.',
        ];
    }
}
