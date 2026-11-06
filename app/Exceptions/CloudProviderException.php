<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use RuntimeException;
use Throwable;

final class CloudProviderException extends RuntimeException implements ExplainsFailure
{
    public function __construct(
        public readonly CloudFailure $failure,
        public readonly CloudProviderType $provider,
        public readonly ?string $detail = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($this->taskMessage(), 0, $previous);
    }

    public static function noToken(CloudProviderType $provider): self
    {
        return new self(CloudFailure::NoToken, $provider);
    }

    public static function unreadableToken(CloudProviderType $provider): self
    {
        return new self(CloudFailure::UnreadableToken, $provider);
    }

    public function retryable(): bool
    {
        return $this->failure->retryable();
    }

    public function connectMessage(): string
    {
        $label = $this->provider->label();
        $said = $this->said();

        return match ($this->failure) {
            CloudFailure::TokenRejected => "{$label} didn't accept that token.{$said} Copy it again from {$label}, then paste it here.",
            CloudFailure::Forbidden => "{$label} accepted the token, but it isn't allowed to manage servers.{$said} {$this->provider->permissionHint()}",
            CloudFailure::Busy => "{$label} is busy with another change.{$said} Try again in a minute.",
            CloudFailure::RateLimited => "{$label} is rate limiting this account.{$said} Wait a minute, then try again.",
            CloudFailure::NotFound, CloudFailure::Refused, CloudFailure::Unavailable, CloudFailure::Unreachable,
            CloudFailure::NoToken, CloudFailure::UnreadableToken, CloudFailure::AddressRefused => $this->taskMessage(),
        };
    }

    private function taskMessage(): string
    {
        $label = $this->provider->label();
        $said = $this->said();
        $afterFixing = $this->provider->hasScopedTokens() ? 'Then replace the token in Settings → Team.' : 'Then try again.';

        return match ($this->failure) {
            CloudFailure::TokenRejected => "{$label} refused this account's token.{$said} Replace it in Settings → Team, then try again.",
            CloudFailure::Forbidden => "{$label} says this token isn't allowed to do that.{$said} {$this->provider->permissionHint()} {$afterFixing}",
            CloudFailure::NotFound => "{$label} can't find it any more.{$said}",
            CloudFailure::Busy => "{$label} is busy with another change to it.{$said} Coresmith tries again shortly.",
            CloudFailure::RateLimited => "{$label} is rate limiting this account.{$said} Coresmith tries again shortly.",
            CloudFailure::Refused => "{$label} refused the request.{$said}",
            CloudFailure::Unavailable => "{$label} had a problem answering" . ($this->status === null ? '' : " (HTTP {$this->status})") . '. Try again in a minute.',
            CloudFailure::Unreachable => "Coresmith couldn't reach {$label} at {$this->provider->apiHost()}. Check this panel can make outbound HTTPS requests, then try again.",
            CloudFailure::NoToken => "No token is stored for this {$label} account. Replace the token in Settings → Team, then try again.",
            CloudFailure::UnreadableToken => "Coresmith couldn't read the token stored for this {$label} account. Replace the token in Settings → Team, then try again.",
            CloudFailure::AddressRefused => "{$label} refused requests from this panel's IP address.{$said} Add the address to Access Control in {$label}'s API settings, then try again.",
        };
    }

    private function said(): string
    {
        $detail = rtrim(trim((string) $this->detail), '. ');

        return $detail === '' ? '' : " {$this->provider->label()} said: {$detail}.";
    }
}
