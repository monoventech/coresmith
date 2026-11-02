# Coresmith

Coresmith is a source-available panel for running your own servers. It provisions Linux servers over SSH, deploys web apps (PHP, JavaScript and TypeScript, Ruby) from Git, and manages their databases, certificates, firewalls, processes and cron jobs, all without Docker. Run it yourself for free, or use Coresmith Cloud.

> **Licence:** Elastic License v2 (ELv2). See [LICENSE](LICENSE). Self-hosting is free with every feature; offering Coresmith to others as a hosted service is not allowed.

## Before the first release

The public repository (`monoven/coresmith`), its first release and the `get.coresmith.io` address are not live yet, and releases are not signed until the release key exists. The steps below are how installing works once they are. Until then, install a branch from a clone with `--branch`, as the installer's own tests do.

## Requirements

- A Linux server with systemd and root access (`sudo`). Ubuntu 22.04 and 24.04 are tested; the installer also accepts Debian 11 and 12, Rocky Linux and AlmaLinux 8 and 9, Fedora and Amazon Linux.
- At least 5GB of free disk (10GB or more recommended) and 2GB of RAM or more (it runs with less, slowly).
- Ports 80 and 443 open to your users. The installer keeps your SSH port open.
- For HTTPS on a name, a domain whose DNS points at the server. Without one the panel answers on the server's IP address.

## Install

Download the installer and its signature from the latest release, check the signature, then run it as root:

    curl -fsSLO https://github.com/monoven/coresmith/releases/latest/download/install.sh
    curl -fsSLO https://github.com/monoven/coresmith/releases/latest/download/install.sh.asc
    gpg --verify install.sh.asc install.sh
    sudo bash install.sh --domain panel.example.com --email you@example.com

Import the release key from this repository's `resources/release-keys/` folder before `gpg --verify`. Once `get.coresmith.io` is live, `curl -fsSL https://get.coresmith.io/install | sudo bash` runs the same installer in one line.

The installer adds Caddy, PHP, Redis, Git, SQLite, Node.js, Bun and fail2ban, creates a `coresmith` user, installs the release, configures the firewall and starts the services.

| Option | What it does |
| --- | --- |
| `--domain <domain>` | Domain for the panel. Default: the server's IP address. |
| `--email <email>` | Email for Let's Encrypt certificates. |
| `--db <sqlite\|pgsql\|mysql>` | Database driver. Default: `sqlite`. |
| `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password` | Connection details for PostgreSQL or MySQL. |
| `--version <x.y.z>` | Install this release. Default: the release the installer belongs to. |
| `--branch <branch>` | Install a branch instead of a release, for development and CI. |
| `--unattended` | Never prompt; use the defaults. |
| `--verbose` | Show every command's output. |
| `--help` | Print the options and change nothing. |

### Finish setup

The installer ends with a one-time link, `https://<your panel>/install?token=<token>`. Open it to create the owner account. It works once; after the owner exists it is cleared from the server. If you lose it, issue a new token on the server and put it after `/install?token=`:

    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:setup-token --force

The panel then walks you through connecting GitHub and adding your first server.

## Running it

    systemctl status caddy coresmith-horizon coresmith-reverb
    tail -f /var/log/coresmith-horizon.log
    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:health

| Path | What lives there |
| --- | --- |
| `/opt/coresmith/app` | The release that is serving (a link into `/opt/coresmith/releases`) |
| `/opt/coresmith/shared` | The `.env` and `storage/`, kept across releases |
| `/var/lib/coresmith` | The SQLite database, when you chose SQLite, and update state |
| `/var/log/coresmith-*.log` | The panel's service logs |

**Backups.** Every night at 04:00 the panel backs up its own database and `.env` to `/opt/coresmith/shared/storage/app/private/backups`, keeping the latest 14. Settings, General, Backups has **Back up now**. Your apps' databases are separate: **Back up now** on a database's page dumps it on its server, and a team can send every MySQL and PostgreSQL database to its own S3 bucket each night from Settings, Team (on Coresmith Cloud, that is part of Business).

## Email

The panel emails invitations, password resets and alerts. Set `MAIL_MAILER` and the other `MAIL_*` settings in `/opt/coresmith/shared/.env`, for example `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS`, then load them:

    cd /opt/coresmith/app && sudo -u coresmith php artisan config:cache && sudo -u coresmith php artisan horizon:terminate

Until then nothing reaches anyone: inviting someone without an account gives you a link to copy and send them yourself.

## Updating

Settings, General, **Panel updates** shows the version you run and any newer release with its notes. **Update now** builds the new release beside the running one, checks its signature, backs up, migrates, switches over and checks the panel is healthy; if any step fails it puts the previous release back on its own. Turn on **Install patch updates automatically** to have patch releases installed at 04:00.

From the shell:

    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:update

## Removing Coresmith

    cd /opt/coresmith/app && sudo php artisan coresmith:uninstall

It asks you to type the panel's host to confirm, saves the `.env`, the backups and, on SQLite, a copy of the database under `/root`, then removes the panel's services, files, Caddy site and user. Apps deployed on the same machine keep running.

## Development

    composer install
    bun install
    php artisan migrate:all --fresh
    composer dev

`composer dev` starts maildev, the Laravel server, the queue listener and Vite. See [CONTRIBUTING.md](CONTRIBUTING.md) for the checks every change passes before it is committed.
