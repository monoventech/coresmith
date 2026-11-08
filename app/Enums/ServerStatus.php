<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum ServerStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
    case Disconnected = 'disconnected';
    case Creating = 'creating';
    case Moving = 'moving';

    /**
     * @return list<self>
     */
    public static function beforeProvisioning(): array
    {
        return [self::Pending, self::Disconnected];
    }

    public function isBeforeProvisioning(): bool
    {
        return in_array($this, self::beforeProvisioning(), true);
    }
}
