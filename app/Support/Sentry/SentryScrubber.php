<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Support\Sentry;

use App\Services\Deployment\LogSanitizer;
use Illuminate\Database\QueryException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Tracing\Span;
use Throwable;

final class SentryScrubber
{
    public const FILTERED = '[Filtered]';

    public const QUERY_KEYS = ['token', 'code', 'state', 'signature'];

    public const KEPT_HEADERS = ['user-agent', 'content-type', 'accept'];

    private const PRIVATE_QUERY_KEYS = ['email'];

    private const ADDRESS_KEYS = ['url', 'http.url', 'http.query', 'to', 'from'];

    private const SECRET_KEYS = '/password|passwd|secret|token|api[_-]?key|authorization|cookie|session|private[_-]?key|credential|signature/i';

    private const MIN_ECHOED_VALUE_LENGTH = 4;

    private const MAX_DEPTH = 10;

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event, $hint);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event, $hint);
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::filterText($message));
        }

        foreach (self::scrubValues($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, $value);
        }

        return $breadcrumb;
    }

    private static function scrub(Event $event, ?EventHint $hint): Event
    {
        $event->setRequest(self::scrubRequest($event->getRequest()));

        self::scrubExceptions($event, $hint?->exception);

        $message = $event->getMessage();

        if ($message !== null) {
            $formatted = $event->getMessageFormatted();

            $event->setMessage(
                self::filterText($message),
                self::scrubValues($event->getMessageParams()),
                $formatted === null ? null : self::filterText($formatted),
            );
        }

        $event->setExtra(self::scrubValues($event->getExtra()));
        $event->setTags(self::scrubValues($event->getTags()));

        foreach ($event->getContexts() as $name => $data) {
            $event->setContext((string) $name, self::scrubValues($data));
        }

        foreach ($event->getSpans() as $span) {
            self::scrubSpan($span);
        }

        $event->setBreadcrumb(array_values(array_filter(array_map(self::beforeBreadcrumb(...), $event->getBreadcrumbs()))));

        return $event;
    }

    private static function scrubExceptions(Event $event, ?Throwable $original): void
    {
        foreach ($event->getExceptions() as $exception) {
            $matched = $original !== null && $original::class === $exception->getType() ? $original : null;
            $value = $matched instanceof QueryException ? self::queryValue($matched) : $exception->getValue();

            $exception->setValue(self::filterText($value));

            $original = $original?->getPrevious();
        }
    }

    private static function queryValue(QueryException $failure): string
    {
        $driver = $failure->getPrevious()?->getMessage() ?? '';

        foreach ($failure->getBindings() as $binding) {
            if (is_string($binding) && strlen($binding) >= self::MIN_ECHOED_VALUE_LENGTH) {
                $driver = str_replace($binding, '?', $driver);
            }
        }

        return $driver . ' (Connection: ' . $failure->getConnectionName() . ', SQL: ' . $failure->getSql() . ')';
    }

    private static function scrubSpan(Span $span): void
    {
        $description = $span->getDescription();

        if ($description !== null) {
            $span->setDescription(self::filterText($description));
        }

        $span->setData(self::scrubValues($span->getData()));
        $span->setTags(self::scrubValues($span->getTags()));
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
    private static function scrubValues(array $values, int $depth = 0): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            $scrubbed[$key] = self::scrubValue($key, $value, $depth);
        }

        return $scrubbed;
    }

    private static function scrubValue(int|string $key, mixed $value, int $depth): mixed
    {
        return match (true) {
            preg_match(self::SECRET_KEYS, (string) $key) === 1 => self::FILTERED,
            $depth >= self::MAX_DEPTH && (is_array($value) || is_object($value)) => self::FILTERED,
            is_object($value) => self::scrubValue($key, self::plain($value), $depth + 1),
            is_array($value) => self::scrubValues($value, $depth + 1),
            !is_string($value) => $value,
            in_array(strtolower((string) $key), self::ADDRESS_KEYS, true) => self::filterAddress($value),
            default => self::filterText($value),
        };
    }

    private static function plain(object $value): mixed
    {
        try {
            $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            return $json === false ? self::FILTERED : json_decode($json, true);
        } catch (Throwable) {
            return self::FILTERED;
        }
    }

    private static function filterAddress(string $address): string
    {
        return self::mask(self::sanitize($address), false);
    }

    private static function filterText(string $text): string
    {
        return self::mask(self::sanitize($text), true);
    }

    private static function mask(string $subject, bool $text): string
    {
        $filtered = rawurlencode(self::FILTERED);
        $names = implode('|', array_map(static fn(string $name): string => preg_quote($name, '~'), [...self::QUERY_KEYS, ...self::PRIVATE_QUERY_KEYS]));
        $stop = $text ? '&#\s"\'<>)' : '&#';
        $segment = $text ? '[^/?#\s"\'<>)]+' : '[^/?#]+';
        $start = $text ? '([?&])' : '(^|[?&])';

        $subject = (string) preg_replace(
            [
                '~(/reset-password/)' . $segment . '~i',
                '~(/(?:verify-email|email/verify)/' . $segment . '/)' . $segment . '~i',
            ],
            '$1' . $filtered,
            $subject,
        );

        return (string) preg_replace(
            '~' . $start . '((?:' . $names . ')|[^=' . $stop . ']{0,64}signature)(\[[^\]=&#]{0,32}\]|%5B[^=&#]{0,32}?%5D)?=[^' . $stop . ']*~i',
            '$1$2$3=' . $filtered,
            $subject,
        );
    }

    private static function sanitize(string $text): string
    {
        return app(LogSanitizer::class)->sanitize($text);
    }
}
