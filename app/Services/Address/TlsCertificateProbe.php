<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final class TlsCertificateProbe implements CertificateProbe
{
    public function serves(string $host, CertificateSource $source): bool
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $trusted = $source !== CertificateSource::Internal;
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'SNI_enabled' => !$ip,
            'verify_peer' => $trusted,
            'verify_peer_name' => $trusted,
            'capture_peer_cert' => true,
        ]]);
        $target = $ip ? (str_contains($host, ':') ? "[{$host}]" : $host) : '127.0.0.1';
        $socket = @stream_socket_client("ssl://{$target}:443", $code, $message, 5, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return false;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        if ($trusted) {
            return true;
        }

        $names = $certificate === null ? '' : (string) (openssl_x509_parse($certificate)['extensions']['subjectAltName'] ?? '');

        return in_array(($ip ? 'IP Address:' : 'DNS:') . $host, array_map(trim(...), explode(',', $names)), true);
    }
}
