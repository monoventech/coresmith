<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Install\ProcessHandOff;

final class FakeProcessHandOff implements ProcessHandOff
{
    /** @var list<array{program: string, arguments: list<string>}> */
    public array $replacements = [];

    public function __construct(
        private readonly bool $root = true,
        private readonly string $failure = 'the test kept its own process',
    ) {}

    public function runningAsRoot(): bool
    {
        return $this->root;
    }

    public function replaceWith(string $program, array $arguments): string
    {
        $this->replacements[] = ['program' => $program, 'arguments' => $arguments];

        return $this->failure;
    }
}
