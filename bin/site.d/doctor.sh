#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: стан сайту (реліз, docroot, міграції, бекап, TLS, диск): doctor <key>|--all
# bin/site doctor <key>     bin/site doctor --all
#   Лише читає. Перевіряє: доступ, маркер ARCHIVED проти status, версію рушія (реліз на сервері проти engine у реєстрі),
#   docroot панелі (fastpanel) / контейнери (docker), міграції (up-to-date), останній звичайний бекап (без pre-*;
#   старший за 36 год або менший за 50K — WARN), cron, диск, php і date.timezone (fastpanel), TLS сайту й адмінки.
#   Код виходу: 0 — усе OK/WARN, 1 — є FAIL. --all — усі записи реєстру (handed-over і стара схема — пропуск).
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

FAILS=0 WARNS=0
ok() { printf '  [OK]   %s\n' "$*"; }
wrn() { printf '  [WARN] %s\n' "$*"; WARNS=$((WARNS + 1)); }
bad() { printf '  [FAIL] %s\n' "$*"; FAILS=$((FAILS + 1)); }

cert_check() {
    local host="$1" end days
    command -v openssl >/dev/null || { wrn "TLS: нема openssl на цій машині"; return; }
    end="$(echo | timeout 15 openssl s_client -servername "$host" -connect "$host:443" -verify_return_error 2>/dev/null \
        | openssl x509 -noout -enddate 2>/dev/null | sed 's/^notAfter=//')" || true
    if [ -z "$end" ]; then bad "TLS $host: сертифікат не отримано або не валідний"; return; fi
    days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
    if [ "$days" -lt 0 ]; then bad "TLS $host: прострочений ($end)"
    elif [ "$days" -lt 14 ]; then wrn "TLS $host: лишилось $days дн."
    else ok "TLS $host: ще $days дн."; fi
}

doctor_one() {
    site_ctx "$1"
    echo "== $S_KEY ($S_STATUS, $S_RUNTIME) $(ssh_dest):$S_PATH"
    if ! is_site_layout; then echo "  [SKIP] стара схема (не …/site-$S_KEY) — bin/site не обслуговує"; return 0; fi
    if [ "$S_STATUS" = handed-over ]; then echo "  [SKIP] handed-over — доступу нема"; return 0; fi
    local out
    if ! out="$(remote doctor 2>&1)"; then bad "сервер недоступний або помилка: $(echo "$out" | tail -n1)"; return 0; fi
    [ "$DRY_RUN" = 1 ] && { echo "  [dry-run]"; return 0; }
    val() { echo "$out" | sed -n "s/^$1=//p" | head -n1; }

    local archived; archived="$(val archived)"
    if [ "$S_STATUS" = archived ]; then
        if [ "$archived" = yes ]; then ok "архів: маркер ARCHIVED є"; else bad "status archived, але маркера ARCHIVED на сервері нема"; fi
    elif [ "$archived" = yes ]; then bad "на сервері маркер ARCHIVED, а status $S_STATUS"; fi

    local tag sha want
    tag="$(val tag)"; sha="$(val release.engine_sha)"
    if [ -z "$tag" ]; then bad "не розгорнуто (нема release.env)"; return 0; fi
    # гілка з реєстру (main) — з origin, як у deploy; локальна копія гілки може відставати
    want="$(git -C "$SITE_ENGINE_DIR" rev-parse --verify -q --short=12 "origin/${S_ENGINE:-HEAD}^{commit}" 2>/dev/null         || git -C "$SITE_ENGINE_DIR" rev-parse --verify -q --short=12 "${S_ENGINE:-HEAD}^{commit}" 2>/dev/null || true)"
    if [ -n "$want" ] && [ "$want" != "${sha:-${tag%%-*}}" ]; then
        wrn "рушій: на сервері ${sha:-${tag%%-*}}, у реєстрі ${S_ENGINE} ($want) — потрібен deploy"
    else ok "рушій: ${sha:-${tag%%-*}} (тег $tag)"; fi

    if [ "$S_RUNTIME" = fastpanel ]; then
        local dr dw; dr="$(val docroot)"; dw="$(val docroot.want)"
        if [ -n "$S_DOCROOT" ]; then
            if [ -n "$dw" ] && [ "$dr" = "$dw" ]; then ok "docroot → current/public"; else wrn "docroot $S_DOCROOT → ${dr:-?} (не current/public): сайт ще на старій схемі, потрібен bin/site cutover"; fi
        fi
        local tz; tz="$(val php.tz)"
        [ -n "$(val php)" ] && ok "php $(val php)"
        if [ "$tz" != "Europe/Kyiv" ]; then wrn "php date.timezone='${tz:-}' (очікується Europe/Kyiv: дати Schema.org/заявок)"; fi
    else
        local line svc state health
        while read -r line; do
            [ -n "$line" ] || continue
            read -r svc state health <<<"$line"
            if [ "$archived" = yes ]; then [ "$state" = running ] && wrn "$svc працює, хоча сайт в архіві"; continue; fi
            if [ "$state" != running ]; then bad "$svc: $state"
            elif [ -n "${health:-}" ] && [ "$health" != healthy ]; then bad "$svc: running, health=$health"
            else ok "$svc: running${health:+ ($health)}"; fi
        done <<<"$(echo "$out" | sed -n 's/^svc=//p')"
    fi
    [ "$archived" = yes ] && return 0

    case "$(val migrations)" in
        up-to-date) ok "міграції: up-to-date" ;;
        "") wrn "міграції: стан невідомий" ;;
        *) bad "міграції: є невиконані або помилка (bin/console doctrine:migrations:status)" ;;
    esac

    local age bytes; age="$(val backup.age_h)"; bytes="$(val backup.bytes)"
    if [ -z "$age" ]; then bad "звичайних бекапів на сервері нема (страхові pre-* не рахуються)"
    else
        local bok=1
        if [ "$age" -gt 36 ]; then wrn "останній бекап $(val backup.last): $age год тому (> 36 год)"; bok=0; fi
        if [ -n "$bytes" ] && [ "$bytes" -lt 51200 ]; then wrn "останній бекап $(val backup.last): $(val backup.size) (< 50K — порожній?)"; bok=0; fi
        if [ "$bok" = 1 ]; then ok "бекап: $(val backup.last), $age год тому, $(val backup.size)"; fi
    fi
    if [ "$(val cron)" = yes ]; then ok "cron бекапу є"; else wrn "cron бекапу на сервері нема"; fi
    [ -n "$S_BACKUP_TARGET" ] || wrn "backup_target не задано — копій поза сервером нема"

    local disk use; disk="$(val disk.root)"; use="${disk%%%*}"
    if [ -z "$use" ]; then wrn "диск: невідомо"
    elif [ "$use" -ge 95 ]; then bad "диск: $disk"
    elif [ "$use" -ge 85 ]; then wrn "диск: $disk"
    else ok "диск: $disk"; fi

    if [ "${SITE_NO_TLS:-0}" != 1 ]; then
        [ -n "$S_SITE" ] && cert_check "$S_SITE"
        [ -n "$S_ADMIN" ] && [ "$S_ADMIN" != "$S_SITE" ] && cert_check "$S_ADMIN"
    fi
    return 0
}

notify_flag=0; args=()
for a in "$@"; do case "$a" in --notify) notify_flag=1 ;; *) args+=("$a") ;; esac; done
set -- "${args[@]+"${args[@]}"}"
# звіт збираємо у змінну (підоболонка), щоб --notify міг надіслати його; лічильник FAIL повертаємо службовим рядком
report="$( {
case "${1-}" in
    -h | --help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    --all) for k in $(reg_keys ''); do doctor_one "$k"; done ;;
    *) doctor_one "${1-}" ;;
esac
echo "== підсумок: FAIL $FAILS, WARN $WARNS"
echo "__FAILS=$FAILS"
} )"
FAILS="$(printf '%s\n' "$report" | sed -n 's/^__FAILS=//p' | tail -n1)"
report="$(printf '%s\n' "$report" | grep -v '^__FAILS=')"
printf '%s\n' "$report"
# --notify: лише коли є FAIL або сайт відстав від реєстру / ще на старій схемі (WARN «потрібен deploy» / cutover)
if [ "$notify_flag" = 1 ] && { [ "${FAILS:-0}" != 0 ] || printf '%s' "$report" | grep -qE 'потрібен deploy|потрібен bin/site cutover'; }; then
    notify "bin/site doctor @ $(hostname 2>/dev/null | cut -d. -f1) $(date '+%F %R')"$'\n'"$(printf '%s' "$report" | grep -E '^==|FAIL|WARN')"
fi
[ "${FAILS:-0}" = 0 ]
