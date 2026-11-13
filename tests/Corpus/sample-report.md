# A sample of public repositories

378 public repositories, drawn from GitHub code search by stack (`tests/Corpus/sample.ts`, seeds 1 and 2), were read and, when Coresmith filled in their stack completely, deployed through Coresmith's own UI onto a fresh arm64 Ubuntu 24.04 server on Lima (4 CPU, 6 GiB), with no database and no secrets attached. A deploy passes when it finishes and the app answers below 500.

Each failure is classified from its log in `classified.json`:

- **stack:** Coresmith's stack choice or deploy pipeline caused it.
- **app:** a variable, a service or the app's own code did.

The bar leaves out the apps' own failures.

Stack causes were fixed with tests, and their repositories were deployed again on the final code. So the final numbers describe the fixed code on the same repositories it was tuned on. The first pass shows each repository's first result, before the fixes it led to. A fresh sample on the final code would measure the bar without that bias.

The sample ran on 36b76e5, ae6142c and c4b5023, with reruns on d1f8f67 and 5155b3d.

## Final, each repository's latest result

- Repositories: 378
- Verdicts: ready 283, unsure 45, blocked 50, unreadable 0, harness 0
- Coverage, filled in completely: 283 (74.9%)
- Strict, deployed and answering: 167 of 283 (59.0%)
- The bar, passes / (passes + stack failures): 167 of 169 (98.8%)
- Failures that were the apps' own: 114

## First pass, each repository's first result

- Repositories: 378
- Verdicts: ready 288, unsure 39, blocked 39, unreadable 0, harness 12
- Coverage, filled in completely: 288 (76.2%)
- Strict, deployed and answering: 149 of 288 (51.7%)
- The bar, passes / (passes + stack failures): 149 of 185 (80.5%)
- Failures that were the apps' own: 103

## Stack causes found

- `225123/Web-Sidang-KP`: config.platform.php 8.2.0 is older than its locked packages need, so composer install fails on any server. Refused before the deploy since ae6142c. (ae6142c)
- `AllisonDaney/BilleterieJO`: @nuxthub/core builds it for Cloudflare Pages, so there was no Node server and the app answered 502. Reads not sure since 7e0ead1. (7e0ead1)
- `AlukweJonesTerah/ProposalMonitor`: Its start script is next start -p 3000, so the app ignored PORT and answered 502. Refused when the stack is read since e9bac9c. (e9bac9c)
- `Anjali12234/crs`: Past the skipped route:cache (ce9f027), its layout uses Illuminate\support\Facades\Cache with a lowercase support, which only loads on a case-insensitive disk, so / answers 500. (ce9f027)
- `ChanMor/chatgenie`: Past the missing yarn (9d7e1b7, 194ce39), its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect. (194ce39)
- `Fuzail21/POS`: Past the missing SQLite file (f9807ff), its migrations use MySQL-only SQL (MODIFY), which SQLite rejects. It needs MySQL. (f9807ff)
- `KaemonIsland/mana-flooded`: Gemfile.lock lists x86_64-linux but not the arm64 corpus node's aarch64-linux, so bundle install refused it in deployment mode. Refused with the bundle lock fix since 56ab872. (56ab872)
- `NishimuraS133/original`: Gemfile.lock lists x86_64-linux but not the arm64 corpus node's aarch64-linux, so bundle install refused it in deployment mode. Refused with the bundle lock fix since 56ab872. (56ab872)
- `PharaohKJ/lightning-talks-manager`: cssbundling-rails runs npm during assets:precompile, but Coresmith only gave Rails apps Node for a build script. Node, npm and the packages are there since 9d7e1b7. (9d7e1b7)
- `PreciousNwogu/Dabs_Beauty_Touch`: public/index.php calls putenv(), which Coresmith turns off for PHP web requests (with exec, proc_open and the rest), so every request is a fatal error before Laravel boots. Not fixed: the restriction is deliberate hardening. (not fixed)
- `VictoriousWealth/audit-management-system-demo`: Nothing names a Ruby version, so Coresmith picked 3.4.10, which stopped shipping bigdecimal that Rails 7.0.8.7 loads. The version reads not sure since 58b3371. (c8119e3)
- `akunesiateam/akunchatnew`: Past the ide-helper fix in 1f337ad, its Vue code imports lodash, which package.json doesn't declare and no lockfile pins, so the build can't resolve it. (1f337ad)
- `devguy4u/paybis-crypto-api`: Past the missing .env (f840f1e), config/bundles.php registers TwigBundle, which composer.json doesn't require, so cache:clear fails. (f840f1e)
- `devops-thiago/6038_Desenvolvimento_Backend_com_IA_Essencial_PHP`: An API-only app with no resources/views, so view:cache failed, and a failed cache failed the deploy. Skipped with the reason since ce9f027. (ce9f027)
- `devosamagasser/glasses-shop`: routes/api.php declares a function, so config:cache, which loads routes twice, hit a fatal redeclare. Skipped with the reason since ce9f027. (ce9f027)
- `devreyz/supply`: Past the skipped view:cache (ce9f027), it connects to MySQL and the corpus deploys without a database, so migrations cannot connect. (ce9f027)
- `finadio/SetaraLoka`: The repository leaves out storage/framework/views, so package discovery failed with 'Please provide a valid cache path'. Created on every release since f9807ff. (f9807ff)
- `fox-one/cowallet`: Nothing names a Node.js version, so Coresmith picked Node 24, where yarn 1 first refused a dependency's engines (fixed by 5155b3d) and then node-sass, which doesn't build on current Node.js, failed. Not fixed yet: a node-sass rule is the next task. (not fixed)
- `frank270/nPhpSlim4Skeleton`: No composer.lock and no PHP constraint, so Coresmith picked PHP 8.5, which its phpspreadsheet ^1.29 stops short of. The version reads not sure since 0ab1062. (0ab1062)
- `ichierevans/futsaldev`: Past the skipped route:cache (ce9f027), it connects to MySQL and the corpus deploys without a database, so migrations cannot connect. (ce9f027)
- `jashanpreetsinghdod/NotificationService`: Its start script runs ts-node, which compiles TypeScript at start and went past Small's 256 MB. Reads not sure since 46ee0f1. (46ee0f1)
- `jedaeroweb/homepage`: Nothing names a Ruby version, so Coresmith picked 3.4.11, and its locked sqlite3 1.7.3 build stops before Ruby 3.4. The version reads not sure since c8119e3. (c8119e3)
- `jusondac/railsapptemplate`: Gemfile.lock lists x86_64-linux but not the arm64 corpus node's aarch64-linux, so bundle install refused it. Refused with the bundle lock fix since 56ab872. (56ab872)
- `klaustopher/turbostream-react-demo`: vite_rails needs the npm packages installed, and Coresmith left a Rails app's JavaScript to assets:precompile, so the vite binary was missing. Installed after the gems since 9d7e1b7. (9d7e1b7)
- `marciotoshio/bingo`: Gemfile.lock lists x86_64-linux but not the arm64 corpus node's aarch64-linux, so bundle install refused it in deployment mode. Refused with the bundle lock fix since 56ab872. (56ab872)
- `msnetworks/doc2info`: Past the skipped route:cache (ce9f027), Laravel 8 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect. (ce9f027)
- `palladium-tms/palladium-api`: Gemfile.lock lists x86_64-linux but not the arm64 corpus node's aarch64-linux. Refused with the bundle lock fix since 56ab872. (56ab872)
- `rafael-arantes/portifolio`: package.json declares yarn@1.22.22 but the only lockfile is pnpm-lock.yaml, so corepack refused the pnpm install Coresmith chose. Refused with the fix since ee5e0a3. (ee5e0a3)
- `rami-aouinti/bro-world-backend-setting`: It needs ext-amqp, which Coresmith builds PHP without, so composer install fails. Refused when the stack is read since 8d792f9. (8d792f9)
- `sethhorsley/inertia-rails-shadcn-starter`: vite_rails needs the npm packages installed, and Coresmith left a Rails app's JavaScript to assets:precompile, so the vite binary was missing. Installed after the gems since 9d7e1b7. (9d7e1b7)
- `sethhorsley/react-rails-starter-kit`: vite_rails needs the npm packages installed, and Coresmith left a Rails app's JavaScript to assets:precompile, so the vite binary was missing. Installed after the gems since 9d7e1b7. (9d7e1b7)
- `simonesilvetti/simonesilvetti.github.io`: A Rails app without Active Record, so rails db:migrate is not a command it has. Migrations are skipped without config/database.yml since 55d0135. (55d0135)
- `symful/template-portfolio`: nuxt.config sets Nitro's preset to cloudflare-pages, so the build made no Node server. Refused since 7e0ead1. (7e0ead1)
- `thebluefowl/vishnujayadevan.com`: nuxt.config sets Nitro's preset to github in production builds, so it built for GitHub Pages, not a Node server. Reads not sure since 7e0ead1. (7e0ead1)
- `umesh-verma/nessam-tea`: It pins Bun 1.3.4, whose bun run can't list the root-owned 711 folders above the release, so the build failed reading its current directory. Builds since be17e43. (be17e43)
- `uyoolabs/uyoopack-tutorial`: Its start script is next start -p 3100, so the app ignored PORT and answered 502. Refused when the stack is read since e9bac9c. (e9bac9c)

## Every failure in the final results

- `1446652577/agent-` (app): Its build cannot find modules it imports.
- `AbdullahAlbkour/Camp_management` (app): It connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `AlexandrePereon/ify` (app): nuxt-auth refuses to start in production without AUTH_ORIGIN, which the corpus does not set.
- `Anen135/shortener` (app): package-lock.json is missing packages package.json needs, so npm ci fails on any machine, this Mac included.
- `Animesh0706/Ai-Resume-Analyzer` (app): It needs a MongoDB URI, which the corpus does not set.
- `Anjali12234/crs` (app): Past the skipped route:cache (ce9f027), its layout uses Illuminate\support\Facades\Cache with a lowercase support, which only loads on a case-insensitive disk, so / answers 500.
- `Ashirvad95/backend_byte` (app): It refuses to start without JWT_SECRET, which the corpus does not set.
- `AwaisDotGeek/_Boardify_` (app): Laravel 10 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `BhuvanXcode/Temple` (app): It imports ../../package.json, which is outside the app, so it cannot start.
- `Buzzi3/api-tarefas` (app): src/Tarefa.php requires Conexao.php, which the repository doesn't include.
- `ChanMor/chatgenie` (app): Past the missing yarn (9d7e1b7, 194ce39), its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect.
- `Djole003/djordjekitic` (app): resources/js/app.js imports bootstrap, which package.json doesn't declare, so the build can't resolve it.
- `EKL-NCI/AlbumTrackerCA` (app): Its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect.
- `ElInsano69/PROYECTOEXPOKINAL` (app): npm ci refuses its package-lock.json, which is out of step with package.json, on any machine.
- `Emanuel-Cacuci/symfony_cour` (app): Its .env points Doctrine at MySQL on 127.0.0.1, and the corpus deploys without a database.
- `Fanreza/Nestora` (app): npm ci refuses its package-lock.json, which is out of step with package.json, on any machine.
- `FarhadNuri/DriveFleet-Car-Rental-Client` (app): Its auth route reads an environment variable at build time that the corpus does not set.
- `Fennlye/insurance` (app): routes/api.php holds a merge conflict marker, so PHP cannot parse it.
- `Fuzail21/POS` (app): Past the missing SQLite file (f9807ff), its migrations use MySQL-only SQL (MODIFY), which SQLite rejects. It needs MySQL.
- `Gabrielingnau/Finance.ai` (app): Its middleware needs Clerk keys and Prisma needs DATABASE_URL, which the corpus does not set, so / answers 500.
- `IAmShafqatAli/boostrap5-inertia-ssr` (app): It connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `InZaynGT/SheinApp` (app): Laravel 8 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `JeremieN6/stellara` (app): Doctrine needs DATABASE_URL, which neither the repository nor the corpus sets, so cache:clear fails.
- `JerryMont/Botica-AMY` (app): It commits part of vendor/, so composer finds nothing to install and then cannot find nette/schema's src folder.
- `Laznology/nuxt-fullstack-blueprint` (app): Better Auth refuses to start without BETTER_AUTH_SECRET, which the corpus does not set.
- `MILOSNIKOLA/MilosNikola1.github.io` (app): Its Vite config minifies with terser, which package.json doesn't install.
- `Maharsh-Nayak/CSI-Quizz` (app): firebase.js reads Firebase credentials from the environment, which the corpus does not set.
- `MaryEddythe/hr-system` (app): Its migrations use MySQL-only SQL (MODIFY), which SQLite rejects. It needs MySQL.
- `MidhunPM6/WorkBridge_Job_Portal-back-end` (app): It refuses to start without JWT_SECRET_KEY, which the corpus does not set.
- `Mysterken/osudroid-hub` (app): yarn.lock is out of date with package.json, so yarn install --frozen-lockfile fails on any machine.
- `Nandoabcd22/futzoneReal` (app): It connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `NicholasLoren/jpriceless` (app): Its dependencies' peer requirements conflict and no lockfile settles them, so npm install fails with ERESOLVE on any machine.
- `Peureki/peuresearchcenterv2` (app): It connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `R0mm1/mediatheque-front-nuxt` (app): yarn.lock is out of date with package.json, so yarn install --frozen-lockfile fails on any machine.
- `Ripwords/ai-trip` (app): Its Nuxt build on Bun needs over 4.6 GB, past the 6 GiB corpus node's memory with other builds running. A server size limit, not a stack choice.
- `SLAVACOM/web-sys-lab-5` (app): Its production database config names no SQLite file, so migrations fail with 'No database file specified'.
- `STOCD/VGER` (app): An endpoint calls mkdirSync on an environment variable the app expects at build time and the corpus does not set.
- `Selomegech/ai-social-tool` (app): package.json names a dependency npm cannot fetch (404), so npm ci fails on any machine.
- `Semai-Mohamed/WorkSphere-Backend` (app): puppeteer's install downloads a Chrome that has no Linux arm64 build; the corpus node is arm64.
- `Shubham-355/gren` (app): It pins bun@1.3.8 in packageManager, and Bun 1.3.8 crashes running its next build (reproduced outside Coresmith on this arm64 node). Bun 1.4.2 builds it.
- `Souvik-1705/Hire-Hub` (app): next build prerenders pages that need a Firebase API key the corpus does not set.
- `SyldevK/TIE` (app): A class under src/ doesn't match its namespace, so Symfony's container can't load it and cache:clear fails.
- `Thagya/Expense-Tracker-` (app): package-lock.json is missing the platform packages npm ci needs, so it fails on any machine.
- `ThilinaTLM/JyskUC-MockAPI` (app): bun.lock is out of date with package.json, so bun install --frozen-lockfile fails on any machine.
- `VovaRotterdam/Store_Sofa_Project` (app): next build collects page data that needs environment values the corpus does not set.
- `YolandaMbande/easybooking` (app): It connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `abdulahad123524/flocci-backend` (app): It refuses to start without MONGODB_URI, which the corpus does not set.
- `akshara-devs/sistem-antrian` (app): It connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `akunesiateam/akunchatnew` (app): Past the ide-helper fix in 1f337ad, its Vue code imports lodash, which package.json doesn't declare and no lockfile pins, so the build can't resolve it.
- `angelsandev/tienda-laravel` (app): It registers Telescope, a dev dependency, unconditionally, so any install without dev packages fails.
- `aniway89/Aetheris` (app): It imports a module by a path that doesn't exist with that letter case.
- `anothor/genui` (app): Its CSS fails to parse in the Nuxt build.
- `avishka-hashara/factory-outlet-pos` (app): It connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `bbxmbb/my-slim` (app): It calls phpdotenv's load(), which needs a .env the repository doesn't commit, and then needs the database settings .env.example lists.
- `brendanfurtado/CaseMap` (app): next build needs an API token from the environment, which the corpus does not set.
- `cfg7am/opendic-server` (app): It needs a MongoDB URI, which the corpus does not set.
- `chatbotkit/template-nextjs-slack-agent-platform-js` (app): next build type-checks a dependency (react-markdown) whose types don't match its React, so the build fails on any machine.
- `christopherdent/VolunteerManager` (app): Its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect.
- `codepharm99/markdown-create` (app): It needs OPENAI_API_KEY, which the corpus does not set.
- `collective/pyf-gui` (app): Its dependencies' peer requirements conflict and it commits no lockfile, so npm install fails with ERESOLVE on any machine.
- `coumbassasanden-group/CS-TECH-WEB-APP` (app): npm ci refuses its package-lock.json, which is out of step with package.json, on any machine.
- `ctyamashita/text-to-speech-google-api` (app): Its Gemfile resolves azure_tts to a version whose ruby_speech dependency Bundler can't satisfy, so bundle install fails on any machine.
- `deeesh/IT-Salary-Calculator-AIR` (app): next build runs ESLint, which fails on errors in its own code.
- `devguy4u/paybis-crypto-api` (app): Past the missing .env (f840f1e), config/bundles.php registers TwigBundle, which composer.json doesn't require, so cache:clear fails.
- `devreyz/supply` (app): Past the skipped view:cache (ce9f027), it connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `dishudhalwal12/localjob` (app): next build runs ESLint, which fails on errors in its own code.
- `eduardogazolla/portfolio` (app): next build needs HYGRAPH_URL and HYGRAPH_TOKEN, which the corpus does not set.
- `efrizalnf/laravel-notes-app` (app): Its dependencies' peer requirements conflict and no lockfile settles them, so npm install fails with ERESOLVE on any machine.
- `erictreacy/FinanceFlow` (app): next build prerenders pages that need NEXT_PUBLIC_SUPABASE_URL and NEXT_PUBLIC_SUPABASE_ANON_KEY, which the corpus does not set.
- `erlin-martinez-ds/lenguas-vivas-backend` (app): It refuses to start without JWT_SECRET, which the corpus does not set.
- `evanpurkhiser/resume` (app): Its Astro build generates a PDF with a headless Chrome that needs system libraries the server doesn't have.
- `fauzan05/z-health-queue-app` (app): Its dependencies' peer requirements conflict and no lockfile settles them, so npm install fails with ERESOLVE on any machine.
- `fpgarciamtnz/CVKiko` (app): package-lock.json makes @cloudflare/workerd-windows-64 a required dependency, so npm ci refuses it on Linux.
- `giabaod1nh-spec/TokenRing_DistributedSystem` (app): Its code holds merge conflict markers, so Node cannot parse it.
- `gma/nestacms.com` (app): haml-contrib registers Tilt::MarukuTemplate, which the Tilt it locks no longer has, so the app cannot boot.
- `gracesitumorang17/ulosta` (app): A migration alters the orders table before any migration creates it.
- `hymns/hostiqo` (app): Its migrations use MySQL-only SQL (ALTER TABLE ... MODIFY), and without a database Laravel 12 uses SQLite, which rejects it. It needs MySQL.
- `iampratyushk/Ai-interview-backend` (app): It needs OPENAI_API_KEY, which the corpus does not set.
- `ichierevans/futsaldev` (app): Past the skipped route:cache (ce9f027), it connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `indigibilliharshit/Habit-Tracker-App` (app): next build cannot find a module the code imports.
- `jtkunley/jameskunley` (app): Its build launches Puppeteer's Chrome, which needs system libraries the server doesn't have.
- `karen-rm/kioskoNet` (app): Its routes sit under a base path hard-coded from the author's machine, so / is not found, and with no error middleware that answers 500.
- `karma253/Hot_stone_bath_booking_system` (app): It connects to MySQL and the corpus deploys without a database, so migrations cannot connect.
- `kevinnail/fs-react-exp-gallery-backend` (app): It needs a PostgreSQL database on 127.0.0.1:5432, which the corpus does not attach.
- `lUCIFER94008/Bismi` (app): Its auth API route reads environment values at build time that the corpus does not set.
- `lasirene-61sgn/vinyl-laravel` (app): Laravel 10 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `lelinguine/supervision-project` (app): It calls phpdotenv's load(), which needs a .env the repository doesn't commit (it ships .env-example).
- `manchagd/grouping_nodes_api` (app): Its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect.
- `maton369/HackFlow` (app): Laravel 10 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `mattrias/directionMap` (app): Its view asks Vite for resources/js/Pages/*.jsx, but the folder is resources/js/pages, which only works on a case-insensitive disk.
- `md-saim850/StudioSpace-client` (app): src/lib/auth.ts reads an environment variable at build time that the corpus does not set.
- `mhero/freight-challenge` (app): Its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect.
- `mikilezen/arc-agent-starter` (app): next build cannot find modules its components import.
- `msnetworks/doc2info` (app): Past the skipped route:cache (ce9f027), Laravel 8 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `mustafamsm/mini-shop` (app): It asks Vite for resources/js/pages/Shop/index.vue, but the file is Index.vue, which only works on a case-insensitive disk.
- `neozhu/lingualens` (app): npm ci refuses its package-lock.json, which is out of step with package.json, on any machine.
- `nev3rfind/bringlu-marketing-app` (app): Laravel 9 connects to MySQL by default and the corpus deploys without a database, so migrations cannot connect.
- `ngeorgeault/ngeorgeault.github.io` (app): Its build fails inside @tailwindcss/vite with the Vite and Rolldown versions it resolves.
- `niranjanb27/saicare-backend` (app): Its MongoDB Atlas cluster no longer resolves, so it cannot start.
- `nivedit1998/dinodia-platform` (app): Its Prisma config needs DATABASE_URL during install, which the corpus does not set.
- `oncorphinchus/fentanylfloyd` (app): An API route throws at build time without BUCKET_NAME, which the corpus does not set.
- `otatechie/guacpanel-tailwind` (app): A composer script connects to MySQL, and the corpus deploys without a database.
- `poliberry/moddr_web` (app): It imports socket from a page module that doesn't export it, so next build fails.
- `quentin-reymond/MusicAPIv2` (app): app/settings.php reads DB_* settings and the app needs a MySQL database, neither of which the corpus provides.
- `rromenskyi/platform-dash` (app): It needs OIDC settings and AUTH_SECRET, which the corpus does not set, so / answers 500.
- `sagi0312/serenity-core` (app): Its dependencies' peer requirements conflict and no lockfile settles them, so npm install fails with ERESOLVE on any machine.
- `sulaeman-kos/s_kos` (app): Its layout reads the signed-in user's name when nobody is signed in, so / answers 500.
- `svnmin/onlineStoreProject` (app): next build runs ESLint, which fails on errors in its own code.
- `ub-digit/bestall` (app): Its production database is PostgreSQL, which the corpus does not attach, so migrations cannot connect.
- `vishuSkywalker/mern-estate` (app): It listens on port 3000 fixed in its code and ignores PORT, so the app answered 502.
- `viv2005ek/BiteSpeed` (app): Prisma needs DATABASE_URL, which the corpus does not set.
- `yakkoworks/StarMap` (app): npm ci refuses its package-lock.json, which is out of step with package.json, on any machine.
- `yasminelkhawa-prog/ATS-pfe` (app): It imports ../Employment-Types, which isn't in the repository under that name.
- `zidvsd/waddlr` (app): Better Auth refuses to build without BETTER_AUTH_SECRET, which the corpus does not set.
- `PreciousNwogu/Dabs_Beauty_Touch` (stack): public/index.php calls putenv(), which Coresmith turns off for PHP web requests (with exec, proc_open and the rest), so every request is a fatal error before Laravel boots. Not fixed: the restriction is deliberate hardening.
- `fox-one/cowallet` (stack): Nothing names a Node.js version, so Coresmith picked Node 24, where yarn 1 first refused a dependency's engines (fixed by 5155b3d) and then node-sass, which doesn't build on current Node.js, failed. Not fixed yet: a node-sass rule is the next task.
