<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum CloudInstanceState: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Stopped = 'stopped';
    case Busy = 'busy';
    case Deleting = 'deleting';
    case Unknown = 'unknown';
}
