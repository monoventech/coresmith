<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

final class GoFrameworks
{
    public const PLAIN = 'net-http';

    public const ALL = [
        'gin' => ['Gin', ['github.com/gin-gonic/gin'], ['GIN_MODE' => 'release']],
        'echo' => ['Echo', ['github.com/labstack/echo'], []],
        'fiber' => ['Fiber', ['github.com/gofiber/fiber'], []],
        'chi' => ['Chi', ['github.com/go-chi/chi'], []],
        'gorilla' => ['Gorilla', ['github.com/gorilla/mux'], []],
        'iris' => ['Iris', ['github.com/kataras/iris'], []],
        'hertz' => ['Hertz', ['github.com/cloudwego/hertz'], []],
        'beego' => ['Beego', ['github.com/beego/beego', 'github.com/astaxie/beego'], ['BEEGO_RUNMODE' => 'prod']],
        'buffalo' => ['Buffalo', ['github.com/gobuffalo/buffalo'], ['GO_ENV' => 'production']],
        'go-zero' => ['go-zero', ['github.com/zeromicro/go-zero'], []],
        'kratos' => ['Kratos', ['github.com/go-kratos/kratos'], []],
        'goframe' => ['GoFrame', ['github.com/gogf/gf'], []],
        'huma' => ['Huma', ['github.com/danielgtaylor/huma'], []],
        'connect' => ['Connect', ['connectrpc.com/connect', 'github.com/bufbuild/connect-go'], []],
        'grpc' => ['gRPC', ['google.golang.org/grpc'], []],
        self::PLAIN => ['net/http', [], []],
    ];

    public static function claiming(GoModule $module, GoSource $source): string
    {
        foreach (self::ALL as $key => [, $modules]) {
            if ($modules === [] || $module->requiresAny($modules) === null) {
                continue;
            }

            if ($key !== 'grpc' || $source->grpcServer()) {
                return $key;
            }
        }

        return self::PLAIN;
    }

    public static function evidence(string $key, GoModule $module): string
    {
        $found = $module->requiresAny(self::ALL[$key][1] ?? []);

        return $found === null ? 'no web framework in go.mod' : "{$found} in go.mod";
    }

    public static function label(string $key): ?string
    {
        return self::ALL[$key][0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function environment(string $key): array
    {
        return self::ALL[$key][2] ?? [];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn(string $key): array => ['value' => $key, 'label' => self::ALL[$key][0]], array_keys(self::ALL));
    }
}
