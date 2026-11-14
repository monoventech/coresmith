<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\Blocker;
use App\Services\Detection\RepositoryReader;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\ResolvedRuntime;
use App\Stacks\PythonPresets\PythonPresets;

function pyFacts(array $files, array $markers = []): RepositoryFacts
{
    return new RepositoryFacts($files, markers: $markers, complete: true);
}

function pyManage(string $settings = 'shop.settings'): string
{
    return "#!/usr/bin/env python\nimport os\nimport sys\n\n\ndef main():\n    os.environ.setdefault(\"DJANGO_SETTINGS_MODULE\", \"{$settings}\")\n    from django.core.management import execute_from_command_line\n    execute_from_command_line(sys.argv)\n";
}

function pySettings(string $extra = "WSGI_APPLICATION = 'shop.wsgi.application'\nSTATIC_ROOT = BASE_DIR / 'staticfiles'\n"): string
{
    return "import os\nfrom pathlib import Path\n\nBASE_DIR = Path(__file__).resolve().parent.parent\nSECRET_KEY = os.environ['SECRET_KEY']\nDEBUG = False\nALLOWED_HOSTS = os.environ.get('ALLOWED_HOSTS', '').split(',')\nSTATIC_URL = 'static/'\n" . $extra;
}

function pyDetect(RepositoryFacts $facts): ?array
{
    return ApplicationType::Python->language()->detect($facts);
}

function pyTooling(?string $framework, RepositoryFacts $facts): array
{
    return ApplicationType::Python->language()->tooling(new Application(['type' => ApplicationType::Python, 'framework' => $framework]), $facts);
}

function pyBlockers(?string $framework, RepositoryFacts $facts, array $attributes = []): array
{
    return array_map(fn(Blocker $blocker): array => $blocker->toArray(), ApplicationType::Python->language()->checks(new Application(['type' => ApplicationType::Python, 'framework' => $framework, ...$attributes]), (string) $framework, $facts, 'x86_64'));
}

test('a Django project runs the WSGI application its settings name, and collects static files into STATIC_ROOT', function () {
    $facts = pyFacts(['requirements.txt' => "Django==5.1.4\npsycopg2==2.9.10\n", 'manage.py' => pyManage(), 'shop/settings.py' => pySettings(), 'shop/wsgi.py' => "application = get_wsgi_application()\n"]);

    expect(pyDetect($facts))->toBe(['type' => 'python', 'framework' => 'django', 'js_runtime' => null, 'web' => null, 'sources' => ['type' => 'From requirements.txt', 'framework' => 'django 5.1.4 in requirements.txt, with manage.py'], 'unsettled' => []])
        ->and(pyTooling('django', $facts))->toBe(['manager' => 'pip', 'server' => 'gunicorn', 'module' => 'shop.wsgi:application', 'collectstatic' => true, 'migrate' => 'django', 'celery' => null])
        ->and(pyBlockers('django', $facts))->toBe([]);
});

test('settings split across files are all read, whichever one sets the application', function () {
    $facts = pyFacts([
        'pyproject.toml' => "[project]\nname = \"shop\"\ndependencies = [\"django>=5.1\", \"gunicorn\"]\n",
        'manage.py' => pyManage('config.settings.local'),
        'config/settings/base.py' => pySettings("WSGI_APPLICATION = \"config.wsgi.application\"\nSTATIC_ROOT = str(BASE_DIR / 'staticfiles')\n"),
        'config/settings/local.py' => "from .base import *  # noqa\nDEBUG = True\n",
        'config/wsgi.py' => "application = get_wsgi_application()\n",
    ], ['uv.lock']);

    expect(pyDetect($facts)['sources'])->toBe(['type' => 'From pyproject.toml and uv.lock', 'framework' => 'django in pyproject.toml, with manage.py'])
        ->and(pyTooling('django', $facts))->toBe(['manager' => 'uv', 'server' => 'gunicorn', 'module' => 'config.wsgi:application', 'collectstatic' => true, 'migrate' => 'django', 'celery' => null]);
});

test('a Django project with an ASGI application, as Channels sets, runs it under uvicorn', function () {
    $facts = pyFacts(['requirements.txt' => "django\nchannels\n", 'manage.py' => pyManage(), 'shop/settings.py' => pySettings("WSGI_APPLICATION = 'shop.wsgi.application'\nASGI_APPLICATION = 'shop.asgi.application'\n")]);

    expect(pyTooling('django', $facts))->toBe(['manager' => 'pip', 'server' => 'uvicorn', 'module' => 'shop.asgi:application', 'collectstatic' => false, 'migrate' => 'django', 'celery' => null]);
});

test('without WSGI_APPLICATION in its settings, a Django project runs the wsgi.py beside them', function () {
    $facts = pyFacts(['requirements.txt' => "django\n", 'manage.py' => pyManage(), 'shop/settings.py' => pySettings(''), 'shop/wsgi.py' => "application = get_wsgi_application()\n"]);

    expect(pyTooling('django', $facts)['module'])->toBe('shop.wsgi:application');
});

test('a Django project with no application to load is unsettled and refused, saying what to add', function () {
    $facts = pyFacts(['requirements.txt' => "django\n", 'manage.py' => pyManage(), 'shop/settings.py' => pySettings('')]);

    expect(pyDetect($facts)['unsettled'])->toBe(['framework' => "manage.py names shop.settings, but those settings don't set WSGI_APPLICATION and there is no shop/wsgi.py, so Coresmith can't tell what gunicorn should load. Commit the shop/wsgi.py that django-admin startproject writes."])
        ->and(pyBlockers('django', $facts))->toBe([['field' => 'framework', 'reason' => "manage.py names shop.settings, but those settings don't set WSGI_APPLICATION and there is no shop/wsgi.py, so Coresmith can't tell what gunicorn should load.", 'fix' => 'Commit the shop/wsgi.py that django-admin startproject writes.']]);
});

test('a manage.py that names no settings module points at the settings file Coresmith found, or at none', function () {
    $manage = "from django.core.management import execute_from_command_line\n";

    expect(pyDetect(pyFacts(['requirements.txt' => "django\n", 'manage.py' => $manage, 'config/settings/base.py' => pySettings('')]))['unsettled']['framework'])
        ->toBe("config/settings/base.py doesn't set WSGI_APPLICATION and there is no config/wsgi.py, so Coresmith can't tell what gunicorn should load. Commit the config/wsgi.py that django-admin startproject writes.")
        ->and(pyDetect(pyFacts(['requirements.txt' => "django\n", 'manage.py' => $manage]))['unsettled']['framework'])
        ->toBe("Coresmith found no settings that set WSGI_APPLICATION and no wsgi.py beside them, so it can't tell what gunicorn should load. Commit the wsgi.py that django-admin startproject writes, next to settings.py.");
});

test('django without manage.py is a package for Django, not a project', function () {
    expect(pyDetect(pyFacts(['pyproject.toml' => "[project]\nname = \"django-thing\"\ndependencies = [\"django>=4.2\"]\n"]))['unsettled'])
        ->toBe(['framework' => 'django is in pyproject.toml, but there is no manage.py, so this looks like a package for Django, not a Django project. Pick the folder that holds manage.py, or the framework the app uses.']);
});

test('Django refuses settings that only allow an empty ALLOWED_HOSTS, which answers 400 on every domain', function () {
    $facts = pyFacts(['requirements.txt' => "django\n", 'manage.py' => pyManage(), 'shop/settings.py' => "DEBUG = True\nALLOWED_HOSTS = []\nWSGI_APPLICATION = 'shop.wsgi.application'\n"]);

    expect(pyBlockers('django', $facts))->toBe([[
        'field' => 'repository',
        'reason' => "shop/settings.py sets ALLOWED_HOSTS = [], so Django answers 400 Bad Request to every visit on the app's domain.",
        'fix' => 'Read it from the environment, for example ALLOWED_HOSTS = os.environ.get("ALLOWED_HOSTS", "").split(","), then add ALLOWED_HOSTS with your domain to the app\'s environment.',
    ]]);
});

test('Django refuses a fixed ALLOWED_HOSTS that leaves out the app\'s domain, and accepts one that names it', function (string $hosts, array $attributes, bool $refused) {
    $facts = pyFacts(['requirements.txt' => "django\n", 'manage.py' => pyManage('locallibrary.settings'), 'locallibrary/settings.py' => "ALLOWED_HOSTS = {$hosts}\nWSGI_APPLICATION = 'locallibrary.wsgi.application'\n"]);

    expect(pyBlockers('django', $facts, $attributes))->toBe($refused ? [[
        'field' => 'repository',
        'reason' => 'locallibrary/settings.py sets ALLOWED_HOSTS to .railway.app, .pythonanywhere.com and 127.0.0.1, so Django answers 400 Bad Request to library.example.com.',
        'fix' => 'Add library.example.com to ALLOWED_HOSTS, or read the list from the environment, for example ALLOWED_HOSTS = os.environ.get("ALLOWED_HOSTS", "").split(","), then add ALLOWED_HOSTS with your domain to the app\'s environment.',
    ]] : []);
})->with([
    'a list for other hosts' => ["['.railway.app', '.pythonanywhere.com', '127.0.0.1']", ['domain' => 'library.example.com'], true],
    'an additional domain left out' => ["['.railway.app', '.pythonanywhere.com', '127.0.0.1']", ['domain' => 'app.railway.app', 'additional_domains' => ['library.example.com']], true],
    'the domain named exactly' => ["['library.example.com']", ['domain' => 'Library.Example.com'], false],
    'a parent domain with a leading dot' => ['[".example.com"]', ['domain' => 'library.example.com'], false],
    'a wildcard' => ["['*']", ['domain' => 'library.example.com'], false],
    'no domain yet' => ["['.railway.app', '.pythonanywhere.com', '127.0.0.1']", [], false],
]);

test('ALLOWED_HOSTS set inside a branch, or built at runtime, is left to the app', function (string $settings) {
    $facts = pyFacts(['requirements.txt' => "django\n", 'manage.py' => pyManage(), 'shop/settings.py' => $settings . "WSGI_APPLICATION = 'shop.wsgi.application'\n"]);

    expect(pyBlockers('django', $facts, ['domain' => 'shop.example.com']))->toBe([]);
})->with([
    'branches, one of them open to all' => ["if IS_HEROKU_APP:\n    ALLOWED_HOSTS = [\"*\"]\nelse:\n    ALLOWED_HOSTS = [\".localhost\", \"127.0.0.1\"]\n"],
    'read from the environment' => ["ALLOWED_HOSTS = os.environ['ALLOWED_HOSTS'].split(',')\n"],
    'extended later' => ["ALLOWED_HOSTS = ['127.0.0.1']\nALLOWED_HOSTS += env.list('EXTRA_HOSTS')\n"],
]);

test('a Flask app runs the app its module makes', function (array $files, array $markers, string $module, string $source) {
    $facts = pyFacts($files, $markers);

    expect(pyDetect($facts)['framework'])->toBe('flask')
        ->and(pyDetect($facts)['sources']['framework'])->toBe($source)
        ->and(pyTooling('flask', $facts))->toBe(['manager' => $markers === [] ? 'pip' : 'poetry', 'server' => 'gunicorn', 'module' => $module, 'collectstatic' => false, 'migrate' => null, 'celery' => null]);
})->with([
    'app.py' => [['requirements.txt' => "Flask==3.1.0\n", 'app.py' => "from flask import Flask\n\napp = Flask(__name__)\n"], [], 'app:app', 'flask 3.1.0 in requirements.txt'],
    'a factory in a package' => [['pyproject.toml' => "[tool.poetry.dependencies]\npython = \"^3.12\"\nflask = \"^3.1\"\n", 'shop/__init__.py' => "from flask import Flask\n\n\ndef create_app(config=None):\n    app = Flask(__name__)\n    return app\n"], ['poetry.lock'], 'shop:create_app()', 'flask in pyproject.toml'],
    'wsgi.py calling the factory' => [['requirements.txt' => "flask\n", 'wsgi.py' => "from shop import create_app\n\napp = create_app()\n", 'shop/__init__.py' => "def create_app():\n    pass\n"], [], 'wsgi:app', 'flask in requirements.txt'],
]);

test('a FastAPI or Starlette app runs under uvicorn, and Alembic migrates when the app has it', function (array $files, array $markers, string $framework, array $tooling) {
    $facts = pyFacts($files, $markers);

    expect(pyDetect($facts)['framework'])->toBe($framework)
        ->and(pyTooling($framework, $facts))->toBe($tooling);
})->with([
    'app/main.py with Alembic' => [['pyproject.toml' => "[project]\nname = \"shop\"\ndependencies = [\"fastapi[standard]>=0.115\", \"alembic\", \"sqlmodel\"]\n", 'app/main.py' => "from fastapi import FastAPI\n\napp: FastAPI = FastAPI(title=\"Shop\")\n"], ['alembic.ini'], 'fastapi', ['manager' => 'pyproject', 'server' => 'uvicorn', 'module' => 'app.main:app', 'collectstatic' => false, 'migrate' => 'alembic', 'celery' => null]],
    'a factory in main.py' => [['requirements.txt' => "fastapi\n", 'main.py' => "from fastapi import FastAPI\n\n\ndef create_app() -> FastAPI:\n    return FastAPI()\n"], [], 'fastapi', ['manager' => 'pip', 'server' => 'uvicorn', 'module' => 'main:create_app()', 'collectstatic' => false, 'migrate' => null, 'celery' => null]],
    'Starlette' => [['requirements.txt' => "starlette\n", 'main.py' => "from starlette.applications import Starlette\n\napp = Starlette(routes=[])\n"], [], 'starlette', ['manager' => 'pip', 'server' => 'uvicorn', 'module' => 'main:app', 'collectstatic' => false, 'migrate' => null, 'celery' => null]],
]);

test('a factory runs through uvicorn\'s --factory flag', function () {
    $application = new Application(['type' => ApplicationType::Python, 'framework' => 'fastapi', 'slug' => 'shop', 'base_port' => 4300, 'stack' => ['language_tooling' => ['manager' => 'pip', 'server' => 'uvicorn', 'module' => 'main:create_app()']]]);

    expect(ApplicationType::Python->language()->webCommand($application))->toBe('/var/coresmith/apps/shop/current/.venv/bin/uvicorn --host 127.0.0.1 --port 4300 --factory main:create_app');
});

test('an app on another framework runs what the Procfile\'s web line names', function (string $procfile, string $program, string $server, string $module) {
    $facts = pyFacts(['requirements.txt' => "bottle\ngunicorn\nuvicorn\n", 'Procfile' => $procfile]);

    expect(pyDetect($facts))->toBe(['type' => 'python', 'framework' => '', 'js_runtime' => null, 'web' => null, 'sources' => ['type' => 'From requirements.txt', 'framework' => "web: {$program} in Procfile"], 'unsettled' => []])
        ->and(pyTooling(null, $facts))->toBe(['manager' => 'pip', 'server' => $server, 'module' => $module, 'collectstatic' => false, 'migrate' => null, 'celery' => null]);
})->with([
    'gunicorn' => ["web: gunicorn hello:app --log-file -\nworker: python worker.py\n", 'gunicorn', 'gunicorn', 'hello:app'],
    'gunicorn with uvicorn workers' => ["web: gunicorn -w 4 -k uvicorn.workers.UvicornWorker main:app\n", 'gunicorn', 'uvicorn', 'main:app'],
    'uvicorn through python -m' => ["web: python -m uvicorn api:app --host 0.0.0.0 --port \$PORT\n", 'uvicorn', 'uvicorn', 'api:app'],
    'options with values before the app' => ["web: gunicorn --bind 0.0.0.0:\$PORT --workers=3 shop.wsgi:application\n", 'gunicorn', 'gunicorn', 'shop.wsgi:application'],
]);

test('a Procfile that starts the app some other way is unsettled, with the line to write instead', function () {
    expect(pyDetect(pyFacts(['requirements.txt' => "gunicorn\n", 'Procfile' => "web: python app.py\n"]))['unsettled'])
        ->toBe(['framework' => 'Procfile starts the app with python app.py, and Coresmith runs Python apps with gunicorn or uvicorn. Change the web line to name the app, for example web: gunicorn app:app.']);
});

test('Python files with no web framework or Procfile are unsettled when no other language claims the repository', function () {
    expect(pyDetect(pyFacts(['requirements.txt' => "requests\n"])))->toBe(['type' => 'python', 'framework' => null, 'js_runtime' => null, 'web' => null, 'sources' => ['type' => 'From requirements.txt'], 'unsettled' => [
        'framework' => "requirements.txt names no web framework Coresmith knows, and there is no Procfile, so Coresmith can't tell how to start the app. Add a Procfile with a web line, for example web: gunicorn app:app.",
    ]])
        ->and(pyDetect(pyFacts(['requirements.txt' => "mkdocs\n", 'package.json' => '{"scripts":{"start":"node server.js"}}'])))->toBeNull()
        ->and(pyDetect(pyFacts(['package.json' => '{"scripts":{"start":"node server.js"}}'])))->toBeNull();
});

test('a lockfile names the tool even when requirements.txt is there too', function () {
    $facts = pyFacts(['requirements.txt' => "django\n", 'pyproject.toml' => "[tool.poetry.dependencies]\npython = \"^3.12\"\ndjango = \"^5.1\"\n", 'manage.py' => pyManage(), 'shop/wsgi.py' => "application = 1\n"], ['poetry.lock']);

    expect(pyDetect($facts)['sources']['type'])->toBe('From pyproject.toml and poetry.lock')
        ->and(pyTooling('django', $facts)['manager'])->toBe('poetry');
});

test('a Pipfile without its lockfile is refused, since Coresmith installs from the lock', function () {
    expect(pyBlockers('flask', pyFacts(['Pipfile' => "[packages]\nflask = \"*\"\n", 'app.py' => "app = Flask(__name__)\n"])))->toBe([[
        'field' => 'repository',
        'reason' => 'There is no Pipfile.lock, and Coresmith installs Pipenv apps from their lockfile.',
        'fix' => 'Run pipenv lock, then commit Pipfile.lock.',
    ]]);
});

test('a framework picked by hand has to be among the app\'s dependencies', function () {
    expect(pyBlockers('django', pyFacts(['requirements.txt' => "flask\n", 'app.py' => "app = Flask(__name__)\n"])))->toBe([[
        'field' => 'framework',
        'reason' => "django isn't in requirements.txt, so this isn't a Django app.",
        'fix' => 'Pick the framework the app uses, or add django to requirements.txt.',
    ]]);
});

test('Python asks for a version when Django is pinned to a release older than the Python Coresmith would pick', function () {
    $default = new ResolvedRuntime('python', '3.14.10', ResolvedRuntime::FROM_DEFAULT, null, false);
    $named = new ResolvedRuntime('python', '3.12.16', '.python-version', '3.12', false);
    $facts = pyFacts(['requirements.txt' => "Django==4.2.17\n", 'manage.py' => pyManage()]);

    expect(ApplicationType::Python->language()->versionDoubt($facts, $default))->toBe('Nothing names a Python version, so Coresmith would pick Python 3.14.10, and Django 4.2 supports Python 3.12 at most. Add a .python-version with the Python the app runs on, for example 3.12.')
        ->and(ApplicationType::Python->language()->versionDoubt($facts, $named))->toBeNull()
        ->and(ApplicationType::Python->language()->versionDoubt(pyFacts(['requirements.txt' => "Django==5.2.8\n"]), $default))->toBeNull();
});

test('Django and Flask apps get a SECRET_KEY when they are added, which a committed .env keeps', function () {
    expect(array_keys(ApplicationType::Python->language()->initialEnvironment('django')))->toBe(['SECRET_KEY'])
        ->and(strlen(ApplicationType::Python->language()->initialEnvironment('flask')['SECRET_KEY']))->toBe(64)
        ->and(ApplicationType::Python->language()->initialEnvironment('fastapi'))->toBe([])
        ->and(RepositoryProbe::ENV_SECRETS)->toContain('SECRET_KEY');
});

test('the presets name Python\'s frameworks for the form and the app page', function () {
    expect(app(PythonPresets::class)->choices())->toBe([
        ['value' => 'django', 'label' => 'Django'],
        ['value' => 'fastapi', 'label' => 'FastAPI'],
        ['value' => 'starlette', 'label' => 'Starlette'],
        ['value' => 'flask', 'label' => 'Flask'],
    ])
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Python, 'framework' => 'fastapi'])))->toBe('FastAPI')
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Python, 'framework' => null])))->toBe('Python');
});

test('the probe reads Django settings and app modules one folder down, wherever the project is named', function () {
    $script = app(RepositoryProbe::class)->script();
    $facts = app(RepositoryProbe::class)->parse(implode("\n", [
        '@@file shop/settings.py ' . base64_encode('DEBUG = False'),
        '@@file config/settings/production.py ' . base64_encode('DEBUG = False'),
        '@@file app/main.py ' . base64_encode('app = FastAPI()'),
        '@@file shop/apps/orders/models.py ' . base64_encode('x = 1'),
        '@@file shop/models.py ' . base64_encode('x = 1'),
        '@@done',
    ]));

    expect($script)->toContain('for f in ' . implode(' ', RepositoryProbe::GLOBS) . '; do')
        ->and(array_keys($facts->files))->toBe(['shop/settings.py', 'config/settings/production.py', 'app/main.py'])
        ->and(app(RepositoryReader::class)->script('https://github.com/monoven/shop.git', 'main', ''))->toContain("'/*/settings.py'");
});
