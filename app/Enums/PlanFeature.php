<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum PlanFeature: string
{
    case TeamRoles = 'team_roles';
    case CustomDomain = 'custom_domain';
    case SingleSignOn = 'single_sign_on';
    case OffsiteBackups = 'offsite_backups';
    case AuditLogExport = 'audit_log_export';

    public function note(): string
    {
        return match ($this) {
            self::TeamRoles => 'Teammates and roles are part of Pro and Business.',
            self::CustomDomain => 'Custom domains are part of Pro and Business.',
            self::SingleSignOn => 'Single sign-on is part of Business.',
            self::OffsiteBackups => 'Database backups to S3 are part of Business.',
            self::AuditLogExport => 'Audit log export is part of Business.',
        };
    }
}
