#!/usr/bin/env bash
# shellcheck disable=SC2016  # бектики в очікуваних рядках — синтаксис правил traefik, не підстановка
# Тести bin/site без сервера (CI job site-ops; локально: bash tests/site/run.sh):
#   перевірка shellcheck усіх скриптів, валідатор на реєстрі-фікстурі й паку-фікстурі, `docker compose config` стенда,
#   сухі прогони DRY_RUN=1 команд і відмови запобіжників (стара схема, невідомий ключ).
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
export SITES_FILE=tests/site/sites.yaml DRY_RUN=1 SITE_YES=1 SITE_NO_TLS=1
pass=0 fail=0
t() { # t <опис> <очікуваний код: 0|!0> <команда…>
    local name="$1" want="$2"; shift 2
    local out rc; out="$("$@" 2>&1)"; rc=$?
    if { [ "$want" = 0 ] && [ "$rc" = 0 ]; } || { [ "$want" = '!0' ] && [ "$rc" != 0 ]; }; then
        pass=$((pass + 1)); echo "ok   $name"
    else
        fail=$((fail + 1)); echo "FAIL $name (код $rc)"; echo "$out" | sed 's/^/     | /' | tail -n 25
    fi
    LAST="$out"
}
has() { if grep -qF -- "$1" <<<"$LAST"; then pass=$((pass + 1)); echo "ok     ↳ є «$1»"; else fail=$((fail + 1)); echo "FAIL   ↳ нема «$1»"; fi; }

sc_files=(bin/site bin/site.d/*.sh tests/site/run.sh)
if command -v shellcheck >/dev/null; then t "shellcheck" 0 shellcheck -x "${sc_files[@]}"
elif command -v docker >/dev/null; then t "shellcheck (docker)" 0 docker run --rm -e LANG=C.UTF-8 -e LC_ALL=C.UTF-8 -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable -x "${sc_files[@]}"
else echo "skip shellcheck: нема ні shellcheck, ні docker"; fi
t "python: синтаксис валідатора" 0 python3 -m py_compile bin/site.d/_validate.py

# Валідатор
t "validate: реєстр-фікстура" 0 bin/site validate
has "validate: OK"
t "validate: пак-фікстура client" 0 bin/site validate client
t "validate: пак-фікстура demo" 0 bin/site validate demo
t "validate: site-template" 0 bin/site validate site-template
t "validate: приклад реєстру sites.example.yaml" 0 env SITES_FILE=sites.example.yaml bin/site validate
bad="$(mktemp -d)"; trap 'rm -rf "$bad"' EXIT
cp -a tests/site/pack-demo "$bad/pack-beta"; sed -i -e 's/\bdemo\b/beta/g' "$bad/pack-beta/site.yaml" "$bad/pack-beta/config/parameters.yaml"
cp -a site-template/. "$bad/"; printf 'DATABASE_URL=mysql://u:secretpass@h/db\n' > "$bad/.env.local"; printf 'parameters:\n    app.nope: 1\n' > "$bad/config/parameters.yaml"
t "validate: пак із секретом і невідомим параметром — відмова" '!0' bin/site validate "$bad"
has ".env.local"; has "невідомий рушію параметр"
printf 'parameters:\n    app.crm: { system: keycrm, bitrix24: { hook: "https://x.bitrix24.ua/rest/1/tok1234/" } }\n' > "$bad/config/parameters.yaml"
t "validate: невідома CRM і вебхук Bitrix24 у паку — відмова" '!0' bin/site validate "$bad"
has "app.crm.system"; has "вебхука Bitrix24"
printf 'parameters:\n    app.blog_prefix: "Новини"\n' > "$bad/config/parameters.yaml"
t "validate: app.blog_prefix не [a-z0-9-] — відмова" '!0' bin/site validate "$bad"
has "app.blog_prefix"
printf 'parameters:\n    app.blog_prefix: services\n' > "$bad/config/parameters.yaml"
t "validate: app.blog_prefix = зарезервований розділ — відмова" '!0' bin/site validate "$bad"
has "зарезервований"
printf 'parameters:\n    app.blog_prefix: news\n    app.catalog.url_scheme: root\n' > "$bad/config/parameters.yaml"; rm -f "$bad/.env.local"
t "validate: app.blog_prefix news + схема root — ок" 0 bin/site validate "$bad"
printf 'parameters:\n    app.calculator.mode: area\n    app.calculator.path: { uk: "/kalkuliator/", ru: "/ru/kalkuliator/" }\n' > "$bad/config/parameters.yaml"
t "validate: app.calculator.* коректні — ок" 0 bin/site validate "$bad"
printf 'parameters:\n    app.calculator.mode: table\n    app.calculator.path: { uk: "/kalkuliator", ru: "/kalkuliator/" }\n' > "$bad/config/parameters.yaml"
t "validate: app.calculator.mode і .path хибні — відмова" '!0' bin/site validate "$bad"
has "app.calculator.mode"; has "app.calculator.path.uk"; has "app.calculator.path.ru"
sed -e 's/^          proxy: edge$/          proxy: nginx/' tests/site/sites.yaml > "$bad/sites-proxy.yaml"
t "validate: невідомий server.proxy — відмова" '!0' env SITES_FILE="$bad/sites-proxy.yaml" bin/site validate
has "server.proxy"
sed -e '/^          runtime: fastpanel$/a\          proxy: edge' tests/site/sites.yaml > "$bad/sites-proxy2.yaml"
t "validate: proxy на fastpanel — відмова" '!0' env SITES_FILE="$bad/sites-proxy2.yaml" bin/site validate
t "site_ctx: proxy на fastpanel — відмова" '!0' env SITES_FILE="$bad/sites-proxy2.yaml" bin/site deploy client HEAD
sed -e 's#^          compose: /opt/stand/compose/beta.yml$#          compose: ""#' tests/site/sites.yaml > "$bad/sites-compose.yaml"
t "validate: порожній server.compose — відмова" '!0' env SITES_FILE="$bad/sites-compose.yaml" bin/site validate
has "server.compose"
sed -e '/^          runtime: fastpanel$/a\          compose: docker-compose.traefik.yml' tests/site/sites.yaml > "$bad/sites-compose2.yaml"
t "validate: compose на fastpanel — відмова" '!0' env SITES_FILE="$bad/sites-compose2.yaml" bin/site validate
t "site_ctx: compose на fastpanel — відмова" '!0' env SITES_FILE="$bad/sites-compose2.yaml" bin/site deploy client HEAD

# compose стенда
if command -v docker >/dev/null; then
    t "compose config: стенд за traefik" 0 env SITE=demo SITE_ROOT=/tmp/site-demo docker compose -f docker-compose.traefik.yml config --services
    has nginx; has mysql; has mailpit
    t "compose config: хости з реєстру в мітках traefik" 0 env SITE=demo SITE_ROOT=/tmp/site-demo SITE_HOST=demo.example.com SITE_ADMIN=demo-admin.example.com SITE_MAIL_HOST=demo-mail.example.com docker compose -f docker-compose.traefik.yml config
    has 'Host(`demo.example.com`)'; has 'Host(`demo-admin.example.com`)'; has 'Host(`demo-mail.example.com`)'; has '+-demo.example.com$$`)'
    t "compose config: без хостів — дефолти *.localhost" 0 env SITE=demo SITE_ROOT=/tmp/site-demo docker compose -f docker-compose.traefik.yml config
    has 'Host(`demo.localhost`)'; has 'Host(`demo-mail.localhost`)'
    t "compose config: без SITE_ROOT — помилка" '!0' env SITE=demo docker compose -f docker-compose.traefik.yml config -q
    edgeroot="$(mktemp -d)/site-edgedemo"; mkdir -p "$edgeroot/shared"; printf 'MYSQL_ROOT_PASSWORD=x
' > "$edgeroot/shared/db.env"
    t "compose config: прод за edge" 0 env SITE=edgedemo SITE_ROOT="$edgeroot" docker compose -f docker-compose.edge.yml config
    has "service-edgedemo-nginx"; has "external: true"; has "Dockerfile.edge"; has "MYSQL_ROOT_PASSWORD"
    if grep -qE '^ +(ports|labels):' docker-compose.edge.yml; then fail=$((fail + 1)); echo "FAIL   ↳ edge: ports/labels у compose"; else pass=$((pass + 1)); echo "ok     ↳ edge: без ports і міток traefik"; fi
    rm -rf "$(dirname "$edgeroot")"
fi

# Лічильник коментарів у HTML (bin/site doctor: «коментарі у HTML: 0») на фікстурі
t "python: синтаксис лічильника коментарів" 0 python3 -m py_compile bin/site.d/_htmlcomments.py
printf '<!DOCTYPE html><html><head><!--[if IE]><p>x</p><![endif]--><style>/* a */ b{}</style><script type="application/ld+json">{"a":"/* не рахувати */"}</script><script>\n// c\nvar a=1; /* d */</script></head><body><!-- e --><p>t</p></body></html>' > "$bad/dirty.html"
printf '<!DOCTYPE html><html><head><style>b{}</style><script type="application/ld+json">{"a":"/* не рахувати */"}</script><script>var a=1;</script></head><body><p>t</p></body></html>' > "$bad/clean.html"
t "лічильник: брудна сторінка — 4 коментарі" 0 python3 bin/site.d/_htmlcomments.py "$bad/dirty.html"
has "total=4"; has "html=1"; has "block=2"; has "line=1"
t "лічильник: чиста сторінка — 0" 0 python3 bin/site.d/_htmlcomments.py "$bad/clean.html"
has "total=0"

# Диспетчер
t "help" 0 bin/site help
has deploy; has doctor; has cutover
t "невідома команда" '!0' bin/site nope
t "команда з _ не запускається" '!0' bin/site _lib
t "некоректний ключ" '!0' bin/site deploy 'Bad_Key'
t "ключа нема в реєстрі" '!0' bin/site deploy nosuch

# Сухі прогони
t "deploy client (ssh, fastpanel, пак-фікстура)" 0 bin/site deploy client HEAD
has "тег"
has "[dry-run] ssh client.example.com deploy"
t "deploy demo (local, docker, --pack)" 0 bin/site deploy demo HEAD --pack tests/site/pack-demo
has "[dry-run] smoke"; has "перемикання на"
t "backup edgedemo (ssh, docker + proxy edge)" 0 bin/site backup edgedemo
has "[dry-run] ssh edge.example.com backup"
t "_remote.sh deploy: docker/edge (сухий прогін на сервері)" 0 env SITE_RUNTIME=docker SITE_PROXY=edge SITE_CRON=0 bash bin/site.d/_remote.sh deploy edgedemo /tmp/nonexistent/site-edgedemo t1
has "рантайм docker/edge"; has "shared/db.env"; has "docker-compose.edge.yml"
t "_remote.sh deploy: docker/traefik — типовий compose і хости стенда" 0 env SITE_RUNTIME=docker SITE_CRON=0 SITE_HOST=alpha.stand.example.net bash bin/site.d/_remote.sh deploy alpha /tmp/nonexistent/site-alpha t1
has "docker-compose.traefik.yml"; has "app:html:strip-static public/themes"
t "_remote.sh deploy: fastpanel — статика теми абсолютним шляхом релізу" 0 env SITE_RUNTIME=fastpanel SITE_CRON=0 bash bin/site.d/_remote.sh deploy client /tmp/nonexistent/site-client t1
has "app:html:strip-static /tmp/nonexistent/site-client/releases/t1/public/themes"
t "_remote.sh deploy: SITE_COMPOSE_FILE замість типового" 0 env SITE_RUNTIME=docker SITE_CRON=0 SITE_COMPOSE_FILE=/opt/stand/compose/beta.yml bash bin/site.d/_remote.sh deploy beta /tmp/nonexistent/site-beta t1
has "/opt/stand/compose/beta.yml"
t "deploy beta (local, docker, server.compose з реєстру)" 0 bin/site deploy beta HEAD --pack "$bad/pack-beta"
has "/opt/stand/compose/beta.yml"
t "_remote.sh: proxy edge на fastpanel — відмова" '!0' env SITE_RUNTIME=fastpanel SITE_PROXY=edge bash bin/site.d/_remote.sh doctor edgedemo /tmp/x/site-edgedemo
t "doctor edgedemo (ssh, dry-run)" 0 bin/site doctor edgedemo
has "docker"
t "rollback client" 0 bin/site rollback client
t "cutover client" 0 bin/site cutover client
t "cutover --undo client" 0 bin/site cutover client --undo
t "cutover на docker — відмова" '!0' bin/site cutover demo
t "backup client" 0 bin/site backup client
t "backup --all" 0 bin/site backup --all
t "restore client (файл)" 0 bin/site restore client tests/site/sites.yaml --yes
has "[dry-run] ssh client.example.com restore"
t "restore без архіву" '!0' bin/site restore client
t "archive client без backup_target — відмова" '!0' bin/site archive client
t "archive client із SITE_NO_COPY" 0 env SITE_NO_COPY=1 bin/site archive client
t "doctor --all" 0 bin/site doctor --all
has "[SKIP] стара схема"
t "doctor --all --notify (без каналу)" 0 bin/site doctor --all --notify
t "sync demo (dry-run, фікстурний пак)" 0 bin/site sync demo --notify
has "→ deploy"; has "[dry-run] notify"
t "sync old (стара схема) — пропуск без помилки" 0 bin/site sync old
has "стара схема"
t "sync без ключів" '!0' bin/site sync

# Запобіжники старої схеми
t "deploy old (стара схема)" '!0' bin/site deploy old
has "стара схема"
t "backup old" '!0' bin/site backup old
t "rollback old" '!0' bin/site rollback old
t "_remote.sh: шлях не */site-<key>" '!0' env SITE_RUNTIME=fastpanel bash bin/site.d/_remote.sh doctor old /opt/x/old-app

# new: сухий прогін і справжнє створення в тимчасовій теці
t "new (dry-run)" 0 bin/site new demo3 demo3.example.com --dir="$bad/site-demo3"
t "new: ключ уже в реєстрі — відмова" '!0' bin/site new demo demo.example.com --dir="$bad/site-demo"
newdir="$(mktemp -d)/site-demo2"
t "new demo2 (справжній, без github)" 0 env DRY_RUN=0 bin/site new demo2 demo2.example.com --name=Demo --scheme=flat --dir="$newdir"
has "пак створено"; has "key: demo2"
t "new: пак проходить validate" 0 bin/site validate "$newdir"
t "new: ключ теми підставлено" 0 grep -q 'key: demo2' "$newdir/config/parameters.yaml"
t "new: схема flat" 0 grep -q 'app.catalog.url_scheme: flat' "$newdir/config/parameters.yaml"
# Своя тема в паку (bin/site theme)
t "theme list" 0 bin/site theme list
has "services/home.html.twig"; has "services/partials/_lead_form.html.twig"
t "theme new (заготовки base+home, theme.css)" 0 env DRY_RUN=0 bin/site theme new "$newdir"
has "templates/services/base.html.twig"; has "theme.css"
t "theme new: заготовка успадковує @engine" 0 grep -q "extends '@engine/services/home.html.twig'" "$newdir/templates/services/home.html.twig"
t "theme new: блок body у заготовці" 0 grep -q "{% block body %}" "$newdir/templates/services/home.html.twig"
t "theme new --copy partial" 0 env DRY_RUN=0 bin/site theme new "$newdir" --copy services/partials/_lead_form.html.twig
t "theme new: копія містить форму" 0 grep -q "data-lead-form" "$newdir/templates/services/partials/_lead_form.html.twig"
t "theme new: адмінку перекрити не можна" '!0' env DRY_RUN=0 bin/site theme new "$newdir" admin/dashboard.html.twig
t "theme new: пак із темою проходить validate" 0 bin/site validate "$newdir"
t "theme diff (engine: main)" 0 bin/site theme diff "$newdir"
has "перекрито 3"
rm -rf "$(dirname "$newdir")"

echo "== site-ops: ok $pass, fail $fail"
[ "$fail" = 0 ]
