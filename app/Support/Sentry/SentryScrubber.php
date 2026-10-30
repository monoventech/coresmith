<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Support\Sentry;

use App\Services\Deployment\LogSanitizer;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

final class SentryScrubber
{
    public const FILTERED = '[Filtered]';

    public const QUERY_KEYS = ['token', 'code', 'state', 'signature'];

    public const KEPT_HEADERS = ['user-agent', 'content-type', 'accept'];

    private const ADDRESS_KEYS = ['url', 'http.url', 'http.query', 'to', 'from'];

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::sanitize($message));
        }

        foreach (self::scrubValues($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, $value);
        }

        return $breadcrumb;
    }

    private static function scrub(Event $event): Event
    {
        $event->setRequest(self::scrubRequest($event->getRequest()));

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::sanitize($exception->getValue()));
        }

        $message = $event->getMessage();

        if ($message !== null) {
            $formatted = $event->getMessageFormatted();

            $event->setMessage(
                self::sanitize($message),
                self::scrubValues($event->getMessageParams()),
                $formatted === null ? null : self::sanitize($formatted),
            );
        }

        $event->setExtra(self::scrubValues($event->getExtra()));
        $event->setBreadcrumb(array_values(array_filter(array_map(self::beforeBreadcrumb(...), $event->getBreadcrumbs()))));

        return $event;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function scrubRequest(array $request): array
    {
        $scrubbed = [];

        if (is_string($request['url'] ?? null)) {
            $scrubbed['url'] = self::filterAddress($request['url']);
        }

        if (is_string($request['method'] ?? null)) {
            $scrubbed['method'] = $request['method'];
        }

        if (is_string($request['query_string'] ?? null)) {
            $scrubbed['query_string'] = self::filterAddress($request['query_string']);
        }

        if (is_array($request['headers'] ?? null)) {
            $scrubbed['headers'] = array_filter(
                $request['headers'],
                static fn(int|string $name): bool => in_array(strtolower((string) $name), self::KEPT_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $scrubbed;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function scrubValues(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            $scrubbed[$key] = match (true) {
                is_array($value) => self::scrubValues($value),
                !is_string($value) => $value,
                in_array(strtolower((string) $key), self::ADDRESS_KEYS, true) => self::filterAddress($value),
                default => self::sanitize($value),
            };
        }

        return $scrubbed;
    }

    private static function filterAddress(string $address): string
    {
        $names = implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), self::QUERY_KEYS));

        return (string) preg_replace(
            '/(^|[?&])(' . $names . ')=[^&#]*/i',
            '$1$2=' . rawurlencode(self::FILTERED),
            self::sanitize($address),
        );
    }

    private static function sanitize(string $text): string
    {
        return app(LogSanitizer::class)->sanitize($text);
    }
}
