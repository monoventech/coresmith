<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use Carbon\CarbonInterface;

final readonly class WebhookMode
{
    public const RECEIVED = 'received';

    public const FAILING = 'failing';

    public const UNPROVEN = 'unproven';

    public const MOVED = 'moved';

    public const NONE = 'none';

    public const UNREACHABLE = 'unreachable';

    public const NO_PUSH = 'no_push';

    public function __construct(
        public bool $instant,
        public string $reason,
        public string $sentence,
    ) {}

    public static function instant(CarbonInterface $since): self
    {
        return new self(true, self::RECEIVED, 'GitHub reached this panel ' . $since->min()->diffForHumans(['options' => CarbonInterface::JUST_NOW]) . '.');
    }

    public static function polling(string $reason, string $sentence): self
    {
        return new self(false, $reason, $sentence);
    }
}
