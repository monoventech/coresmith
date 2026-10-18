<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use phpseclib3\Crypt\EC;

final class KeyPairGenerator
{
    public function generate(string $comment = 'coresmith'): KeyPair
    {
        $key = EC::createKey('Ed25519');
        $options = ['comment' => $comment];

        return new KeyPair(
            privateKey: $key->toString('OpenSSH', $options),
            publicKey: $key->getPublicKey()->toString('OpenSSH', $options),
        );
    }
}
