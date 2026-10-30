# Система сервісних сайтів service-engine: експлуатація

Деплой, відкат, бекап, відновлення, архів і стан сайту. Реєстр (`sites.yaml`) і структура паку — `docs/site-pack.md`.
Схема — порт `bin/store` із shop-engine (`docs/store-ops.md` там); прод може бути без Docker (FastPanel: PHP-FPM 8.4 + nginx
панелі) або в Docker за зовнішнім nginx.

**Реєстр живе поза рушієм.** `bin/site` читає його зі змінної `SITES_FILE` (типово `<рушій>/sites.yaml`, цей шлях у `.gitignore`):
тримайте реєстр у приватному ops-репо або файлі на операторській машині й запускайте `SITES_FILE=/path/ops/sites.yaml bin/site …`
(зручно — обгорткою `ops/bin/site`). Приклад з усіма полями — `sites.example.yaml`; `tests/site/sites.yaml` — фікстура тестів.

## Як це влаштовано

- **Реліз = рушій + пак.** `bin/site deploy` бере `git archive` рушія потрібного тегу, з паку кладе
  `config/parameters.yaml` → `config/site/parameters.local.yaml`, `themes/<key>/` → `public/themes/<key>/`,
  `translations/*.yaml` → `translations/site/`, `templates/` (свій дизайн сайту, якщо є) → `templates/site/`, пише `SITE_RELEASE`
  (версії) і передає на сервер у `<path>/releases/<tag>`.
  Тег `<sha рушія 12>-<хеш паку 8>`: той самий код і пак → той самий тег, контекст повторно не заливається.
- **На сервері** (`bin/site.d/_remote.sh`, копія лишається в `<path>/site-remote.sh`): у релізі `composer install --no-dev -o`
  (кеш у `<path>/.composer`), симлінки на спільне (`shared/.env.local`, `shared/media` → `public/media`, `shared/log` → `var/log`),
  бекап `*-pre-deploy.tar`, `doctrine:migrations:migrate`, `cache:warmup`, `lint:twig templates/site` (коли пак має свої шаблони), **smoke** (`bin/smoke.php`: ядро Symfony обробляє
  `/`, `/services/`, `/districts/`, `/faq/`, `/sitemap.xml`, `/robots.txt`, `/login` адмінки, а з карти сайту сам додає першу
  послугу, район і пост блогу та першу картинку Liip зі сторінки послуги — без веб-сервера, бо другого php-fpm на FastPanel нема;
  у схемі `slug` — першу, середню й останню адресу `/<x>/` з карти, а що відкрилося, пише за маршрутом: item/category/page;
  з `app.pages` — і сторінки; хости `domains.extra` — їхні `/`, `/sitemap.xml`, `/robots.txt` з тим самим автовідкриттям), перемикання
  `current → releases/<tag>` + `release.env`, скидання php-fpm (`server.fpm_reload`), перевірка через nginx
  (`server.check_ip`; головна апекса і кожного хоста `domains.extra`); провал після перемикання — автоматичний відкат на попередній тег.
- **Два рантайми** (`server.runtime`): `fastpanel` — ssh під root (аліас сервера), файлові дії `sudo -u <server.user>`,
  php = `server.php`; `docker` — compose релізу, `SITE_ROOT=<path>`, проєкт `service-<key>`. Для docker `server.proxy`:
  `traefik` (типово) — стенд за traefik, `docker-compose.traefik.yml` (розділ «Стенд у Docker за traefik»); `edge` — прод
  у Docker за зовнішнім nginx (`docker-compose.edge.yml`, розділ «Прод у Docker за edge»). Свій compose-файл —
  `server.compose` (ім'я в релізі або абсолютний шлях на сервері; те саме — змінна `SITE_COMPOSE_FILE`).
- **Секрети — лише на сервері**: `<path>/shared/.env.local` (600). Перший деплой бере його зі старої робочої копії
  (`server.legacy_app`) разом з `public/media`; для нового сайту створює заготовку і зупиняється, поки не заповнено `DATABASE_URL`.
- **Усе через `bin/site`** з операторської машини (потрібні `python3`, PyYAML, `git`, `rsync`, `ssh`; на Windows Git Bash —
  `SITE_PYTHON=python`). `server.host` — ssh-аліас сервера з `~/.ssh/config`; `local` — виконання на цій машині (стенд).

Розкладка `<path>` = `…/site-<key>`:

| Шлях | Що це |
|---|---|
| `shared/.env.local`, `shared/media`, `shared/log` | секрети (600), медіа, логи — спільні для релізів |
| `releases/<tag>/`, `current` | релізи (лишаються поточний, попередній і ще 2) і симлінк на поточний |
| `release.env` | `SITE_TAG` (поточний), `SITE_PREV_TAG` |
| `backups/` | архіви `<key>-<час>[-мітка].tar`, `backup.log` |
| `.composer` | кеш composer користувача сайту |
| `site-remote.sh` | серверна частина `bin/site` (для cron бекапу) |
| `docroot.prev` | куди дивився docroot панелі до `cutover` |
| `db-init/` (docker) | дампи для першого підйому MySQL стенда |
| `ARCHIVED` | маркер архіву |

## Команди

```
bin/site help
bin/site validate [--all | <key> | <тека-паку>]
bin/site new      <key> <домен> [--from=…] [--name=…] [--scheme=flat|nested] [--dir=…] [--github]
bin/site theme    list | new <key|тека> [шаблон…] [--copy] [--force] | diff <key|тека>
bin/site deploy   <key> [ref] [--pack <тека>]
bin/site rollback <key>
bin/site cutover  <key> [--undo]
bin/site backup   <key>|--all [--copy-only]
bin/site restore  <key> <архів|server:/шлях> [--yes]
bin/site archive  <key>
bin/site doctor   <key>|--all [--notify]
bin/site sync     --all | <key>… [--notify]
```

Спільні змінні: `DRY_RUN=1` — лише показати дії; `SITES_FILE` — інший реєстр; `SITE_DEBUG=1` — `bash -x`;
`SITE_SKIP_BACKUP=1`, `SITE_CRON=0`, `UNARCHIVE=1`, `SITE_NO_TLS=1` (doctor без перевірки сертифікатів).
Запобіжники: команди, що змінюють сайт, відмовляють, якщо `server.path` не закінчується на `/site-<key>` (стара схема —
робочі копії `<key>-app` з git checkout + post-receive — не обслуговується ніколи), для `status: archived` без
`UNARCHIVE=1`, для `handed-over` завжди; на сервері з маркером `ARCHIVED` — так само.

### deploy

```
bin/site deploy example                   # engine з реєстру
bin/site deploy example v1.2.0            # інший тег/коміт (потім оновити engine у реєстрі)
bin/site deploy example --pack ~/site-example
```

Порядок на сервері: shared/перший запуск → симлінки → `composer install` → бекап `pre-deploy` (якщо реліз уже був) →
міграції → `cache:warmup` → `app:html:strip-static public/themes` (коментарі зі статики теми в релізі; оригінали паку не
змінюються, збій — лише WARN) → `lint:twig` шаблонів паку (якщо в паку є `templates/`) → smoke кандидата → перемикання →
`fpm_reload` → перевірка через nginx → cron бекапу → прибирання.
Провал до перемикання не чіпає робочий реліз (але міграції вже застосовані — для схеми є `pre-deploy`); провал після —
відкат на попередній тег. Міграції мають бути зворотно сумісні з попереднім релізом.

> **Відомий збій до v0.9.28 (FastPanel + пак із `templates/`).** Рушій ≤ v0.9.27 викликав `lint:twig templates/site`
> відносним шляхом, а на FastPanel php іде не з теки релізу: деплой зупиняється з `File or directory "templates/site" is not
> readable` і повідомленням «шаблони паку (templates/) не проходять lint:twig; поточний реліз лишився …». Сайт цілий: `current`
> не перемикається, кандидат лишається в `releases/`. На стенді docker не відтворюється (робоча тека контейнера `/app`).
> Перевірити, що шаблони справні: `php $R/bin/console lint:twig $R/templates/site` (абсолютні шляхи, `$R` — тека кандидата).
> Обходу без нового рушія нема (`bin/site.d/_remote.sh` деплой бере з тега через `git archive`, не з робочої копії) —
> деплоїти на v0.9.28+ (#84). Уперше — 18.10.2026, перший пак зі своїм шаблоном на FastPanel.

**Перший перехід прод-сайту зі старої схеми** (рішення власника, по одному сайту):
1. `bin/site deploy <key>` — кладе реліз у `…/site-<key>`, бере `.env.local` і media з `legacy_app`, мігрує **ту саму БД**
   (бекап `pre-deploy` робиться до міграцій), нічого в панелі не перемикає. Сайт і далі працює зі старої копії.
2. Перевірка: `bin/site doctor <key>` (docroot ще старий — WARN), smoke пройшов.
3. `bin/site cutover <key>` — симлінк docroot панелі → `current/public`, скидання php-fpm, перевірка через nginx
   (провал → симлінк повертається). Відкат руками: `bin/site cutover <key> --undo`. Новий сайт: FastPanel створює docroot
   справжньою текою з `index.php`-заглушкою — `cutover` сам відкладає таку (до 3 файлів, без ознак застосунку) у
   `<docroot>.fp-placeholder`; теку з даними не чіпає (відмова «перемикати руками»).
   Піддомени міст і адмінки в FastPanel — окремі docroot-симлінки (`north.example.com` тощо): перелічені в
   `server.docroot_extra` реєстру, `cutover` перемикає їх разом із головним (і повертає всі при `--undo`).
4. Жива перевірка сторінок, `/analytics-healthcheck`, запис у solutions-log. Старий bare-репо й post-receive більше не
   використовуються (лишити до наступного релізу як запасний шлях, потім прибрати).

### rollback

`bin/site rollback <key>` — `current` → `SITE_PREV_TAG`, скидання php-fpm (docker: контейнери з попереднього релізу),
перевірка. БД не відкочується: якщо схема змінилась несумісно — спершу `restore` з `*-pre-deploy.tar`, потім `rollback`.

### backup / restore

- Архів: `db.sql.gz` (mysqldump однієї БД, креди читаються з `shared/.env.local` без друку), `media.tar.gz`, `manifest.txt`,
  `SHA256SUMS`. Ротація на сервері `SITE_BACKUP_KEEP_DAYS` (14), 3 останні лишаються завжди. Cron на сервері — щодня ~03:xx
  (`site-remote.sh backup`), копія в `backup_target` — pull з операторської машини (`bin/site backup --all --copy-only`).
- `restore <key> <архів>`: контрольні суми, відмова на дампі з `CREATE DATABASE/USE`, страховий `pre-restore`, таблиці
  перестворюються з дампа, media замінюється, міграції до версії релізу, `cache:clear`, smoke. `.sql.gz` — лише БД
  (напр. дамп прода для стенда).

### doctor

Читає стан: доступ, `ARCHIVED` проти `status`, версія рушія проти `engine` реєстру, docroot панелі (fastpanel) або
контейнери (docker), `doctrine:migrations:up-to-date`, останній звичайний бекап (старший за 36 год або менший за 50K — WARN),
cron, диск (85% WARN, 95% FAIL), php і `date.timezone` (має бути Europe/Kyiv), збіги слагів у корені сайту
(`bin/console app:site:slugs`: дві послуги / послуга й категорія / сторінка й категорія на одній адресі або запис на адресі
фіксованого розділу — FAIL з переліком; рушій без команди — рядка нема), TLS сайту, адмінки й кожного хоста `domains.extra`
(< 14 днів — WARN), коментарі розробника у HTML (з 25.10.2026, `docs/site-pack.md` «Чистий HTML»): GET головної й однієї
послуги з sitemap, лічильники `<!--`, `/*`, `^\s*//` поза JSON-LD (`bin/site.d/_htmlcomments.py`, лише stdlib) — `0` OK,
інакше WARN із числом на сторінку (рушій без чистки або `app.html.strip_comments: false` у паку); `SITE_NO_HTTP=1` — пропустити.
Код виходу 1 при FAIL.

**Місто на окремому домені** (не піддомен апекса; `ServiceCity.host` = інший домен): допишіть хост у реєстр —
`domains.extra: [klinika-misto.example]` — і docroot панелі в `server.docroot_extra` (cutover перемикає всі). Тоді smoke
деплою й перевірка через nginx ходять і на цей домен, а doctor перевіряє його сертифікат (wildcard апекса окремий домен
не покриває). Валідатор: список імен хостів без повторів і без `domains.site`/`domains.admin`.

### archive

Бекап з міткою `archive` → копія в `backup_target` (без неї відмова; `SITE_NO_COPY=1` свідомо) → перевірка архіву →
docker: зупинка стека; fastpanel: сайт у панелі вимикає власник → cron вимкнено → маркер `ARCHIVED`. Далі `status: archived`
у реєстрі (PR).

## Автооновлення: релізи, sync, сповіщення

Схема (рішення власника 11.10.2026): **версії рушія — теги `vX.Y.Z`**, не кожен коміт `main`; **стенди оновлюються самі**
з `origin/main` після злиття; **прод — напівавтомат**: `doctor --notify` щоночі повідомляє, що сайт відстав від реєстру,
а `bin/site deploy` на прод запускає людина, поки для сайту не ввімкнено `auto_update: true`.

- **Реліз.** `git tag v1.2.0 && git push origin v1.2.0` на коміті `main`, що пройшов CI (і консиліум для мажорних). CI на тег
  створює GitHub Release з автонотатками. `v1.0.0` — лише після консиліуму на рушій (рішення власника 10.10).
- **Реєстр.** `engine:` у `sites.yaml` = що має бути на проді (тег). Оновлення сайту = PR в ops-репо зі зміною `engine:` (або
  `bin/site deploy <key> v1.2.0` руками + той самий PR). Стенди тримають `engine: main` і `auto_update: true`.
- **`bin/site sync --all [--notify]`** — pull-модель без доступу GitHub до серверів: для кожного `active` сайту з
  `auto_update: true` порівнює `engine` реєстру (гілка — `origin/<ref>`, тег/sha — як є) з `release.engine_sha` на сервері
  і, якщо відстав, робить звичайний `deploy` (бекап, міграції, smoke, перемикання, автовідкат). Названі явно ключі
  (`sync example`) оновлюються незалежно від `auto_update`. Підсумок — у stdout, `--notify` шле його в канал лише коли щось
  оновлено або впало. Код виходу 1 при провалі.
- **`bin/site doctor --all --notify`** — шле звіт, коли є FAIL або WARN «потрібен deploy» / «потрібен cutover».
- **Канал сповіщень** (`notify` у `_lib.sh`): `SITE_NOTIFY_CMD` (команда, текст у stdin) або файл
  `$SITE_NOTIFY_ENV` (600; типовий шлях — у `_lib.sh`) з `TELEGRAM_BOT_TOKEN` і `TELEGRAM_CHAT_ID` — Bot API `sendMessage`
  (окремий бот від BotFather, не особистий акаунт; токен ніде не друкується). Без каналу — попередження в лозі.

Cron на операторській машині (користувач, якому належать теки стендів; реєстри — в ops-репо `<ops>`):

```
*/10 * * * * cd <рушій> && git pull -q --ff-only origin main && SITES_FILE=<ops>/stand.yaml bin/site sync --all --notify >> <ops>/log/sync.log 2>&1   # стенди
20 7 * * *   cd <рушій> && SITES_FILE=<ops>/sites.yaml SITE_NO_TLS=0 bin/site doctor --all --notify >> <ops>/log/doctor.log 2>&1                  # прод: лише звіт
```

Перший рядок — стенди (кожні 10 хв після злиття в `main`: `git pull` оновлює і сам `bin/site`), другий — прод
(лише звіт; увімкнути автодеплой проду = `auto_update: true` у реєстрі + третій рядок із `bin/site sync --all --notify`
на прод-реєстрі, рішення власника).

## Стенд у Docker за traefik (`runtime: docker`, `proxy: traefik` — типово)

Машина з traefik і зовнішньою docker-мережею `edge` (wildcard-сертифікат на домен стенда). Запис у реєстрі стенда:
`host: local`, `runtime: docker`, `path: <стенди>/site-<key>`, `domains.site: <key>.<домен стенда>`,
`domains.admin: <key>-admin.<домен стенда>`, `engine: main`, `auto_update: true`. Стек — `docker-compose.traefik.yml`
релізу: `service-<key>-php` (`Dockerfile.traefik`: php-fpm 8.4 від `SITE_UID:SITE_GID` — типово користувач, що робить
deploy, і група теки сайту), `service-<key>-nginx` (мітки traefik з хостів реєстру: апекс, адмінка, міста
`<slug>-<апекс>`), `service-<key>-mysql` (паролі дефолтні, портів назовні нема), `service-<key>-mailpit`
(`<key>-mail.<домен стенда>`; інший хост — `SITE_MAIL_HOST`).

Перший підйом сайту: покласти дамп сайту в `<path>/db-init/10-site.sql.gz` (дамп проду переносьте так, щоб стенд у прод
не ходив) і `90-stand-hosts.sql` (підміна прод-хостів на стендові: `docker/traefik/90-stand-hosts.sql.tpl`,
`__SITE_HOST__` = `domains.site`), медіа — у `<path>/shared/media`, далі:

```
cd <рушій> && SITES_FILE=<ops>/stand.yaml bin/site deploy <key>
```

`shared/.env.local` стенд створює сам (без прод-токенів). Перезалити дамп = `docker volume rm service-<key>_mysql-data`
і deploy. Свій compose-файл (інший проксі, інші томи) — `server.compose: /шлях/на/сервері.yml` або ім'я файлу в релізі;
він має приймати ті самі змінні (`SITE`, `SITE_ROOT`, `SITE_HOST`, `SITE_ADMIN`, `SITE_MAIL_HOST`, `SITE_UID`, `SITE_GID`)
і мати сервіси `php`, `nginx`, `mysql`.

### Стенд із Joomla-джерела

Прода рушія ще нема — дамп беремо не з прода, а з локального стека після імпорту (обраний шлях: `bin/site deploy`
очікує `db-init/10-site.sql.gz`, а схема `joomla_src` на стенді не потрібна):

```
# локально: схема joomla_src ← дамп Joomla (local/ паку), міграції, імпорт, дамп результату
docker compose -f docker-compose.local.yml exec service-engine-php php bin/console doctrine:migrations:migrate -n
docker compose -f docker-compose.local.yml exec service-engine-php php bin/console app:joomla:import \
    --mapping=/app/var/pack/seo/joomla-mapping.yaml --media-root=/tmp/joomla
docker exec service-engine-mysql mysqldump -uroot -proot --single-transaction service_engine | gzip > <key>-engine.sql.gz
scp <key>-engine.sql.gz stand:<стенди>/site-<key>/db-init/10-site.sql.gz
scp -r public/media/. stand:<стенди>/site-<key>/shared/media/
# стенд: 90-stand-hosts.sql з docker/traefik/90-stand-hosts.sql.tpl (__SITE_HOST__ = апекс стенда; друге місто → <slug>-<апекс>), далі
cd <рушій> && SITES_FILE=<ops>/stand.yaml bin/site deploy <key> && SITES_FILE=<ops>/stand.yaml bin/site doctor <key>
```

Альтернатива (без дампа рушія): на стенді порожня БД → міграції → залити `joomla_src` → `app:joomla:import` там само.
Повторний імпорт безпечний (upsert по legacyId), але перетирає правки адмінки в імпортованих полях.

## CRM сайту: доступи й крон добору (13.10.2026)

Куди йдуть заявки, задає пак (`app.crm`, див. `docs/site-pack.md`); доступи — лише `shared/.env.local` сайту:

| env | CRM | порожньо |
|---|---|---|
| `PIPEDRIVE_API_TOKEN`, `PIPEDRIVE_OWNER_ID` | Pipedrive (`app.crm.system: pipedrive` або `auto`) | вимкнено |
| `BITRIX24_WEBHOOK_URL` | Bitrix24 (`system: bitrix24`): вхідний вебхук `https://<портал>/rest/<id>/<токен>/` з правом `crm` | вимкнено, у лог лише info «CRM вимкнено» |

Вебхук і токени — секрети: не в пак, не в git, не в чат. Стенд створює `.env.local` з порожніми значеннями,
тож у чужу CRM не пише. Заявка, що не дійшла (CRM лежала, протух токен), лишається в черзі (`crm_external_id` порожній,
текст помилки — у полі «Помилка CRM» в адмінці); її добирає крон раз на 10 хвилин:

```
*/10 * * * * cd <path>/current && <php> bin/console app:crm:sync --limit=50 --days=30 >/dev/null 2>&1
```

`app:pipedrive:sync` — аліас тієї самої команди (старі crontab-и можуть кликати його, міняти не обов'язково).

Журнал 404 (адмінка → SEO, з 23.10.2026) прибирається сам приблизно раз на 100 нових адрес; щоб ліміти
`app.seo_not_found_limit` / `app.seo_not_found_days` трималися й на тихих сайтах — раз на добу:

```
35 3 * * * cd <path>/current && <php> bin/console app:seo:not-found:prune >/dev/null 2>&1
```
Без доступу команда пише «CRM вимкнено» і виходить з кодом 0. Оброблені в адмінці заявки в CRM не тягнуться.

## Прод у Docker за edge (`runtime: docker`, `proxy: edge`)

Сервер, де вже є спільний вхідний nginx у Docker (напр. `edge-nginx`: єдиний 80/443, TLS сертифікатами Cloudflare Origin,
конфіги в `/opt/edge/conf.d/*.conf`) і зовнішня docker-мережа `edge`. Стек сайту — `docker-compose.edge.yml` релізу:
`service-<key>-php` (`Dockerfile.edge`: php-fpm 8.4 від www-data, opcache без перечитування, TZ Europe/Kyiv),
`service-<key>-nginx` (у мережах `internal` і `edge`, **портів назовні нема**), `service-<key>-mysql` (том `mysql-data`).
Запис у реєстрі:

```yaml
server: { host: <ssh-аліас>, user: www-data, runtime: docker, proxy: edge, path: /opt/sites/site-<key> }
```

- `user: www-data` — uid 33 на хості = користувач php у контейнері: `shared/.env.local` (600), `shared/media`, `shared/log`
  належать йому (`own`), симлінк `current` ставиться `sudo -u www-data`.
- Перший deploy сам створює `shared/db.env` (600: `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE=<key>`, `MYSQL_USER=<key>`,
  `MYSQL_PASSWORD`; compose читає як `env_file` mysql) і `shared/.env.local` з `DATABASE_URL` на `mysql:3306/<key>` і
  `MAILER_DSN=null://null`. Свої значення (пошта, токени) — заздалегідь через stdin у `.env.local`, але тоді й `db.env`
  кладеться руками (інакше deploy відмовить: паролі не вгадати).
- Дані: дамп однієї БД (без `CREATE DATABASE/USE`) у `<path>/db-init/10-*.sql.gz` до першого підйому тому — або після
  першого deploy `bin/site restore <key> <архів|.sql.gz на сервері>`; медіа — у `<path>/shared/media`.
- Зовнішній nginx: свій `conf.d/<NN>-<key>.conf` з `proxy_pass http://service-<key>-nginx:80`, `Host`, `X-Forwarded-Proto https`,
  `X-Forwarded-For`; перевірка `docker run --rm --network edge -v /opt/edge/conf.d:/etc/nginx/conf.d:ro … nginx -t`
  (без мережі edge upstream не резолвиться), далі `docker exec edge-nginx nginx -s reload`. Стейдж/копія сайту —
  `add_header X-Robots-Tag "noindex, nofollow" always;` там само.
- Бекап, restore, rollback, doctor — як для стенда: дамп робить контейнер mysql (пароль зі змінної контейнера), cron бекапу
  — у crontab root на сервері. Перемикання релізу перестворює контейнери (інший `./` → `/app`), тому сайт оновлюється в
  момент `compose up` нового релізу, ще до smoke; провал smoke/міграцій повертає контейнери на попередній реліз.

## Налаштування в адмінці (21.10.2026)

Адмінка → «Налаштування» → **«Налаштування сайту»** (лише `ROLE_ADMIN`; модель — розділ налаштувань shop-engine). Ключі й
коди, які клієнт вводить сам, без пака й деплою. Вмикається саме: міграція `Version20261021100000` (адитивна, таблиця
`service_site_setting`) і порожні рядки; сайт без налаштувань рендериться як раніше, `.env.local` і параметри паку лишаються
запасним джерелом.

| Група | Поле (`код`) | Що робить на сайті | Пріоритет |
|---|---|---|---|
| Вхід через Google | Увімкнено, Client ID, Client Secret (`google.*`) | кнопка «Увійти через Google» і форма «Залишити відгук» на `/reviews/` | адмінка (перемикач увімкнено) > `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` у `.env.local`; увімкнено, а ключі неповні — вимкнено, без тихого відкату на env |
| Аналітика | GTM сайту (`analytics.gtm_container_id`) | контейнер у містах без власного лічильника | **лічильник міста** («Міста сервісів» → «Лічильник аналітики») > GTM сайту; два контейнери на сторінці не ставимо |
| | GA4, Google Ads, мітка конверсії «Заявка» | `gtag.js` лише на сторінках без GTM; на `/thank-you/` — події `app.lead_events` у GA4 сайту (`send_to`) і одна конверсія Ads | з GTM — налаштовувати в контейнері |
| | Microsoft Clarity (`analytics.clarity_id`) | тег Clarity | адмінка > `app.clarity_id` паку |
| | Meta Pixel (`analytics.fb_pixel_id`) | PageView, Lead на `/thank-you/` | якщо піксель у GTM — поле порожнє |
| | Банер згоди на cookie (`analytics.consent_banner`) | Consent Mode v2 (`denied` до згоди), Clarity/Pixel лише після «Прийняти», cookie `sv_consent` 180 днів | вимкнено = як раніше |
| Верифікація | Google Search Console, Bing (`seo.*`) | `<meta>` у `<head>` усіх хостів | GSC: адмінка > `app.site_verification` паку |
| HTML-коди | `<head>`, початок і кінець `<body>` (`html.*`) | сирий HTML на всіх сторінках | лише адміністратор |
| Адмінка | Назва адмінки (`site.admin_title`) | бічне меню, дашборд, сторінка входу | адмінка > назва сайту хоста адмінки > «Сервісні сайти» |

- **Формат** кожного id перевіряється при збереженні (помилка біля поля); можна вставити цілий код від Google, Meta чи Microsoft —
  ідентифікатор дістанеться сам. Під списком — таблиця «Що виводиться на сайті по містах» (джерело кожного тегу й посилання на
  поле міста) та стан інтеграцій, які лишаються в паку/на сервері (CRM `app.crm`, віджет `app.gbp_reviews`, `STUDIO_API_TOKEN`).
- **Секрети** (Client Secret) — у БД лише конверт `enc:v1:…` (sodium secretbox), ключ — `SETTINGS_SECRET_KEY` у `shared/.env.local`
  (не `APP_SECRET`). `bin/site deploy` дописує ключ сам, якщо його нема; `bin/site doctor` показує його стан; вручну —
  `bin/console app:secrets:keygen`. У формі секрет не показується (маска «…abcd»), порожнє поле = лишити чинний. Ключ змінено
  чи БД відновлено на іншому сервері — в адмінці «введіть знову», сайт не падає.
- **Аудит** — `var/log/security_audit.log` (на сервері `shared/log/`): `settings.changed` / `settings.secret_set` /
  `settings.secret_cleared` з кодом ключа, id сайту, id користувача, HMAC email і анонімізованим IP; **без значень** (для секрету — маска).
- **Доступ:** лише `ROLE_ADMIN`; персональні токени менеджерів (`content:*`, `prices:write`, `studio:write`, `theme:write`) налаштувань
  не бачать і не змінюють — REST/MCP живуть в окремому firewall і такого ресурсу не мають. Шаблонам (і живому дизайну під токеном)
  доступні лише публічні значення (`site_tags(city)`), секретів там нема.
- **Кілька сайтів в одній БД** (локальний стек): налаштування — на сайт, перемикач сайту над списком (`?site=<id>`).

### Вхід через Google: підключення (інструкція є й в адмінці під списком)

1. Google Cloud Console → проєкт компанії → APIs & Services → OAuth consent screen: External, назва, email підтримки, домени сайту,
   статус In production; scopes — `openid`, `email`, `profile`.
2. Credentials → Create credentials → OAuth client ID → Web application.
3. Authorized JavaScript origins і Authorized redirect URIs — **по рядку на кожне активне місто сайту**: адмінка показує готові
   значення (`https://<хост міста>` і `https://<хост міста>/connect/google/check`) з кнопками «Копіювати». Нове місто чи домен —
   додати його рядок і в Google.
4. Client ID і Client Secret — у рядки групи «Вхід через Google», перемикач «Увімкнено» → зберегти.
5. «Перевірити налаштування» (формат Client ID, стан секрету, перемикач, кількість адрес, discovery/JWKS Google з сервера) →
   «Спробувати на сайті» (сторінка відгуків першого міста; повний вхід — своїм акаунтом Google).

## Вимоги до сервера (fastpanel)

PHP 8.4 (`/opt/php84/bin/php`) з intl/pdo_mysql/gd/zip/opcache, `composer` у `/usr/local/bin`, `mysqldump`/`mysql`,
`openssl`, `tar`, `gzip`, `crontab`, `curl`; ssh під root (деплой робить `sudo -u <user>`), FastPanel-акаунт сайту,
nginx з `disable_symlinks if_not_owner` (симлінки власника дозволені — так і є), `date.timezone = Europe/Kyiv` у pool php-fpm.

## Перевірка скриптів

`bash tests/site/run.sh` (CI job `site-ops`): shellcheck, валідатор на реєстрі-фікстурі, `sites.example.yaml` й паку-фікстурі, `docker compose config` стенда,
сухі прогони всіх команд (`DRY_RUN=1`) і відмови запобіжників, `bin/site new` у тимчасовій теці.
