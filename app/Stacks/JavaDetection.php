<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class JavaDetection
{
    /**
     * @return array<string, mixed>|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        $build = JavaFrameworks::rootFile($facts);

        if ($build === null) {
            return null;
        }

        [$framework, $evidence] = JavaFrameworks::claim($facts);

        return Proposal::make('java', $framework, null, null, ['type' => "From {$build}", 'framework' => $evidence]);
    }
}
