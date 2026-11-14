<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Stacks\JavaScript;
use App\Stacks\Language;
use App\Stacks\Php;
use App\Stacks\Ruby;
use App\Stacks\StaticSite;

test('each application type speaks through its own language provider', function () {
    $providers = array_map(fn(ApplicationType $type): Language => $type->language(), ApplicationType::cases());

    expect(array_map(fn(Language $language): string => $language::class, $providers))
        ->toBe([Php::class, JavaScript::class, Ruby::class, StaticSite::class]);
});
