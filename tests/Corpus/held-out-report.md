# A held-out sample of public repositories

77 public repositories that the stack detection was never tuned on, deployed through Coresmith's own UI on 2026-11-13. The aim was to see whether the 99.4% bar from `sample-report.md`, measured on the repositories the fixes were tuned on, holds on new ones.

## How it was drawn

- 59 repositories come from `tests/Corpus/sample.ts` with seed 3 at a quarter of the usual quotas. They exclude every earlier set: `fixed.json`, samples 1 and 2, and the reruns.
    - Code search returned no recent Rails, Sinatra or static repositories that weren't already used, because the earlier samples had taken the recent ones.
- The other 18 come from GitHub's repository search: 10 Rails, 4 Sinatra and 4 static.
    - Each was pushed within 18 months, is not a fork, and is under 100 MB.
    - Each was confirmed by its own files: `railties` or `sinatra` in Gemfile.lock, or an index.html with no package.json.
    - Each was picked in a seeded order with the same exclusions.
- The merged set is `storage/corpus/held-3.json`.

## First pass, before any fix (code hash 898b7b60c7de1114)

- Repositories: 77
- Verdicts: ready 57, unsure 10, blocked 10
- Coverage, filled in completely: 57 (74.0%)
- Strict, deployed and answering: 30 of 57 (52.6%)
- The bar, passes / (passes + Coresmith's failures): 30 of 35 (85.7%)
- Failures that were the apps' own: 22.
- `classified.json` records each repository's latest cause. Running `report.ts` on the first-pass files alone therefore gives a different bar than the 85.7% above, which counts the five below as Coresmith's, as they were then.
    - Most need a service or variable the corpus doesn't provide: MySQL, PostgreSQL, MongoDB, JWT_SECRET, NEON_AUTH_BASE_URL.
    - The others have an out-of-date lockfile, or an error in the app's own code or config.

## Coresmith's failures, each fixed with tests

- **artur-laptev/spot-prices:** a Laravel repository with no `database/` folder, so migrate found no SQLite file. Fixed in 0.8.6.
- **GHmolliss/slim-app:** a plain PHP app whose `Dotenv::load()` needs a `.env`. Fixed in 0.8.6.
- **e621ng/e621ng:** rugged needs cmake, and mrml, through rb_sys, needs libclang. Fixed in 0.8.6.
- **rails/rails-contributors:** rugged needs cmake. Fixed in 0.8.6.
- **ibibhu/wedding:** fibers builds on no Node.js Coresmith installs, but detection called it ready. Fixed in 0.8.6, which refuses it with that reason.

The fixed set (`fixed.json`, 40 repositories) passed 40 of 40 on the fixed code (code hash 4c7ff5da5088ca79).

## The five, deployed again on the fixed code (code hash 4c7ff5da5088ca79)

- **ibibhu/wedding** is now refused, with the fibers reason.
- **artur-laptev/spot-prices** now migrates, then answers 500. Laravel's database sessions need a sessions table, and the repository has no migration for one.
- **GHmolliss/slim-app** now loads its `.env`, then answers 500. Its own EnvHelper needs APP_ENV, and the corpus sets no variables.
- **rails/rails-contributors** now compiles rugged, after the deploy installed cmake. Its build then needs a git mirror of the Rails repository on the server.
- **e621ng/e621ng** now compiles rugged and mrml, after the deploy installed libclang. Its migrations then load `config/danbooru_local_config`, which the repository doesn't commit.

Each now stops on a gap of its own, so each is classified as the app's, with its first failure recorded as Coresmith's.

## Each repository's latest result

- Repositories: 77
- Verdicts: ready 56, unsure 10, blocked 11
- Coverage, filled in completely: 56 (72.7%)
- Strict, deployed and answering: 30 of 56 (53.6%)
- The bar: 30 of 30 (100%). No Coresmith failure remains on this set.
- Failures that were the apps' own: 26

## What this says

On new repositories, the first pass scored 85.7%, not 99.4%. The difference was five Coresmith gaps, now fixed. Each is a class of failure the tuned sample didn't contain.

- After the fixes, this set shows no Coresmith failure. It has now been used for tuning too, so the next held-out sample, drawn after these fixes ship, is the honest measure again.
- The node is arm64 (Lima on Apple silicon). An x86 server would differ for gems and packages that only ship x86 builds. No x86 machine was available, so that remains untested.
