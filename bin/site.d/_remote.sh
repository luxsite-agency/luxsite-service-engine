#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# shellcheck disable=SC2012,SC2001,SC2016  # імена архівів/релізів — [0-9a-z.-]; sed/бектики — SQL, не підстановка
# Серверна частина bin/site: виконується НА СЕРВЕРІ сайту. bin/site передає цей файл через ssh (або запускає локально
# для server.host: local) і викликає:
#   _remote.sh <дія> <key> <root> [аргументи]
#   дії: deploy | rollback | cutover | uncutover | backup | restore | archive | doctor | prune
# Деплой кладе копію в <root>/site-remote.sh — для cron бекапу без операторської машини.
#
# Два рантайми (env SITE_RUNTIME):
#   fastpanel — PHP-FPM + nginx панелі FastPanel без Docker: ssh під root, файли від імені SITE_USER (sudo -u),
#               php = SITE_PHP (бінарник панелі), після перемикання — SITE_FPM_RELOAD (скидає opcache/realpath);
#   docker    — compose поточного релізу, SITE_ROOT = <root>, проєкт service-<key>; compose-файл — SITE_COMPOSE_FILE
#               (server.compose у реєстрі: ім'я в релізі або абсолютний шлях), інакше за SITE_PROXY:
#               traefik — стенд (docker-compose.traefik.yml: mailpit, root/engine-паролі MySQL, мітки traefik з SITE_HOST/
#                         SITE_ADMIN/SITE_MAIL_HOST, php від SITE_UID:SITE_GID — типово користувач deploy і група теки сайту);
#               edge    — прод за зовнішнім nginx (docker-compose.edge.yml): лише мережа edge, портів назовні нема,
#                         паролі MySQL — shared/db.env (600, генерує перший deploy), php — від www-data (= SITE_USER).
#
# Розкладка <root> (…/site-<key>):
#   shared/.env.local (600) — секрети Symfony; shared/media — public/media; shared/log — var/log; .composer — кеш composer;
#   shared/theme — живий дизайн під токеном (templates/, public/theme.css, history/) → var/theme і public/theme-live релізу
#   releases/<tag>/ — рушій + пак (+ vendor), current → поточний; release.env — SITE_TAG, SITE_PREV_TAG
#   backups/ — архіви; db-init/ (docker) — дампи для першого підйому MySQL; ARCHIVED — маркер; docroot.prev — ціль
#   docroot панелі до cutover (fastpanel)
# Змінні: DRY_RUN=1, UNARCHIVE=1, SITE_CRON=0, SITE_SKIP_BACKUP=1, SITE_BACKUP_KEEP_DAYS (14), SITE_COMPOSER (бінарник),
#   SITE_EXTRA — інші хости міст на окремих доменах (domains.extra через пробіл): smoke і http-перевірка їх теж.
set -euo pipefail

ACTION="${1:?дія}"; KEY="${2:?key}"; ROOT="${3:?root}"; shift 3
DRY_RUN="${DRY_RUN:-0}"
RUNTIME="${SITE_RUNTIME:-fastpanel}"
P="site-$KEY"

log() { printf '>> %s\n' "$*" >&2; }
warn() { printf '!! %s\n' "$*" >&2; }
die() { printf '!! %s: %s\n' "$P" "$*" >&2; exit 1; }
run() {
    if [ "$DRY_RUN" = 1 ]; then printf '[dry-run] %s\n' "$(printf '%q ' "$@")" >&2; return 0; fi
    "$@"
}

[[ "$KEY" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || die "некоректний ключ"
case "$ROOT" in */site-"$KEY") ;; *) die "шлях '$ROOT' не за схемою */site-$KEY — відмова (стара схема не обслуговується)" ;; esac
case "$RUNTIME" in fastpanel | docker) ;; *) die "SITE_RUNTIME має бути fastpanel|docker" ;; esac
PROXY="${SITE_PROXY:-}"
if [ "$RUNTIME" = docker ] && [ -z "$PROXY" ]; then PROXY=traefik; fi
case "$RUNTIME:$PROXY" in fastpanel: | docker:traefik | docker:edge | docker:traefik-ext) ;; *) die "SITE_PROXY '$PROXY' не підходить до рантайму $RUNTIME" ;; esac

env_get() { if [ -f "$2" ]; then sed -n "s/^$1=//p" "$2" | tail -n1; fi; }
cur_tag() { env_get SITE_TAG "$ROOT/release.env"; }
prev_tag() { env_get SITE_PREV_TAG "$ROOT/release.env"; }

# as_user <команда…> — від імені користувача сайту (fastpanel: sudo -u, якщо ми не він); docker — як є
as_user() {
    if [ -n "${SITE_USER:-}" ] && [ "$(id -un)" != "$SITE_USER" ]; then run sudo -u "$SITE_USER" -H "$@"; else run "$@"; fi
}
own() { # own <шлях…> — власник = користувач сайту (лише коли ми root і користувач заданий)
    if [ -n "${SITE_USER:-}" ] && [ "$(id -u)" = 0 ]; then run chown -R "$SITE_USER:" "$@"; fi
}

# ---------- docker runtime ----------
COMPOSE_REL="$ROOT/current"
# compose-файл: SITE_COMPOSE_FILE (абсолютний шлях — як є; ім'я — у теці релізу), інакше типовий для SITE_PROXY
COMPOSE_FILE_NAME="${SITE_COMPOSE_FILE:-}"
if [ -z "$COMPOSE_FILE_NAME" ]; then
    COMPOSE_FILE_NAME=docker-compose.traefik.yml
    if [ "$PROXY" = edge ]; then COMPOSE_FILE_NAME=docker-compose.edge.yml; fi
    if [ "$PROXY" = traefik-ext ]; then COMPOSE_FILE_NAME=docker-compose.traefik-ext.yml; fi
fi
# прод у Docker (edge — за зовнішнім nginx, traefik-ext — за чужим traefik): www-data, паролі MySQL у shared/db.env, без mailpit
is_prod_docker() { [ "$PROXY" = edge ] || [ "$PROXY" = traefik-ext ]; }
# traefik-ext: мітки traefik — окремий файл shared/traefik-labels.yml, який deploy генерує з реєстру (traefik_labels нижче)
LABELS_FILE="$ROOT/shared/traefik-labels.yml"
compose_args() {
    printf '%s\n' -f "$(compose_file)"
    if [ "$PROXY" = traefik-ext ] && [ -s "$LABELS_FILE" ]; then printf '%s\n' -f "$LABELS_FILE"; fi
}
compose_file() { case "$COMPOSE_FILE_NAME" in /*) printf '%s' "$COMPOSE_FILE_NAME" ;; *) printf '%s/%s' "$COMPOSE_REL" "$COMPOSE_FILE_NAME" ;; esac; }
# Змінні для compose-файлу стенда (traefik): хости з реєстру і uid/gid для php у контейнері (типово — хто робить deploy
# і група теки сайту, щоб файли в shared/ лишались «своїми»). Мають дефолти в самому compose, тож для edge зайві, але не шкодять.
if [ "$RUNTIME" = docker ]; then
    _apex="${SITE_HOST:-$KEY.localhost}"
    SITE_MAIL_HOST="${SITE_MAIL_HOST:-$KEY-mail.${_apex#"$KEY".}}"
    SITE_UID="${SITE_UID:-$(id -u)}"
    SITE_GID="${SITE_GID:-$(stat -c %g "$ROOT" 2>/dev/null || id -g)}"
    export SITE_MAIL_HOST SITE_UID SITE_GID
fi
compose() {
    local files; mapfile -t files < <(compose_args)
    SITE="$KEY" SITE_ROOT="$ROOT" docker compose -p "service-$KEY" --project-directory "$COMPOSE_REL" "${files[@]}" "$@" </dev/null
}
compose_in() {
    local files; mapfile -t files < <(compose_args)
    SITE="$KEY" SITE_ROOT="$ROOT" docker compose -p "service-$KEY" --project-directory "$COMPOSE_REL" "${files[@]}" "$@"
}
# traefik_labels: shared/traefik-labels.yml для proxy traefik-ext (22.10.2026). Окремий роутер і сертифікат на КОЖЕН домен
# (SITE_HOST і SITE_EXTRA): один роутер з Host(a) || Host(b) дав би один сертифікат з усіма доменами в SAN — це видно в
# CT-логах і зв'язує домени між собою. www.<домен> — лише з server.www_redirect: true (редирект на домен; DNS www має бути,
# інакше LE не видасть сертифікат і для самого домену). Адмінка — свій роутер з X-Robots-Tag: noindex. Конфіг traefik
# сервера не змінюється — лише мітки контейнера nginx цього стека; решта контейнерів — traefik.enable=false (у compose).
traefik_labels() {
    [ "$PROXY" = traefik-ext ] || return 0
    local net="${SITE_PROXY_NETWORK:-}" ep="${SITE_TRAEFIK_ENTRYPOINT:-websecure}" cr="${SITE_TRAEFIK_CERTRESOLVER:-}" r="service-$KEY" n=0 h hosts
    if [ -z "$net" ] || [ -z "$cr" ]; then die "traefik-ext: потрібні server.network і server.certresolver у реєстрі"; fi
    hosts="${SITE_HOST:-} ${SITE_EXTRA:-}"
    if [ -z "${hosts// /}" ]; then die "traefik-ext: нема доменів (domains.site / domains.extra)"; fi
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] згенерувати $LABELS_FILE: роутери на $hosts${SITE_ADMIN:+ і $SITE_ADMIN}"; return 0; fi
    {
        echo "# Згенеровано bin/site deploy $(date -u +%FT%TZ) з реєстру — не правити руками (перезапишеться)."
        echo "services:"
        echo "  nginx:"
        echo "    labels:"
        echo "      traefik.enable: \"true\""
        echo "      traefik.docker.network: \"$net\""
        echo "      traefik.http.services.$r.loadbalancer.server.port: \"80\""
        for h in $hosts; do
            n=$((n + 1))
            if [ "${SITE_WWW_REDIRECT:-}" = yes ]; then
                echo "      traefik.http.routers.$r-$n.rule: \"Host(\`$h\`) || Host(\`www.$h\`)\""
                echo "      traefik.http.routers.$r-$n.middlewares: \"$r-www\""
            else
                echo "      traefik.http.routers.$r-$n.rule: \"Host(\`$h\`)\""
            fi
            echo "      traefik.http.routers.$r-$n.entrypoints: \"$ep\""
            echo "      traefik.http.routers.$r-$n.tls.certresolver: \"$cr\""
            echo "      traefik.http.routers.$r-$n.service: \"$r\""
        done
        if [ -n "${SITE_ADMIN:-}" ]; then
            echo "      traefik.http.routers.$r-admin.rule: \"Host(\`$SITE_ADMIN\`)\""
            echo "      traefik.http.routers.$r-admin.entrypoints: \"$ep\""
            echo "      traefik.http.routers.$r-admin.tls.certresolver: \"$cr\""
            echo "      traefik.http.routers.$r-admin.service: \"$r\""
            echo "      traefik.http.routers.$r-admin.middlewares: \"$r-noindex\""
            echo "      traefik.http.middlewares.$r-noindex.headers.customresponseheaders.X-Robots-Tag: \"noindex, nofollow\""
        fi
        if [ "${SITE_WWW_REDIRECT:-}" = yes ]; then
            printf '      traefik.http.middlewares.%s-www.redirectregex.regex: "^https?://www\\\\.(.+)"\n' "$r"
            echo "      traefik.http.middlewares.$r-www.redirectregex.replacement: \"https://\$\${1}\""
            echo "      traefik.http.middlewares.$r-www.redirectregex.permanent: \"true\""
        fi
    } > "$LABELS_FILE.tmp" && mv "$LABELS_FILE.tmp" "$LABELS_FILE"
    log "traefik-ext: мітки $n домен(ів)${SITE_ADMIN:+ + адмінка} → shared/traefik-labels.yml"
}
# mysql_root <клієнт> <аргументи…> — mysql/mysqldump у контейнері mysql від root; пароль — зі змінної самого контейнера
# (MYSQL_ROOT_PASSWORD: стенд traefik — «root» у compose, edge — shared/db.env), у командному рядку й лозі його нема.
# Аргументи — без пробілів і лапок (ключ сайту, прапорці); stdin — дамп для mysql.
mysql_root() { local c="$1"; shift; compose_in exec -T mysql sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" exec $c -uroot $*"; }
wait_mysql() {
    for _ in $(seq 1 60); do
        [ "$(docker inspect -f '{{.State.Health.Status}}' "$(compose ps -q mysql 2>/dev/null)" 2>/dev/null || true)" = healthy ] && return 0
        sleep 3
    done
    die "mysql не став healthy за 3 хв"
}

# ---------- php / console у релізі ----------
# php_in <реліз> <аргументи php…>
php_in() {
    local rel="$1"; shift
    if [ "$RUNTIME" = docker ]; then COMPOSE_REL="$rel" run compose exec -T php php "$@"; else as_user "${SITE_PHP:-php}" "$@"; fi
}
console() { # console <реліз> <команда…>
    local rel="$1"; shift
    if [ "$RUNTIME" = docker ]; then php_in "$rel" bin/console "$@"; else php_in "$rel" "$rel/bin/console" "$@"; fi
}
# Стенд (docker): повернути контейнери на реліз $1. vendor і var — спільні томи, тож після невдалого кандидата там його
# залежності й кеш: без повторного composer install старий код падає на пакеті, якого вже нема (500 на стенді, 17.10).
stand_back() {
    local back="$ROOT/releases/$1"
    COMPOSE_REL="$back" compose up -d >/dev/null 2>&1 || true
    COMPOSE_REL="$back" compose exec -T php sh -c 'rm -rf var/cache/*' >/dev/null 2>&1 || true
    composer_install "$back" >/dev/null 2>&1 || warn "stand_back: composer install для $1 не пройшов"
    COMPOSE_REL="$back" fpm_reload
}

composer_install() {
    local rel="$1"
    if [ "$RUNTIME" = docker ]; then
        COMPOSE_REL="$rel" run compose exec -T php composer install --no-dev --optimize-autoloader --no-interaction --no-progress
        return
    fi
    local comp="${SITE_COMPOSER:-}"
    [ -n "$comp" ] || comp="$(command -v composer || true)"; [ -n "$comp" ] || comp=/usr/local/bin/composer
    [ "$DRY_RUN" = 1 ] || [ -x "$comp" ] || die "нема composer ($comp); задайте SITE_COMPOSER"
    run mkdir -p "$ROOT/.composer"; own "$ROOT/.composer"
    as_user env COMPOSER_HOME="$ROOT/.composer" COMPOSER_NO_INTERACTION=1 COMPOSER_NO_AUDIT=1 \
        "${SITE_PHP:-php}" "$comp" install --working-dir="$rel" --no-dev --optimize-autoloader --no-interaction --no-progress
}

# smoke <реліз>: кандидат без nginx — ядро Symfony обробляє запити до головної, sitemap і логіну адмінки (bin/smoke.php)
smoke() {
    local rel="$1" site="${SITE_HOST:-}" admin="${SITE_ADMIN:-}" urls=()
    [ -n "$site" ] || { warn "SITE_HOST не задано — smoke пропущено"; return 0; }
    # головна, каталог, райони, FAQ, sitemap, robots (+ smoke.php сам додає з sitemap першу послугу/район/пост і першу
    # картинку Liip зі сторінки послуги) — сторінки з різними шаблонами, щоб зламаний twig не доїхав до перемикання
    urls+=("https://$site/" "https://$site/services/" "https://$site/districts/" "https://$site/faq/" "https://$site/sitemap.xml" "https://$site/robots.txt")
    # другий домен міста (domains.extra): головна, карта (smoke.php відкриває з неї сторінки саме цього хоста) і robots
    local h
    for h in ${SITE_EXTRA:-}; do urls+=("https://$h/" "https://$h/sitemap.xml" "https://$h/robots.txt"); done
    [ -n "$admin" ] && urls+=("https://$admin/login")
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] smoke $rel: ${urls[*]}"; return 0; fi
    if [ "$RUNTIME" = docker ]; then php_in "$rel" bin/smoke.php "${urls[@]}"; else php_in "$rel" "$rel/bin/smoke.php" "${urls[@]}"; fi
}

# http_check: через справжній веб-сервер після перемикання. fastpanel — nginx на SITE_CHECK_IP (порожньо = пропуск);
# docker — wget усередині контейнера nginx з Host. Головна апекса і кожного хоста SITE_EXTRA (другий домен міста).
http_check() {
    local site="${SITE_HOST:-}" admin="${SITE_ADMIN:-}" code h
    [ -n "$site" ] || return 0
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] http_check $site${SITE_EXTRA:+ $SITE_EXTRA}"; return 0; }
    if [ "$RUNTIME" = docker ]; then
        local ok
        for _ in $(seq 1 15); do
            ok=1
            for h in "$site" ${SITE_EXTRA:-}; do
                compose exec -T nginx wget -q -O /dev/null -T 20 --header "Host: $h" --header "X-Forwarded-Proto: https" http://127.0.0.1/ >/dev/null 2>&1 || { ok=0; break; }
            done
            if [ "$ok" = 1 ] \
               && { [ -z "$admin" ] || compose exec -T nginx wget -q -O /dev/null -T 20 --header "Host: $admin" --header "X-Forwarded-Proto: https" http://127.0.0.1/login >/dev/null 2>&1; }; then
                log "http: $site/${SITE_EXTRA:+ $SITE_EXTRA} ${admin:+і $admin/login }→ 2xx"; return 0
            fi
            sleep 3
        done
        warn "http: $site/${SITE_EXTRA:+, $SITE_EXTRA} або $admin/login не відповідає 2xx"; return 1
    fi
    [ -n "${SITE_CHECK_IP:-}" ] || { log "http: SITE_CHECK_IP не задано — перевірка через nginx пропущена"; return 0; }
    command -v curl >/dev/null || return 0
    for h in "$site" ${SITE_EXTRA:-}; do
        code=""
        for _ in $(seq 1 5); do
            code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$h:443:$SITE_CHECK_IP" "https://$h/" </dev/null || true)"
            [ "$code" = 200 ] && break
            sleep 2
        done
        if [ "$code" != 200 ]; then warn "http: https://$h/ через nginx → $code"; return 1; fi
        log "http: https://$h/ через nginx → 200"
    done
}

fpm_reload() {
    # docker: php-fpm у контейнері з opcache.validate_timestamps=0 тримає скомпільовані файли var/cache і vendor
    # (спільні томи між релізами) — без перезавантаження після cache:clear новий код іде зі старим контейнером DI
    # (TypeError у конструкторах → 500), а відкат — навпаки (граблі 18.10.2026 на сайті з Joomla).
    if [ "$RUNTIME" = docker ]; then
        [ "$DRY_RUN" = 1 ] && { log "[dry-run] php-fpm reload (USR2)"; return 0; }
        compose exec -T php sh -c 'kill -USR2 1' >/dev/null 2>&1 || warn "php-fpm reload (USR2) не вдався — opcache не скинуто"
        sleep 1
        return 0
    fi
    [ "$RUNTIME" = fastpanel ] || return 0
    [ -n "${SITE_FPM_RELOAD:-}" ] || { warn "SITE_FPM_RELOAD не задано — opcache/realpath-кеш php-fpm не скинуто (старий код може жити до revalidate)"; return 0; }
    run bash -c "$SITE_FPM_RELOAD"
}

# ---------- перший запуск: shared, секрети ----------
gen_hex() { openssl rand -hex "$1" | tr -d '\n'; }
# db_env_edge: shared/db.env (600) — паролі MySQL стека edge; compose читає його як env_file сервісу mysql.
# Генерується один раз. Якщо .env.local уже є, а db.env нема — паролі не вгадати: відмова з підказкою.
db_env_edge() {
    local dbenv="$ROOT/shared/db.env"
    is_prod_docker || return 0
    [ -s "$dbenv" ] && return 0
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] створити shared/db.env (паролі MySQL стека)"; return 0; fi
    [ -s "$ROOT/shared/.env.local" ] && die "є shared/.env.local, але нема shared/db.env — покладіть db.env (MYSQL_ROOT_PASSWORD, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD) під DATABASE_URL з .env.local"
    ( umask 077
      printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_DATABASE=%s\nMYSQL_USER=%s\nMYSQL_PASSWORD=%s\n' "$(gen_hex 16)" "$KEY" "$KEY" "$(gen_hex 16)" > "$dbenv" )
    log "створено shared/db.env (паролі MySQL стека, 600)"
}
first_run() {
    run mkdir -p "$ROOT/shared/media" "$ROOT/shared/log" "$ROOT/shared/theme/templates" "$ROOT/shared/theme/public" "$ROOT/shared/theme/history" "$ROOT/releases" "$ROOT/backups"
    # корінь і releases/ створює root (ssh), а симлінк current ставить користувач сайту — тому власник усього кореня = він
    # (на першому запуску тут лише shared і щойно залитий реліз, chown -R дешевий)
    own "$ROOT"
    [ "$RUNTIME" = docker ] && run mkdir -p "$ROOT/db-init"
    db_env_edge
    local env="$ROOT/shared/.env.local" legacy="${SITE_LEGACY_APP:-}"
    if [ ! -s "$env" ] && [ "$DRY_RUN" != 1 ]; then
        if [ -n "$legacy" ] && [ -s "$legacy/.env.local" ]; then
            cp -p "$legacy/.env.local" "$env"; chmod 600 "$env"; own "$env"
            log "shared/.env.local ← $legacy/.env.local (стара тека застосунку)"
        elif is_prod_docker; then
            local dbu dbp
            dbu="$(sed -n 's/^MYSQL_USER=//p' "$ROOT/shared/db.env")"; dbp="$(sed -n 's/^MYSQL_PASSWORD=//p' "$ROOT/shared/db.env")"
            umask 077
            {
                echo "# $P (docker за edge): env Symfony (секрети — не копіювати в чат/git/доки). Пошта вимкнена — заповніть MAILER_DSN."
                echo "APP_ENV=prod"; echo "APP_DEBUG=0"; echo "APP_SECRET=$(gen_hex 16)"
                echo "DATABASE_URL=mysql://$dbu:$dbp@mysql:3306/$KEY?serverVersion=8.0&charset=utf8mb4"
                echo "MAILER_DSN=null://null"; echo "MAIL_FROM=noreply@${SITE_HOST:-$KEY}"
                echo "ADMIN_HOST=${SITE_ADMIN:-admin.$KEY}"; echo "DEFAULT_URI=https://${SITE_HOST:-$KEY}"
                echo "GOOGLE_CLIENT_ID="; echo "GOOGLE_CLIENT_SECRET="; echo "STUDIO_API_TOKEN="; echo "PIPEDRIVE_API_TOKEN="; echo "LEAD_RATE_LIMIT_ALLOWLIST="
            } > "$env"
            umask 022; own "$env"; log "створено shared/.env.local (edge: БД стека з db.env, пошта null)"
        elif [ "$RUNTIME" = docker ]; then
            umask 077
            {
                echo "# $P (стенд docker): env Symfony. Прод-токенів тут бути не має."
                echo "APP_ENV=prod"; echo "APP_DEBUG=0"; echo "APP_SECRET=$(gen_hex 16)"
                echo "DATABASE_URL=mysql://engine:engine@mysql:3306/$KEY?serverVersion=8.0&charset=utf8mb4"
                echo "MAILER_DSN=smtp://mailpit:1025"; echo "MAIL_FROM=noreply@${SITE_HOST:-$KEY.local}"
                echo "ADMIN_HOST=${SITE_ADMIN:-$KEY-admin.local}"; echo "DEFAULT_URI=https://${SITE_HOST:-$KEY.local}"
                echo "GOOGLE_CLIENT_ID="; echo "GOOGLE_CLIENT_SECRET="; echo "STUDIO_API_TOKEN="; echo "PIPEDRIVE_API_TOKEN="; echo "BITRIX24_WEBHOOK_URL="; echo "LEAD_RATE_LIMIT_ALLOWLIST="
            } > "$env"
            umask 022; own "$env"; log "створено shared/.env.local (стенд)"
        else
            umask 077
            {
                echo "# $P: env Symfony (секрети — не копіювати в чат/git/доки). ЗАПОВНІТЬ DATABASE_URL і MAILER_DSN."
                echo "APP_ENV=prod"; echo "APP_DEBUG=0"; echo "APP_SECRET=$(gen_hex 16)"
                echo "DATABASE_URL=mysql://USER:PASSWORD@127.0.0.1:3306/DBNAME?serverVersion=8.0&charset=utf8mb4"
                echo "MAILER_DSN=null://null"; echo "MAIL_FROM=noreply@${SITE_HOST:-$KEY}"
                echo "ADMIN_HOST=${SITE_ADMIN:-admin.$KEY}"; echo "DEFAULT_URI=https://${SITE_HOST:-$KEY}"
                echo "GOOGLE_CLIENT_ID="; echo "GOOGLE_CLIENT_SECRET="; echo "STUDIO_API_TOKEN="; echo "PIPEDRIVE_API_TOKEN="; echo "BITRIX24_WEBHOOK_URL="; echo "LEAD_RATE_LIMIT_ALLOWLIST="
            } > "$env"
            umask 022; own "$env"
            die "створено $env із заготовкою — заповніть DATABASE_URL (і MAILER_DSN) на сервері й повторіть deploy"
        fi
    fi
    if [ -n "$legacy" ] && [ -d "$legacy/public/media" ] && [ -z "$(ls -A "$ROOT/shared/media" 2>/dev/null)" ] && [ "$DRY_RUN" != 1 ]; then
        cp -a "$legacy/public/media/." "$ROOT/shared/media/"; own "$ROOT/shared/media"
        log "shared/media ← $legacy/public/media ($(du -sh "$ROOT/shared/media" | cut -f1))"
    fi
    if [ "$DRY_RUN" != 1 ] && [ "$RUNTIME" = fastpanel ] && grep -q '^DATABASE_URL=mysql://USER:PASSWORD@' "$env"; then
        die "у $env ще заготовка DATABASE_URL — заповніть і повторіть deploy"
    fi
    settings_secret_key_env
    return 0
}

# settings_secret_key_env — SETTINGS_SECRET_KEY (шифрування секретів «Налаштування сайту» в адмінці, 21.10.2026) у наявному
# shared/.env.local: дописується один раз (як у shop-engine); значення не друкується. Без ключа секретні поля неактивні.
settings_secret_key_env() {
    local f="$ROOT/shared/.env.local"
    [ -f "$f" ] || return 0
    grep -q '^SETTINGS_SECRET_KEY=.\+' "$f" && return 0
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] shared/.env.local: додати SETTINGS_SECRET_KEY"; return 0; fi
    (umask 077; { grep -v '^SETTINGS_SECRET_KEY=' "$f"; echo "SETTINGS_SECRET_KEY=$(openssl rand -base64 32 | tr -d '\n')"; } > "$f.tmp.$$")
    chmod 600 "$f.tmp.$$" && mv "$f.tmp.$$" "$f" && own "$f"
    log "SETTINGS_SECRET_KEY додано в shared/.env.local"
}

# settings_key_state — yes / no / invalid (не 32 байти base64, 44 символи одним рядком). Значення не друкується.
settings_key_state() {
    local v
    v="$(grep -m1 '^SETTINGS_SECRET_KEY=' "$ROOT/shared/.env.local" 2>/dev/null | cut -d= -f2- | tr -d "\"' \r")"
    if [ -z "$v" ]; then echo no; return 0; fi
    if [ "${#v}" -eq 44 ] && printf '%s' "$v" | grep -Eq '^[A-Za-z0-9+/]{43}=$'; then echo yes; else echo invalid; fi
}

# link_release <реліз>: .env.local, public/media, var/log → shared
link_release() {
    local rel="$1"
    run rm -rf "$rel/public/media" "$rel/var/log" "$rel/var/theme" "$rel/public/theme-live"
    run mkdir -p "$rel/var"
    # живий дизайн (ThemeService): тека могла не існувати на старих сайтах — створити
    run mkdir -p "$ROOT/shared/theme/templates" "$ROOT/shared/theme/public" "$ROOT/shared/theme/history"
    run ln -sfn "$ROOT/shared/theme" "$rel/var/theme"
    run ln -sfn "$ROOT/shared/theme/public" "$rel/public/theme-live"
    run ln -sfn "$ROOT/shared/.env.local" "$rel/.env.local"
    run ln -sfn "$ROOT/shared/media" "$rel/public/media"
    run ln -sfn "$ROOT/shared/log" "$rel/var/log"
    own "$rel"
}

# ---------- deploy <tag> ----------
do_deploy() {
    local tag="$1" prev
    local rel="$ROOT/releases/$tag"
    if [ -f "$ROOT/ARCHIVED" ] && [ "${UNARCHIVE:-0}" != 1 ]; then die "сайт заархівовано ($ROOT/ARCHIVED); підняти лише з UNARCHIVE=1 і рішенням власника"; fi
    if [ ! -d "$rel" ]; then [ "$DRY_RUN" = 1 ] || die "нема контексту релізу $rel"; warn "[dry-run] контексту $rel нема"; fi
    first_run
    link_release "$rel"
    prev="$(cur_tag)"
    log "реліз $tag (попередній: ${prev:-нема}), рантайм $RUNTIME${PROXY:+/$PROXY}$([ "$RUNTIME" = docker ] && echo ", compose $(compose_file)")"

    if [ "$RUNTIME" = docker ]; then
        # контейнери стартують із нового релізу (./ → /app); vendor і var — спільні томи проєкту
        COMPOSE_REL="$rel"
        traefik_labels
        run compose up -d --build --remove-orphans
        [ "$DRY_RUN" = 1 ] || wait_mysql
        # var — спільний том: скомпільований контейнер попереднього релізу посилається на його бандли, і cache:clear нового
        # релізу (auto-scripts composer) падає на першому ж прибраному пакеті («Class …Bundle not found», 17.10 після #74)
        run compose exec -T php sh -c 'rm -rf var/cache/*'
    fi
    if [ -n "$prev" ] && [ "${SITE_SKIP_BACKUP:-0}" != 1 ]; then
        log "бекап перед деплоєм"
        do_backup pre-deploy >/dev/null
    elif [ -z "$prev" ] && [ -n "${SITE_LEGACY_APP:-}" ] && [ "${SITE_SKIP_BACKUP:-0}" != 1 ] && [ "$DRY_RUN" != 1 ]; then
        # перший реліз поверх живої БД старої схеми: міграції нижче її змінять — страховий бекап обов'язковий
        log "бекап БД старої схеми перед першими міграціями (pre-engine)"
        do_backup pre-engine >/dev/null
    fi
    log "composer install ($tag)"
    if ! composer_install "$rel"; then
        if [ "$RUNTIME" = docker ] && [ -n "$prev" ]; then warn "повернення контейнерів на $prev"; stand_back "$prev"; fi
        die "composer install не пройшов (скрипти auto-scripts/cache:clear — див. вище); поточний реліз лишився ${prev:-—}"
    fi
    log "міграції ($tag)"
    if ! console "$rel" doctrine:migrations:migrate -n --allow-no-migration; then
        if [ "$RUNTIME" = docker ] && [ -n "$prev" ]; then warn "повернення контейнерів на $prev"; stand_back "$prev"; fi
        die "міграції не пройшли; поточний реліз лишився ${prev:-—}. Бекап перед деплоєм — $ROOT/backups (*-pre-deploy.tar)"
    fi
    console "$rel" cache:clear >/dev/null
    fpm_reload # docker: живий php-fpm уже на новому релізі — скинути opcache старого контейнера DI
    # свій дизайн паку (templates/site/): синтаксис усіх шаблонів, не лише тих, що відкриває smoke
    if [ -n "$(find "$rel/templates/site" -name '*.twig' -print -quit 2>/dev/null)" ]; then
        log "lint:twig шаблонів паку ($tag)"
        # docker: робоча тека php-контейнера /app = реліз; fastpanel: as_user запускає php не з теки релізу, і відносний
        # templates/site там «not readable» (18.10.2026 — деплой зупинявся на першому ж паку з templates/)
        tpl_dir="templates/site"
        [ "$RUNTIME" = docker ] || tpl_dir="$rel/templates/site"
        if ! console "$rel" lint:twig "$tpl_dir"; then
            if [ "$RUNTIME" = docker ] && [ -n "$prev" ]; then warn "повернення контейнерів на $prev"; stand_back "$prev"; fi
            die "шаблони паку (templates/) не проходять lint:twig; поточний реліз лишився ${prev:-—}"
        fi
    fi
    if ! smoke "$rel"; then
        if [ "$RUNTIME" = docker ] && [ -n "$prev" ]; then warn "повернення контейнерів на $prev"; stand_back "$prev"; fi
        die "кандидат $tag не пройшов smoke; current лишився ${prev:-—} (міграції вже застосовано — див. бекап pre-deploy)"
    fi

    # Перемикання
    log "перемикання на $tag"
    switch_to "$tag" "$prev"
    if ! http_check; then
        if [ -n "$prev" ]; then
            warn "відкат на $prev"
            switch_to "$prev" ""
            [ "$RUNTIME" = docker ] && { stand_back "$prev"; }
        fi
        die "після перемикання сайт не відповідає; повернуто ${prev:-нічого — першого релізу нема куди відкочувати}"
    fi
    [ -f "$ROOT/ARCHIVED" ] && run mv "$ROOT/ARCHIVED" "$ROOT/ARCHIVED.lifted-$(date +%Y%m%d)"
    if [ "$DRY_RUN" != 1 ]; then install -m 755 "$rel/bin/site.d/_remote.sh" "$ROOT/site-remote.sh"; fi
    docroot_check
    cron_on
    do_prune "$tag" "$prev"
    log "готово: $P → $tag"
}

# switch_to <tag> <prev-для-release.env>: current → реліз, release.env, скидання opcache
switch_to() {
    local tag="$1" prev="$2" keep_prev
    if [ "$DRY_RUN" != 1 ]; then
        keep_prev="$prev"
        [ "$prev" = "$tag" ] && keep_prev="$(prev_tag)"
        printf 'SITE_TAG=%s\nSITE_PREV_TAG=%s\n' "$tag" "$keep_prev" > "$ROOT/release.env"
    fi
    as_user ln -sfn "releases/$tag" "$ROOT/current"
    COMPOSE_REL="$ROOT/current"
    fpm_reload
}

# docroot (fastpanel): симлінк панелі має вказувати на <root>/current/public; інакше — підказка про cutover
# all_docroots — головний симлінк панелі + docroot_extra (піддомени міст, адмінка): усі ведуть на той самий public
all_docroots() { echo "${SITE_DOCROOT:-} ${SITE_DOCROOT_EXTRA:-}"; }
docroot_check() {
    [ "$RUNTIME" = fastpanel ] && [ -n "${SITE_DOCROOT:-}" ] || return 0
    [ "$DRY_RUN" = 1 ] && return 0
    local want="$ROOT/current/public" have d
    for d in $(all_docroots); do
        have="$(readlink -f "$d" 2>/dev/null || true)"
        if [ "$have" = "$(readlink -f "$want")" ]; then log "docroot $d → current/public ✓"
        else warn "docroot $d → ${have:-?}, а не $want: сайт ще на старій схемі. Перемкнути: bin/site cutover $KEY (рішення власника)"; fi
    done
}

do_cutover() {
    [ "$RUNTIME" = fastpanel ] || die "cutover лише для fastpanel"
    [ -n "${SITE_DOCROOT:-}" ] || die "server.docroot у реєстрі не задано"
    [ -n "$(cur_tag)" ] || die "спершу deploy — релізу ще нема"
    local want="$ROOT/current/public" have d n
    have="$(readlink "$SITE_DOCROOT" 2>/dev/null || true)"
    for d in $(all_docroots); do
        if [ -e "$d" ] && [ ! -L "$d" ]; then
            # Новий сайт у FastPanel: панель створює docroot справжньою текою з index.php-заглушкою.
            # Лише таку (до 3 файлів, без ознак застосунку) відкладаємо в <docroot>.fp-placeholder; усе інше — руками.
            n="$(find "$d" -mindepth 1 -maxdepth 1 2>/dev/null | wc -l)"
            if [ "$n" -le 3 ] && [ ! -e "$d/vendor" ] && [ ! -e "$d/public" ] && [ ! -e "$d/.git" ] && [ ! -e "$d/bin" ] && [ ! -e "$d/wp-config.php" ]; then
                log "$d — тека-заглушка панелі ($n файл.) → $d.fp-placeholder"
                run mv "$d" "$d.fp-placeholder"
            else
                die "$d — не симлінк (справжня тека з даними, $n записів); перемикати руками"
            fi
        fi
    done
    if [ "$DRY_RUN" != 1 ] && [ -n "$have" ] && [ "$have" != "$want" ]; then echo "$have" > "$ROOT/docroot.prev"; fi
    for d in $(all_docroots); do as_user ln -sfn "$want" "$d"; done
    fpm_reload
    http_check || { warn "повертаю docroot на ${have:-?}"; if [ -n "$have" ]; then for d in $(all_docroots); do as_user ln -sfn "$have" "$d"; done; fi; fpm_reload; die "після cutover сайт не відповідає"; }
    log "cutover: $(all_docroots | wc -w) симлінк(ів) → $want (попередня ціль у $ROOT/docroot.prev)"
}
do_uncutover() {
    [ "$RUNTIME" = fastpanel ] || die "uncutover лише для fastpanel"
    local prev; prev="$(cat "$ROOT/docroot.prev" 2>/dev/null || true)"
    [ -n "$prev" ] || die "нема $ROOT/docroot.prev — нема куди повертати"
    local d
    for d in $(all_docroots); do as_user ln -sfn "$prev" "$d"; done
    fpm_reload
    log "docroot повернуто: $(all_docroots | wc -w) симлінк(ів) → $prev"
}

do_rollback() {
    local prev cur; cur="$(cur_tag)"; prev="$(prev_tag)"
    [ -n "$prev" ] || die "SITE_PREV_TAG порожній — нема куди відкочувати"
    [ -d "$ROOT/releases/$prev" ] || die "релізу $prev уже нема на диску"
    log "відкат $cur → $prev (міграції БД не відкочуються — для схеми є бекап pre-deploy)"
    if [ "$RUNTIME" = docker ]; then COMPOSE_REL="$ROOT/releases/$prev"; run compose up -d; run compose exec -T php sh -c 'rm -rf var/cache/*'; composer_install "$ROOT/releases/$prev"; fi
    console "$ROOT/releases/$prev" cache:clear >/dev/null || true
    if [ "$DRY_RUN" != 1 ]; then printf 'SITE_TAG=%s\nSITE_PREV_TAG=%s\n' "$prev" "$cur" > "$ROOT/release.env"; fi
    as_user ln -sfn "releases/$prev" "$ROOT/current"
    COMPOSE_REL="$ROOT/current"
    fpm_reload
    http_check || warn "після відкату сайт не відповідає 2xx — дивіться логи"
    log "готово: $P → $prev"
}

CRON_MARK="# bin/site backup $P"
cron_on() {
    [ "${SITE_CRON:-1}" = 1 ] || { log "cron бекапу не ставлю (SITE_CRON=0)"; return 0; }
    command -v crontab >/dev/null || { warn "нема crontab — щоденний бекап налаштувати вручну"; return 0; }
    crontab -l 2>/dev/null | grep -qF "$CRON_MARK" && return 0
    local m=$(( $(printf '%s' "$KEY" | cksum | cut -d' ' -f1) % 50 ))
    run sh -c "(crontab -l 2>/dev/null; echo '$m 3 * * * SITE_RUNTIME=$RUNTIME SITE_PROXY=$PROXY ${SITE_COMPOSE_FILE:+SITE_COMPOSE_FILE=$SITE_COMPOSE_FILE }SITE_USER=${SITE_USER:-} SITE_PHP=${SITE_PHP:-php} $ROOT/site-remote.sh backup $KEY $ROOT >> $ROOT/backups/backup.log 2>&1 $CRON_MARK') | crontab -"
    log "cron бекапу: щодня 03:$(printf %02d "$m")"
}
cron_off() {
    command -v crontab >/dev/null || return 0
    crontab -l 2>/dev/null | grep -qF "$CRON_MARK" || return 0
    run sh -c "crontab -l 2>/dev/null | grep -vF '$CRON_MARK' | crontab -"
    log "cron бекапу вимкнено"
}

# Лишає поточний, попередній і ще 2 найновіші релізи
do_prune() {
    local cur="${1:-$(cur_tag)}" prev="${2:-$(prev_tag)}" old n=0
    [ -d "$ROOT/releases" ] || return 0
    # shellcheck disable=SC2045
    for old in $(ls -1t "$ROOT/releases"); do
        case "$old" in *.part) continue ;; esac
        [ "$old" = "$cur" ] || [ "$old" = "$prev" ] && continue
        n=$((n + 1)); [ "$n" -le 2 ] && continue
        run rm -rf "${ROOT:?}/releases/$old"
    done
}

# ---------- БД: дамп/імпорт ----------
# db_env: з shared/.env.local → тимчасовий defaults-файл DB_CNF (600: user/password/host/port) + DB_NAME. Саме файл, а не
# MYSQL_PWD: на 161 у root є ~/.my.cnf з паролем, і option file має пріоритет над MYSQL_PWD → «Access denied» (07.10 і
# 11.10); --defaults-extra-file читається після ~/.my.cnf і виграє. Значення не друкуються. Потрібен php (CLI сервера).
db_env() {
    local env="$ROOT/shared/.env.local" php="${SITE_PHP:-php}"
    [ -f "$env" ] || die "нема $env"
    if [ "$RUNTIME" = docker ]; then return 0; fi
    if [ -n "${DB_CNF:-}" ] && [ -f "$DB_CNF" ]; then return 0; fi
    # shellcheck disable=SC2016
    eval "$("$php" -r '
        $u = null; foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $l) { if (str_starts_with($l, "DATABASE_URL=")) { $u = parse_url(trim(substr($l, 13), "\"\x27")); } }
        if (!$u) { fwrite(STDERR, "DATABASE_URL не знайдено\n"); exit(1); }
        printf("DB_HOST=%s\nDB_PORT=%s\nDB_USER=%s\nDB_PASS=%s\nDB_NAME=%s\n", escapeshellarg($u["host"] ?? "127.0.0.1"), escapeshellarg((string)($u["port"] ?? 3306)), escapeshellarg(urldecode($u["user"] ?? "")), escapeshellarg(urldecode($u["pass"] ?? "")), escapeshellarg(ltrim($u["path"] ?? "", "/")));
    ' "$env")"
    DB_CNF="$(mktemp)"
    # shellcheck disable=SC2064  # шлях фіксується зараз
    trap "rm -f '$DB_CNF'" EXIT
    (umask 077; printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT" > "$DB_CNF")
    chmod 600 "$DB_CNF"
}
# dump_flags: --set-gtid-purged є лише в mysqldump від MySQL (MariaDB-клієнт його не знає)
dump_flags() {
    local f="--single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4"
    if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then f="$f --set-gtid-purged=OFF"; fi
    echo "$f"
}
db_dump() { # → stdout (gzip)
    if [ "$RUNTIME" = docker ]; then
        mysql_root mysqldump --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "$KEY" </dev/null | gzip -6
    else
        db_env
        # shellcheck disable=SC2046  # прапорці без пробілів у значеннях
        mysqldump --defaults-extra-file="$DB_CNF" $(dump_flags) "$DB_NAME" | gzip -6
    fi
}
db_import() { # < дамп (plain sql)
    if [ "$RUNTIME" = docker ]; then
        mysql_root mysql --default-character-set=utf8mb4 "$KEY"
    else
        db_env
        mysql --defaults-extra-file="$DB_CNF" --default-character-set=utf8mb4 "$DB_NAME"
    fi
}
db_drop_tables() {
    local list
    if [ "$RUNTIME" = docker ]; then
        list="$(mysql_root mysql -N -e "'SHOW TABLES'" "$KEY" </dev/null)"
        [ -n "$list" ] && printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\nSET FOREIGN_KEY_CHECKS=1;\n' "$(echo "$list" | sed 's/.*/DROP TABLE IF EXISTS `&`;/')" | db_import
    else
        db_env
        list="$(mysql --defaults-extra-file="$DB_CNF" -N -e 'SHOW TABLES' "$DB_NAME")"
        [ -n "$list" ] && printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\nSET FOREIGN_KEY_CHECKS=1;\n' "$(echo "$list" | sed 's/.*/DROP TABLE IF EXISTS `&`;/')" | db_import
    fi
    return 0
}

# ---------- backup [мітка] → друкує шлях архіву ----------
do_backup() {
    local label="${1-}" ts tag dir name
    ts="$(date +%Y%m%d-%H%M%S)"; tag="$(cur_tag)"
    name="$KEY-$ts${label:+-$label}.tar"
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] backup → $ROOT/backups/$name"; echo "$ROOT/backups/$name"; return 0; fi
    dir="$ROOT/backups/.tmp-$ts"
    umask 077
    mkdir -p "$dir"
    db_dump > "$dir/db.sql.gz"
    gzip -t "$dir/db.sql.gz"
    zcat "$dir/db.sql.gz" | tail -n1 | grep -q '^-- Dump completed' || die "дамп БД неповний"
    tar -C "$ROOT/shared/media" -czf "$dir/media.tar.gz" . 2>/dev/null || tar -czf "$dir/media.tar.gz" --files-from /dev/null
    gzip -t "$dir/media.tar.gz"
    {
        echo "key=$KEY"; echo "tag=$tag"; echo "created=$(date -Is)"; echo "host=$(hostname)"; echo "label=$label"
        [ -f "$ROOT/current/SITE_RELEASE" ] && sed 's/^/release./' "$ROOT/current/SITE_RELEASE"
    } > "$dir/manifest.txt"
    (cd "$dir" && sha256sum db.sql.gz media.tar.gz manifest.txt > SHA256SUMS)
    tar -C "$dir" -cf "$ROOT/backups/.$name.part" manifest.txt SHA256SUMS db.sql.gz media.tar.gz
    mv "$ROOT/backups/.$name.part" "$ROOT/backups/$name"
    rm -rf "$dir"
    local keep="${SITE_BACKUP_KEEP_DAYS:-14}" f
    for f in $(ls -1t "$ROOT/backups/"*.tar 2>/dev/null | tail -n +4); do
        [ -n "$(find "$f" -mtime +"$keep")" ] && rm -f "$f"
    done
    find "$ROOT/backups" -maxdepth 1 -name '.*.part' -mtime +1 -delete
    find "$ROOT/backups" -maxdepth 1 -name '.tmp-*' -mtime +1 -exec rm -rf {} +
    log "бекап: $ROOT/backups/$name ($(du -h "$ROOT/backups/$name" | cut -f1))"
    echo "$ROOT/backups/$name"
}

# ---------- restore <файл на сервері> ----------
do_restore() {
    local file="$1" tmp db media=""
    [ -f "$file" ] || die "нема файлу $file"
    if [ -f "$ROOT/ARCHIVED" ] && [ "${UNARCHIVE:-0}" != 1 ]; then die "сайт заархівовано; відновлення поверх — лише з UNARCHIVE=1"; fi
    [ -n "$(cur_tag)" ] || die "спершу bin/site deploy $KEY, потім restore"
    tmp="$ROOT/backups/.restore-$$"
    mkdir -p "$tmp"
    # shellcheck disable=SC2064
    trap "rm -rf '$tmp'" EXIT
    case "$file" in
        *.tar)
            tar -C "$tmp" -xf "$file"
            (cd "$tmp" && sha256sum -c --quiet SHA256SUMS) || die "контрольні суми архіву не збігаються"
            local akey; akey="$(sed -n 's/^key=//p' "$tmp/manifest.txt")"
            [ "$akey" = "$KEY" ] || warn "архів сайту '$akey' відновлюється в '$KEY'"
            db="$tmp/db.sql.gz"; [ -f "$tmp/media.tar.gz" ] && media="$tmp/media.tar.gz" ;;
        *.sql.gz) db="$file" ;;
        *) die "очікую .tar (bin/site backup) або .sql.gz" ;;
    esac
    gzip -t "$db"
    if [ "$(zcat "$db" | grep -cE '^(CREATE DATABASE|USE )' || true)" != 0 ]; then
        die "дамп містить CREATE DATABASE/USE — потрібен дамп однієї БД без --databases"
    fi
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] restore $file → $P (БД${media:+ + media})"; return 0; }
    [ "$RUNTIME" = docker ] && { compose up -d --no-deps mysql; wait_mysql; }
    if [ "${SITE_SKIP_BACKUP:-0}" != 1 ]; then log "страховий бекап перед відновленням"; do_backup pre-restore >/dev/null; fi
    log "БД ← $(basename "$db")"
    db_drop_tables
    zcat "$db" | db_import
    if [ -n "$media" ]; then
        log "media ← архів"
        find "$ROOT/shared/media" -mindepth 1 -delete
        tar -C "$ROOT/shared/media" -xzf "$media"; own "$ROOT/shared/media"
    fi
    console "$ROOT/current" doctrine:migrations:migrate -n --allow-no-migration
    console "$ROOT/current" cache:clear >/dev/null
    fpm_reload
    smoke "$ROOT/current" || die "після відновлення smoke не пройшов"
    http_check || true
    log "відновлено з $(basename "$file")"
}

do_archive() {
    local file="$1"
    [ "$DRY_RUN" = 1 ] || [ -f "$file" ] || die "нема бекапу $file"
    if [ "$DRY_RUN" != 1 ]; then
        local t ok=0; t="$(mktemp -d)"
        if tar -C "$t" -xf "$file" && (cd "$t" && sha256sum -c --quiet SHA256SUMS); then ok=1; fi
        rm -rf "$t"
        [ "$ok" = 1 ] || die "архів $file пошкоджений"
    fi
    [ "$RUNTIME" = docker ] && run compose stop
    cron_off
    if [ "$DRY_RUN" != 1 ]; then
        printf 'archived=%s\nbackup=%s\ntag=%s\n# Підняти: UNARCHIVE=1 bin/site deploy %s (рішення власника)\n' "$(date -Is)" "$(basename "$file")" "$(cur_tag)" "$KEY" > "$ROOT/ARCHIVED"
    fi
    log "$P заархівовано (fastpanel: сайт у панелі вимкнути руками), маркер $ROOT/ARCHIVED"
}

# ---------- doctor: рядки key=value ----------
do_doctor() {
    echo "archived=$([ -f "$ROOT/ARCHIVED" ] && echo yes || echo no)"
    echo "tag=$(cur_tag)"
    [ -f "$ROOT/current/SITE_RELEASE" ] && sed 's/^/release./' "$ROOT/current/SITE_RELEASE"
    if [ "$RUNTIME" = fastpanel ]; then
        [ -n "${SITE_DOCROOT:-}" ] && echo "docroot=$(readlink -f "$SITE_DOCROOT" 2>/dev/null || echo '?')" && echo "docroot.want=$(readlink -f "$ROOT/current/public" 2>/dev/null || true)"
        echo "php=$("${SITE_PHP:-php}" -r 'echo PHP_VERSION;' 2>/dev/null || echo '?')"
        echo "php.tz=$("${SITE_PHP:-php}" -r 'echo ini_get("date.timezone");' 2>/dev/null || true)"
    else
        if [ -f "$ROOT/release.env" ]; then compose ps -a --format '{{.Service}} {{.State}} {{.Health}}' 2>/dev/null | sed 's/^/svc=/' || true; fi
    fi
    if [ -n "$(cur_tag)" ] && [ -d "$ROOT/current" ]; then
        if console "$ROOT/current" doctrine:migrations:up-to-date -n >/dev/null 2>&1; then echo "migrations=up-to-date"; else echo "migrations=pending-or-error"; fi
        # збіги слагів у корені сайту (послуга/категорія/сторінка на одній адресі); рушій без app:site:slugs — пропуск
        local slugs
        # grep без -q: дочитує вивід, інакше console отримає SIGPIPE і pipefail зробить збіг провалом
        if console "$ROOT/current" list --raw 2>/dev/null | grep '^app:site:slugs ' >/dev/null; then
            if slugs="$(console "$ROOT/current" app:site:slugs -n 2>&1)"; then echo "slugs=ok"
            else echo "slugs=conflicts"; printf '%s\n' "$slugs" | grep '^- ' | head -n 10 | sed 's/^- /slugs.line=/'; fi
        fi
    fi
    local last="" f
    for f in "$ROOT/backups/"*.tar; do
        [ -e "$f" ] || continue
        case "${f##*/}" in *-pre-*.tar | incoming-*) continue ;; esac
        if [ -z "$last" ] || [ "$f" -nt "$last" ]; then last="$f"; fi
    done
    if [ -n "$last" ]; then
        echo "backup.last=$(basename "$last")"
        echo "backup.age_h=$(( ($(date +%s) - $(stat -c %Y "$last")) / 3600 ))"
        echo "backup.size=$(du -h --apparent-size "$last" | cut -f1)"
        echo "backup.bytes=$(stat -c %s "$last")"
    fi
    echo "cron=$(crontab -l 2>/dev/null | grep -qF "$CRON_MARK" && echo yes || echo no)"
    echo "disk.root=$(df -P "$ROOT" | awk 'NR==2 {print $5" used, "int($4/1024)"M free"}')"
    echo "secret_key=$(settings_key_state)"
}

if [ "${SITE_LIB_ONLY:-0}" = 1 ]; then return 0; fi

case "$ACTION" in
    deploy) do_deploy "${1:?tag}" ;;
    rollback) do_rollback ;;
    cutover) do_cutover ;;
    uncutover) do_uncutover ;;
    backup) do_backup "${1-}" ;;
    restore) do_restore "${1:?файл}" ;;
    archive) do_archive "${1:?файл бекапу}" ;;
    doctor) do_doctor ;;
    prune) do_prune ;;
    *) die "невідома дія $ACTION" ;;
esac
