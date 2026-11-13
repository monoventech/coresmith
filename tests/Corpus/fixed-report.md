# The fixed set

40 repositories that cover every stack Coresmith fills in (`fixed.json`), each with the verdict and stack Coresmith must reach. A release needs a pass on the exact detection and deploy code (`codeHash.ts`), which `scripts/release.sh` checks.

## Gate run, 2026-11-13

- Code 264a3264ee2d91f0, Coresmith at 5155b3d, on Lima: arm64 Ubuntu 24.04, 4 CPU, 6 GiB.
- 40 of 40 as expected: 25 ready, 9 not sure, 6 refused.
- 25 of 25 ready repositories deployed and answered.

## Earlier runs

- ae6142c: 40 of 40 as expected, 25 of 25 deployed.
- 36b76e5: 39 of 40. nest-api crash-looped because nest start compiles TypeScript inside the 256 MB Small limit; f6abd63 runs start:prod instead.
- Replaced along the way because their failures were their own: rails-api-jwt (Rails credentials it doesn't carry), rails-api-my-api (Ruby 3.3.0 segfault), hono-bun (nirnejak's starter needs Neon), rails-rodauth-demo (turbo-rails without Action Cable) and nest-api (MyAPI's Nest CLI 7 hangs on Node 24).
