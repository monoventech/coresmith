# Changelog

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
