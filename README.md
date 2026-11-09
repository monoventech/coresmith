# Coresmith

Server management without the complexity. Coresmith sets up your own Linux servers and deploys your apps straight onto them, all from the browser: no terminal, no Docker.

**One server, every stack.** PHP apps such as Laravel, JavaScript and TypeScript apps on Node.js, Bun or Deno, Ruby apps such as Rails, and static sites can all share one server. Each app gets its own Linux user, its own block of ports and an environment file only root can read.

- **Servers set up for you.** Add a server with its password once, or by pasting one command on it, and Coresmith provisions it over SSH and hardens it, with key-only SSH, fail2ban and a firewall.
- **Push to deploy.** Connect GitHub or your own Git host. Every deploy builds a new release beside the live one, health-checks it, then switches over in one step, and the previous release stays ready to roll back to.
- **Everything an app needs.** HTTPS certificates, domains, environment variables, background processes, cron jobs, and MySQL, PostgreSQL and Redis databases, all set from the browser.

> **Licence:** Elastic License v2 (ELv2). See [LICENSE](LICENSE). Self-hosting is free with every feature; offering Coresmith to others as a hosted service is not allowed.

Coresmith Cloud runs Coresmith for you. Read about it at [coresmith.io](https://coresmith.io).

## Self-hosting

Run Coresmith yourself, free, with every feature. Setup takes four steps.

### What you need

- A Linux server with systemd and root access (`sudo`). Ubuntu 22.04 and 24.04 are tested; the installer also accepts Debian 11 and 12, Rocky Linux and AlmaLinux 8 and 9, Fedora and Amazon Linux.
- At least 5GB of free disk (10GB or more recommended) and 2GB of RAM or more (it runs with less, slowly).
- Ports 80 and 443 open to your users. The installer opens them. If a firewall is already on, it leaves that firewall's SSH rules as they are; if it turns ufw on itself, it keeps your SSH port open.
- For HTTPS on a name, a domain whose DNS points at the server. Without one Coresmith answers on the server's IP address.
- Give Coresmith a server of its own. It can't provision the server it runs on, so add other servers for your apps.

### 1. Point a domain at the server

To reach Coresmith on a name with HTTPS, point the domain's DNS at the server first: an A record, plus an AAAA record if the server has IPv6. Skip this step to use the server's IP address.

### 2. Run the installer

Import the release key and check its fingerprint:

    curl -fsSL https://raw.githubusercontent.com/monoventech/coresmith/main/resources/release-keys/coresmith-releases.asc | gpg --import
    gpg --fingerprint releases@coresmith.io

The fingerprint must read `10AD 393D 01B6 2CA5 524F  AEAB AF8B BF43 12DD D022`. The same key is in this repository's `resources/release-keys/` folder and at `https://get.coresmith.io/release-key.asc`.

Then download the installer and its signature from the latest release, check the signature, and run it as root:

    curl -fsSLO https://github.com/monoventech/coresmith/releases/latest/download/install.sh
    curl -fsSLO https://github.com/monoventech/coresmith/releases/latest/download/install.sh.asc
    gpg --verify install.sh.asc install.sh
    sudo bash install.sh --domain coresmith.example.com --email you@example.com

Go on only if `gpg --verify` reports a good signature from "Coresmith releases". Leave out `--domain` to use the server's IP address. `curl -fsSL https://get.coresmith.io/install | sudo bash` runs the same installer in one line.

You can give Coresmith a domain later from Settings, General: point the domain, or the server's Tailscale name, at the server, and Coresmith switches over once Caddy has a certificate for it. The old address then sends people to the new one.

The installer adds Caddy, PHP, Redis, Git, SQLite, Node.js, Bun and fail2ban, creates a `coresmith` user, installs the release, configures the firewall and starts the services. It takes these options:

| Option | What it does |
| --- | --- |
| `--domain <domain>` | Domain for Coresmith. Default: the server's IP address. |
| `--email <email>` | Email for Let's Encrypt certificates. |
| `--db <sqlite\|pgsql\|mysql>` | Database driver. Default: `sqlite`. |
| `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password` | Connection details for PostgreSQL or MySQL. |
| `--version <x.y.z>` | Install this release. Default: the release the installer belongs to. |
| `--branch <branch>` | Install a branch instead of a release, for development and CI. |
| `--unattended` | Never prompt; use the defaults. |
| `--verbose` | Show every command's output. |
| `--help` | Print the options and change nothing. |

### 3. Create the owner account

The installer ends with a one-time link, `https://<your address>/install?token=<token>`. Open it to create the owner account. It works once; after the owner exists it is cleared from the server. If you lose it, issue a new token on the server and put it after `/install?token=`:

    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:setup-token --force

### 4. Connect GitHub and add a server

Coresmith's **Finish setup** list walks you through the rest: connect GitHub (Coresmith first creates its own GitHub App, which you confirm on GitHub), add a server, provision it and make your first deploy.

### Running it

    systemctl status caddy coresmith-horizon coresmith-reverb
    tail -f /var/log/coresmith-horizon.log
    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:health

| Path | What lives there |
| --- | --- |
| `/opt/coresmith/app` | The release that is serving (a link into `/opt/coresmith/releases`) |
| `/opt/coresmith/shared` | The `.env` and `storage/`, kept across releases |
| `/var/lib/coresmith` | The SQLite database, when you chose SQLite, and update state |
| `/var/log/coresmith-*.log` | Coresmith's service logs |

**Backups.** Every night at 04:00 Coresmith backs up its own database and `.env` to `/opt/coresmith/shared/storage/app/private/backups`, keeping the latest 14. Settings, General, Backups has **Back up now**. Your apps' databases are separate: **Back up now** on a database's page dumps it on its server, and a team can send every MySQL and PostgreSQL database to its own S3 bucket each night from Settings, Team.

### Email

Coresmith emails invitations, password resets and alerts. Set `MAIL_MAILER` and the other `MAIL_*` settings in `/opt/coresmith/shared/.env`, for example `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS`, then load them:

    cd /opt/coresmith/app && sudo -u coresmith php artisan config:cache && sudo -u coresmith php artisan horizon:terminate

Until then nothing reaches anyone: inviting someone without an account gives you a link to copy and send them yourself.

### Updating

Settings, General, **Coresmith updates** shows the version you run and any newer release with its notes. **Update now** builds the new release beside the running one, checks its signature, backs up, migrates, switches over and checks Coresmith is healthy; if any step fails it puts the previous release back on its own. Turn on **Install patch updates automatically** to have patch releases installed at 04:00.

From the shell:

    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:update

### Removing Coresmith

    cd /opt/coresmith/app && sudo php artisan coresmith:uninstall

It asks you to type `uninstall` to confirm, saves the `.env`, the backups and, on SQLite, a copy of the database under `/root`, then removes Coresmith's services, files, Caddy site and user. If apps are deployed on the same machine, they keep running and the `coresmith` user stays for them.

## Development

    composer install
    bun install
    php artisan migrate:all --fresh
    composer dev

`composer dev` starts maildev, the Laravel server, the queue listener and Vite. See [CONTRIBUTING.md](CONTRIBUTING.md) for the checks every change passes before it is committed.
