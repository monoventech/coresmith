<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum BillingState: string
{
    case Unlimited = 'unlimited';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Ending = 'ending';
    case ReadOnly = 'read_only';
}
