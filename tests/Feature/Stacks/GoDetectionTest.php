<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\Blocker;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;

function goFacts(array $files, array $markers = []): RepositoryFacts
{
    $plain = [];
    $goLines = [];

    foreach ($files as $path => $content) {
        if (!str_ends_with($path, '.go')) {
            $plain[$path] = $content;

            continue;
        }

        $goLines[$path] = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $line) {
            if (preg_match('~' . RepositoryProbe::GO_PATTERN . '~', $line) === 1) {
                $goLines[$path][] = [$index + 1, trim($line)];
            }
        }
    }

    return new RepositoryFacts($plain, markers: $markers, complete: true, goLines: $goLines);
}

function goMod(array $requires = [], string $extra = ''): string
{
    $lines = implode("\n", array_map(fn(string $module, string $version): string => "\t{$module} {$version}", array_keys($requires), $requires));

    return "module github.com/acme/shop\n\ngo 1.26\n" . ($requires === [] ? '' : "\nrequire (\n{$lines}\n)\n") . $extra;
}

function goMain(string $body): string
{
    return "package main\n\nimport (\n\t\"net/http\"\n\t\"os\"\n)\n\nfunc main() {\n{$body}\n}\n";
}

function goDetect(RepositoryFacts $facts): ?array
{
    return ApplicationType::Go->language()->detect($facts);
}

function goToolingFor(RepositoryFacts $facts, ?string $main = null, string $framework = 'net-http'): array
{
    return ApplicationType::Go->language()->tooling(new Application(['type' => ApplicationType::Go, 'framework' => $framework, 'main_package' => $main]), $facts);
}

function goBlockers(RepositoryFacts $facts, ?string $main = null, string $framework = 'net-http'): array
{
    return array_map(fn(Blocker $blocker): array => $blocker->toArray(), ApplicationType::Go->language()->checks(new Application(['type' => ApplicationType::Go, 'framework' => $framework, 'main_package' => $main]), $framework, $facts, 'x86_64'));
}

test('a plain net/http app at the module root that reads PORT is ready, and builds one binary named after the module', function () {
    $facts = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), nil)")]);

    expect(goDetect($facts))->toBe([
        'type' => 'go', 'framework' => 'net-http', 'js_runtime' => null, 'web' => null,
        'sources' => ['type' => 'From go.mod', 'framework' => 'no web framework in go.mod', 'main_package' => 'the only package main'],
        'unsettled' => [], 'fields' => ['main_package' => '.'], 'options' => ['main_package' => ['.']],
    ])
        ->and(goToolingFor($facts))->toBe(['mains' => ['.' => 'shop'], 'web' => '.', 'vendored' => false, 'templ' => null, 'listen' => [], 'flag' => null, 'grpc' => false, 'goose' => null])
        ->and(goBlockers($facts))->toBe([]);
});

test('the framework comes from go.mod, whatever major version it is on', function (array $requires, string $framework, string $evidence) {
    $detected = goDetect(goFacts(['go.mod' => goMod($requires), 'main.go' => goMain("\tr.Run()")]));

    expect($detected['framework'])->toBe($framework)
        ->and($detected['sources']['framework'])->toBe($evidence);
})->with([
    'Gin' => [['github.com/gin-gonic/gin' => 'v1.10.0'], 'gin', 'github.com/gin-gonic/gin in go.mod'],
    'Echo v4' => [['github.com/labstack/echo/v4' => 'v4.13.3'], 'echo', 'github.com/labstack/echo in go.mod'],
    'Fiber v2' => [['github.com/gofiber/fiber/v2' => 'v2.52.6'], 'fiber', 'github.com/gofiber/fiber in go.mod'],
    'Chi v5' => [['github.com/go-chi/chi/v5' => 'v5.2.1'], 'chi', 'github.com/go-chi/chi in go.mod'],
    'Beego v2' => [['github.com/beego/beego/v2' => 'v2.3.4'], 'beego', 'github.com/beego/beego in go.mod'],
    'a gRPC client only' => [['google.golang.org/grpc' => 'v1.70.0'], 'net-http', 'no web framework in go.mod'],
]);

test('a cmd layout runs the package that starts a server, and every package main is built', function () {
    $facts = goFacts([
        'go.mod' => goMod(['github.com/go-chi/chi/v5' => 'v5.2.1']),
        'cmd/api/main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), router())"),
        'cmd/worker/main.go' => goMain("\trunQueue()"),
        'internal/store/store.go' => "package store\n",
    ]);

    expect(goDetect($facts)['fields'])->toBe(['main_package' => 'cmd/api'])
        ->and(goDetect($facts)['options'])->toBe(['main_package' => ['cmd/api', 'cmd/worker']])
        ->and(goToolingFor($facts, framework: 'chi'))->toMatchArray(['mains' => ['cmd/api' => 'api', 'cmd/worker' => 'worker'], 'web' => 'cmd/api']);
});

test('several packages that start a server are left for the person to pick, and so is a module with none', function (array $files, string $unsettled) {
    $detected = goDetect(goFacts(['go.mod' => goMod(), ...$files]));

    expect($detected['unsettled'])->toBe(['main_package' => $unsettled])
        ->and($detected['fields'] ?? [])->toBe(['main_package' => null]);
})->with([
    'two servers' => [['cmd/api/main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), nil)"), 'cmd/admin/main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"ADMIN_PORT\"), nil)")], 'Several packages start a server (cmd/admin, cmd/api). Pick the one that answers web requests.'],
    'two that start nothing Coresmith knows' => [['cmd/migrate/main.go' => goMain("\tmigrate()"), 'cmd/seed/main.go' => goMain("\tseed()")], 'Several packages declare package main (cmd/migrate, cmd/seed) and none starts a server Coresmith recognises. Pick the one that answers web requests.'],
    'none' => [['internal/api/api.go' => "package api\n"], "Coresmith found no package main at the repository's root or two folders below it, so it can't tell what to run. Type the folder of the package that starts the app, such as cmd/server."],
]);

test('examples, tools and files built only as tools are never apps to run', function () {
    $facts = goFacts([
        'go.mod' => goMod(),
        'main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), nil)"),
        'examples/hello/main.go' => goMain("\thttp.ListenAndServe(\":9000\", nil)"),
        'tools/gen/main.go' => goMain("\tgenerate()"),
        'tools.go' => "//go:build tools\n\npackage main\n",
    ]);

    expect(goDetect($facts)['options'])->toBe(['main_package' => ['.']]);
});

test('a port written into the code is refused with the file, the line and the change, unless something reads the port', function () {
    $hardcoded = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\thttp.ListenAndServe(\":8080\", nil)")]);
    $fallback = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\tif os.Getenv(\"PORT\") == \"\" {\n\t\thttp.ListenAndServe(\":8080\", nil)\n\t}")]);
    $other = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\tgo http.ListenAndServe(\"localhost:6060\", nil)\n\thttp.ListenAndServe(os.Getenv(\"MYAPP_LISTEN\"), app())")]);

    expect(goBlockers($hardcoded))->toBe([[
        'field' => 'repository',
        'reason' => 'main.go line 9 listens on port 8080, and Coresmith gives each app its own port in the PORT environment variable.',
        'fix' => 'Listen on ":"+os.Getenv("PORT") there instead of port 8080.',
    ]])
        ->and(goBlockers($fallback))->toBe([])
        ->and(goBlockers($other))->toBe([]);
});

test("Gin's Run() with no address and Viper's AutomaticEnv read PORT, so a debug server on a fixed port beside them is fine", function (string $body) {
    $facts = goFacts(['go.mod' => goMod(['github.com/gin-gonic/gin' => 'v1.10.0']), 'main.go' => goMain("\tgo http.ListenAndServe(\"localhost:6060\", nil)\n{$body}")]);

    expect(goBlockers($facts, framework: 'gin'))->toBe([]);
})->with([
    'Run()' => ["\tr.Run()"],
    'AutomaticEnv' => ["\tviper.AutomaticEnv()\n\tr.Run(viper.GetString(\"addr\"))"],
]);

test('the address variables and listen flags the code reads are set and passed for it', function () {
    $variables = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\thttp.ListenAndServe(os.Getenv(\"LISTEN_ADDR\"), nil)"), 'internal/config/config.go' => "package config\n\ntype Config struct {\n\tPort int `env:\"SERVER_PORT\"`\n}\n"]);
    $flag = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\taddr := flag.String(\"addr\", \":8080\", \"listen address\")\n\tflag.Parse()\n\thttp.ListenAndServe(*addr, nil)")]);
    $pflag = goFacts(['go.mod' => goMod(), 'main.go' => goMain("\tport := pflag.Int(\"port\", 8080, \"port\")\n\tpflag.Parse()\n\tserve(*port)")]);

    expect(goToolingFor($variables)['listen'])->toBe(['LISTEN_ADDR' => 'address', 'SERVER_PORT' => 'port'])
        ->and(goToolingFor($flag)['flag'])->toBe('-addr=127.0.0.1:${PORT}')
        ->and(goToolingFor($pflag)['flag'])->toBe('--port=${PORT}')
        ->and(goBlockers($flag))->toBe([]);
});

test('a gRPC service with no HTTP server is proxied over HTTP/2, and one with a gateway is not', function () {
    $grpc = goFacts(['go.mod' => goMod(['google.golang.org/grpc' => 'v1.70.0']), 'main.go' => goMain("\tlis, _ := net.Listen(\"tcp\", \":\"+os.Getenv(\"PORT\"))\n\ts := grpc.NewServer()\n\ts.Serve(lis)")]);
    $gateway = goFacts(['go.mod' => goMod(['google.golang.org/grpc' => 'v1.70.0']), 'main.go' => goMain("\ts := grpc.NewServer()\n\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), mux)")]);

    expect(goDetect($grpc)['framework'])->toBe('grpc')
        ->and(goToolingFor($grpc, framework: 'grpc')['grpc'])->toBeTrue()
        ->and(goToolingFor($gateway, framework: 'grpc')['grpc'])->toBeFalse();
});

test('templ, goose and vendored modules are read from go.mod and the vendor folder', function () {
    $tool = goFacts(['go.mod' => goMod(['github.com/a-h/templ' => 'v0.3.857'], "\ntool github.com/a-h/templ/cmd/templ\n"), 'main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), nil)")], ['vendor/modules.txt']);
    $required = goFacts(['go.mod' => goMod(['github.com/a-h/templ' => 'v0.3.857', 'github.com/pressly/goose/v3' => 'v3.24.1']), 'main.go' => goMain("\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), nil)")]);

    expect(goToolingFor($tool))->toMatchArray(['templ' => 'tool', 'vendored' => true, 'goose' => null])
        ->and(goToolingFor($required))->toMatchArray(['templ' => 'v0.3.857', 'vendored' => false, 'goose' => 'v3.24.1']);
});

test('a main package typed by hand is built and run even when the scan did not reach it', function () {
    $facts = goFacts(['go.mod' => goMod(), 'internal/api/api.go' => "package api\n"]);

    expect(goToolingFor($facts, 'cmd/shop/server'))->toMatchArray(['mains' => ['cmd/shop/server' => 'server'], 'web' => 'cmd/shop/server']);
});

test('apps Coresmith cannot build with go build are refused with the reason', function (array $files, string $reason) {
    expect(goBlockers(goFacts($files))[0]['reason'] ?? null)->toBe($reason);
})->with([
    'Encore' => [['go.mod' => goMod(['encore.dev' => 'v1.46.0']), 'main.go' => goMain('')], "This is an Encore app, which builds only with Encore's own compiler, and Coresmith builds Go apps with go build."],
    'Revel' => [['go.mod' => goMod(['github.com/revel/revel' => 'v1.1.0']), 'app/init.go' => "package app\n"], "This is a Revel app. Revel writes the app's main package during revel build, and Coresmith builds Go apps with go build."],
]);

test("a port set in Beego's or go-zero's config file is refused with the framework's way to read PORT", function (string $framework, array $files, string $fix) {
    expect(goBlockers(goFacts(['go.mod' => goMod(), 'main.go' => goMain("\tbeego.Run()"), ...$files]), framework: $framework)[0]['fix'] ?? null)->toBe($fix);
})->with([
    'Beego' => ['beego', ['conf/app.conf' => "appname = shop\nhttpport = 8080\nrunmode = prod\n"], 'Change it to httpport = ${PORT||8080}, which Beego reads from the environment.'],
    'go-zero' => ['go-zero', ['etc/shop-api.yaml' => "Name: shop-api\nHost: 0.0.0.0\nPort: 8888\n"], 'Set Port: ${PORT}, and load the file with conf.MustLoad(*configFile, &c, conf.UseEnv()) so go-zero reads it from the environment.'],
]);
