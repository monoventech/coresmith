# Changelog

## v0.8.13 (2026-11-14)

### Added
- feat: Go apps, on plain net/http or any framework (#87)

## v0.8.12 (2026-11-14)

### Added
- feat: Developer SSH keys, a searchable Releases list, level Now cards and DNS advice that leads with Cloudflare's proxy (#85)

## v0.8.11 (2026-11-14)

### Added
- feat: Python apps on Django, Flask, FastAPI and Starlette, with uv-installed Python and Celery switches (#83)

## v0.8.10 (2026-11-14)

### Added
- feat: JavaScript presets, AdonisJS and Angular apps, and the SQLite lock wait (#81)

### Fixed
- fix: the move file downloads as a plain file in every browser, and the password test uses an address Linux refuses at once (#80)

### Changed
- ci: the MySQL job runs Pest as root, so each parallel worker can create its own test database (#79)

## v0.8.9 (2026-11-14)

### Added
- feat: language providers, Rack apps, apps in a repository's folders, settings that follow the stack, and the installer's copy (#77)

## v0.8.8 (2026-11-14)

### Fixed
- fix: copy across the app reads as a person wrote it, with no comma splices, no symlink or operations jargon, plain errors in place of Unable to, and the right roles named (#75)

## v0.8.7 (2026-11-13)

### Added
- feat: the DNS records a domain needs are ready to copy where it's added and checked, an IPv6-only domain says who can't reach it, and Cloudflare's proxy is recognised (#73)
- feat: an app's domains are checked from outside, and the advice for home servers only appears when the test shows IPv4 can't get through (#72)
- feat: Developers create servers on the team's cloud accounts and projects of their own, with full control of what they create and nothing else unless it's shared (#71)

## v0.8.6 (2026-11-13)

### Added
- feat: the Hardening tab shows each protection's real state and can turn it back on, and everyday messages stop naming Caddy, systemd and Linux users (#67)
- feat: a Developer role, GitHub connections that belong to one member, and app addresses without a domain (#65)

### Fixed
- fix: copy says plainly what happens, without fragment rows or claims the code can't keep, and the password reset email speaks like Coresmith (#69)
- fix: deploys reconnect after a corrupt SSH packet, Laravel and plain PHP apps get the database folder or .env they lack, Ruby gems get their build tools, and fibers is refused (#68)
- fix: PHP apps see Coresmith's environment in $_ENV, and deploys name the missing database or the fixed port an app listens on (#66)
- fix: a Laravel app's SQLite database lives in shared/database and survives deploys, copied once from the live release, and a rollback points an older release at it (#64)

## v0.8.5 (2026-11-13)

### Added
- feat: Add an app reads the repository and fills in a stack it is sure will deploy, and says why when it can't (#62)

### Changed
- test: every spec drops its route handlers when its page is torn down, so a request still in a handler as the context closes no longer fails a run (#61)

## v0.8.4 (2026-11-12)

### Added
- feat: the app page leads with its release, domains and source, runs the live log across, and lines its settings up in rows (#59)

## v0.8.3 (2026-11-12)

### Added
- feat: PHP follows composer.json and composer.lock together, a version chosen for an app is used, and the app page says when the lock holds PHP back (#57)

## v0.8.2 (2026-11-12)

### Fixed
- fix: the phone Apps row opens in place, live refreshes carry the trail, a failed subscription cuts no one off, and a page that goes away returns to the fleet (#55)

## v0.8.1 (2026-11-12)

### Added
- feat: a rail and a live explorer replace the sidebar, every page gets a breadcrumb trail, and the fleet page draws deploys, uptime and each server's last hour (#53)

### Changed
- docs: the README sends people to Coresmith Cloud and S3 backups, and no copy says self-hosting has every feature (#52)

## v0.8.0 (2026-11-12)

### Added
- feat: projects group apps and databases with their environments, and can be shared whole or one environment at a time (#50)

## v0.7.1 (2026-11-11)

### Added
- feat: Coresmith can be removed from Settings, databases restore from their backups, and This machine warns while password login is on (#48)

## v0.7.0 (2026-11-11)

### Added
- feat: each team member sees everything, or only the servers and apps an owner shares with them (#46)
- feat: Coresmith warns when its domain has only an IPv6 address, release notes read as plain lines, sizes never wrap, and the machine Coresmith runs on is handed back to its owner once (#45)

### Fixed
- fix: Coresmith leaves SSH on its own machine to its owner, and Detach takes Coresmith's key off a server before forgetting it (#44)

## v0.6.0 (2026-11-10)

### Added
- feat: a server's page says what each number is: cores, RAM, disk and the system's name, and each reading card shows the amounts behind its percentage (#42)

### Fixed
- fix: This machine keeps the SSH rules its firewall already has, says what is true about passwords and its state, and Add server starts on it (#41)

## v0.5.0 (2026-11-10)

### Added
- feat: Coresmith keeps its domain pointed at this server with a Cloudflare API token, and a failed address check can point it there (#38)
- feat: Coresmith says what stands between people and a working address: a cloud install behind NAT, and each check in Settings before a move (#37)
- feat: managed servers close the connection for unknown hostnames, and in-app updates refresh Coresmith's own Caddy site (#36)
- feat: apps on Coresmith's own machine run sandboxed in their own slice, Coresmith gets root there past an SSH guest list, and Add server points this machine's own address to This machine (#35)
- feat: an install with a domain comes up on the machine's own address and moves to the domain only once it works (#33)
- feat: before Coresmith moves to a domain, it checks from outside that the domain reaches this server and says exactly what to fix (#32)
- feat: every way to the first sign-in ends in a link to click, never a token to paste (#31)
- feat: Add server offers This machine, so a self-hosted Coresmith can deploy apps onto the machine it runs on (#26)
- feat: on Coresmith's own machine one Caddy serves Coresmith and its apps, and an address change swaps only Coresmith's routes (#25)
- feat: the installer readies Coresmith's own machine to host apps, with a root helper that only answers over loopback (#24)
- feat: Coresmith's address opens sign-in or your fleet, and the marketing page moves to coresmith.io (#23)
- feat: on Coresmith's own machine, runtimes get a user of their own, its logs are private, and the installer leaves app folders to provisioning (#22)

### Fixed
- fix: on a real install, Add server opens and provisioning this machine survives the library updates it installs (#39)

### Changed
- test: the countdown, installer and update checks no longer depend on the date, the version or folders Coresmith stopped making (#34)
- docs: the readme says only where to read about Coresmith Cloud, at coresmith.io (#30)

## v0.4.2 (2026-11-09)

### Fixed
- fix: on an install served on an IP address, HTTPS no longer hangs once its certificate is due for renewal (#27)

### Changed
- chore: a release's gate is the full CI, run on the exact commit its pull request opens (#28)

## v0.4.1 (2026-11-09)

### Fixed
- fix: SSH password logins really turn off, and the Hardening tab claims it only once sshd confirms it (#20)

## v0.4.0 (2026-11-09)

### Added
- feat: a database on the shared Redis moves to a Redis of its own, and the shared Redis turns off once nothing uses it (#17)
- feat: a new Redis database gets an instance of its own, on its own port, behind its own password (#16)
- feat: Caddy's admin API moves to a socket only Caddy and root can open (#12)
- feat: show and rotate a database's password, and keep passwords out of the command log (#11)

### Fixed
- fix: Coresmith's own Redis gets a password, so apps on its machine can't read its queue or sessions (#18)
- fix: database passwords reach MySQL and PostgreSQL on stdin, so they never show in the server's process list (#15)
- fix: deleting a database drops it and its user from the server, once its name is typed (#14)
- fix: a database password is shown once, as the spec says, then only rotating gives a new one (#13)

## v0.3.0 (2026-11-09)

### Added
- feat: self-hosted Coresmith can change its address, with a certificate, from Settings (#9)
- feat: connect a server with a password once, or one command, instead of copying keys (#8)

## v0.2.1 (2026-11-08)

### Fixed
- fix: say Coresmith instead of panel everywhere people read it (#5)
- fix: a squash-merged release still knows what it shipped (#4)

## v0.2.0 (2026-11-08)

### Added
- feat: move a team from Coresmith Cloud to a self-hosted panel, servers and all
- feat: give a cloud team a free plan from the command line

### Changed
- docs: lead the readme with every stack on one server, run from the browser (#2)
- chore: release through a pull request into main that can leave changes out (#1)
- docs: a clearer readme on self-hosting and what Coresmith Cloud adds
- test: a trial or free plan with under two days left still reaches checkout

## v0.1.0 (2026-11-07)

Initial release.
