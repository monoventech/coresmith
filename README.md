# Coresmith

Coresmith is a source-available panel for running your own servers. It provisions Linux servers over SSH, deploys web apps (PHP, JavaScript and TypeScript, Ruby) from Git, and manages their databases, certificates, firewalls, processes and cron jobs, all without Docker.

> **Licence:** Elastic License v2 (ELv2). See [LICENSE](LICENSE). Self-hosting is free with every feature; offering Coresmith to others as a hosted service is not allowed.

## Cloud or self-hosted

Use Coresmith Cloud and we run the panel for you, or self-host it for free. Either way your apps run on your own servers, which the panel manages over SSH, and the self-hosted version has every feature Cloud has.

| | Coresmith Cloud | Self-hosted |
| --- | --- | --- |
| Who runs the panel | We do, on our servers | You do, on a server of yours |
| Upkeep | We update it, back it up and keep its certificates and email working | You update it (one click) and look after its server and email |
| GitHub | Coresmith's GitHub App, ready to use | Your panel creates its own GitHub App, in a few clicks |
| Features | By plan | Every feature |
| Price | 14-day free trial, then from $9 a month per team | Free |

## Coresmith Cloud

Coresmith Cloud is Coresmith with the panel run for you. It isn't open for sign-ups yet; it will be at coresmith.io.

What you get over running the panel yourself:

- **One server fewer.** A self-hosted panel needs a server of its own, because Coresmith can't manage the server it runs on. On Cloud, every server you add is for your apps.
- **No upkeep.** We install each release, back up the panel and keep its certificates and email working, so invitations, password resets and alerts arrive from the first day.
- **GitHub straight away.** Connect your repositories through Coresmith's GitHub App, with no App of your own to create.
- **Your servers stay yours.** If a plan ends, your servers and apps keep running and the team turns read-only until you choose a plan. To move to self-hosting, install Coresmith on a server of yours and add your servers there; they keep running the whole time.

| Plan | Price | Includes |
| --- | --- | --- |
| Trial | Free for 14 days, no card | Pro features on one server |
| Starter | $9 a month | 3 servers, one person |
| Pro | $19 a month | Unlimited servers, teammates and roles, a custom domain |
| Business | $39 a month | Everything in Pro, single sign-on, database backups to S3, audit log export |

Prices are per team, billed monthly in US dollars through Stripe. Change or end your plan from Settings, Billing; an ended plan runs until the end of the month you paid for.

## Self-hosting

Run the whole panel yourself, free, with every feature. Setup takes four steps.

### What you need

- A Linux server with systemd and root access (`sudo`). Ubuntu 22.04 and 24.04 are tested; the installer also accepts Debian 11 and 12, Rocky Linux and AlmaLinux 8 and 9, Fedora and Amazon Linux.
- At least 5GB of free disk (10GB or more recommended) and 2GB of RAM or more (it runs with less, slowly).
- Ports 80 and 443 open to your users. The installer opens them. If a firewall is already on, it leaves that firewall's SSH rules as they are; if it turns ufw on itself, it keeps your SSH port open.
- For HTTPS on a name, a domain whose DNS points at the server. Without one the panel answers on the server's IP address.
- Run the panel on a server of its own. Coresmith can't provision the server the panel runs on, so add other servers for your apps.

### 1. Point a domain at the server

To reach the panel on a name with HTTPS, point the domain's DNS at the server first: an A record, plus an AAAA record if the server has IPv6. Skip this step to use the server's IP address.

### 2. Run the installer

Import the release key and check its fingerprint:

    curl -fsSL https://raw.githubusercontent.com/monoventech/coresmith/main/resources/release-keys/coresmith-releases.asc | gpg --import
    gpg --fingerprint releases@coresmith.io

The fingerprint must read `10AD 393D 01B6 2CA5 524F  AEAB AF8B BF43 12DD D022`. The same key is in this repository's `resources/release-keys/` folder and at `https://get.coresmith.io/release-key.asc`.

Then download the installer and its signature from the latest release, check the signature, and run it as root:

    curl -fsSLO https://github.com/monoventech/coresmith/releases/latest/download/install.sh
    curl -fsSLO https://github.com/monoventech/coresmith/releases/latest/download/install.sh.asc
    gpg --verify install.sh.asc install.sh
    sudo bash install.sh --domain panel.example.com --email you@example.com

Go on only if `gpg --verify` reports a good signature from "Coresmith releases". Leave out `--domain` to use the server's IP address. `curl -fsSL https://get.coresmith.io/install | sudo bash` runs the same installer in one line.

The installer adds Caddy, PHP, Redis, Git, SQLite, Node.js, Bun and fail2ban, creates a `coresmith` user, installs the release, configures the firewall and starts the services. It takes these options:

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

### 3. Create the owner account

The installer ends with a one-time link, `https://<your panel>/install?token=<token>`. Open it to create the owner account. It works once; after the owner exists it is cleared from the server. If you lose it, issue a new token on the server and put it after `/install?token=`:

    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:setup-token --force

### 4. Connect GitHub and add a server

The panel's **Finish setup** list walks you through the rest: connect GitHub (the panel first creates its own GitHub App, which you confirm on GitHub), add a server, provision it and make your first deploy.

### Running it

    systemctl status caddy coresmith-horizon coresmith-reverb
    tail -f /var/log/coresmith-horizon.log
    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:health

| Path | What lives there |
| --- | --- |
| `/opt/coresmith/app` | The release that is serving (a link into `/opt/coresmith/releases`) |
| `/opt/coresmith/shared` | The `.env` and `storage/`, kept across releases |
| `/var/lib/coresmith` | The SQLite database, when you chose SQLite, and update state |
| `/var/log/coresmith-*.log` | The panel's service logs |

**Backups.** Every night at 04:00 the panel backs up its own database and `.env` to `/opt/coresmith/shared/storage/app/private/backups`, keeping the latest 14. Settings, General, Backups has **Back up now**. Your apps' databases are separate: **Back up now** on a database's page dumps it on its server, and a team can send every MySQL and PostgreSQL database to its own S3 bucket each night from Settings, Team.

### Email

The panel emails invitations, password resets and alerts. Set `MAIL_MAILER` and the other `MAIL_*` settings in `/opt/coresmith/shared/.env`, for example `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS`, then load them:

    cd /opt/coresmith/app && sudo -u coresmith php artisan config:cache && sudo -u coresmith php artisan horizon:terminate

Until then nothing reaches anyone: inviting someone without an account gives you a link to copy and send them yourself.

### Updating

Settings, General, **Panel updates** shows the version you run and any newer release with its notes. **Update now** builds the new release beside the running one, checks its signature, backs up, migrates, switches over and checks the panel is healthy; if any step fails it puts the previous release back on its own. Turn on **Install patch updates automatically** to have patch releases installed at 04:00.

From the shell:

    cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:update

### Removing Coresmith

    cd /opt/coresmith/app && sudo php artisan coresmith:uninstall

It asks you to type `uninstall` to confirm, saves the `.env`, the backups and, on SQLite, a copy of the database under `/root`, then removes the panel's services, files, Caddy site and user. If apps are deployed on the same machine, they keep running and the `coresmith` user stays for them.

## Development

    composer install
    bun install
    php artisan migrate:all --fresh
    composer dev

`composer dev` starts maildev, the Laravel server, the queue listener and Vite. See [CONTRIBUTING.md](CONTRIBUTING.md) for the checks every change passes before it is committed.
