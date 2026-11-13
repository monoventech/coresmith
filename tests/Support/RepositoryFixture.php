<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

final class RepositoryFixture
{
    public static function facts(string $name): RepositoryFacts
    {
        $directory = base_path('tests/Fixtures/repositories/' . $name);

        if (!is_dir($directory)) {
            throw new InvalidArgumentException("There is no repository fixture named {$name}.");
        }

        $result = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s');

        return app(RepositoryProbe::class)->parse($result->output());
    }
}
