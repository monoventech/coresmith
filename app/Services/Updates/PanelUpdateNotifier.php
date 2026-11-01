<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\InstanceSetting;
use App\Models\User;
use App\Notifications\PanelUpdateFailedNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class PanelUpdateNotifier
{
    public function mailable(): bool
    {
        return config('mail.default') !== 'log';
    }

    public function failed(UpdateStatus $status): bool
    {
        if (!$this->mailable()) {
            return false;
        }

        try {
            $notification = new PanelUpdateFailedNotification($status->toArray());
            $owners = User::query()->instanceOwners()->get();

            if ($owners->isNotEmpty()) {
                Notification::sendNow($owners, $notification);
            }

            $email = (string) InstanceSetting::get('notification_email');

            if ($email !== '' && !$owners->contains(fn(User $owner): bool => strcasecmp((string) $owner->email, $email) === 0)) {
                Notification::route('mail', $email)->notifyNow($notification);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
