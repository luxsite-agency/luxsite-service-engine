#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: відновити БД і media з архіву: restore <key> <архів> [--yes]
# bin/site restore <key> <архів> [--yes]
#   <архів> — локальний файл (завантажується на сервер у <path>/backups/) або шлях на сервері (server:/шлях).
#   .tar з bin/site backup → БД + media; .sql.gz (дамп однієї БД без CREATE DATABASE/USE, напр. дамп прода) → лише БД.
#   Реліз уже має бути розгорнутий (bin/site deploy <key>). Перед відновленням — страховий бекап (pre-restore),
#   таблиці БД перестворюються з дампа, media замінюється, далі міграції до версії рушія, cache:clear і smoke.
#   Без --yes (або SITE_YES=1) питає підтвердження — потрібно ввести ключ сайту.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

key="" src="" yes="${SITE_YES:-0}"
for a in "$@"; do
    case "$a" in
        --yes | -y) yes=1 ;;
        -h | --help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $a" ;;
        *) if [ -z "$key" ]; then key="$a"; elif [ -z "$src" ]; then src="$a"; else die "зайвий аргумент $a"; fi ;;
    esac
done
[ -n "$src" ] || die "вкажіть архів: restore <key> <архів>"
site_ctx "$key"
guard_write

if [ "$yes" != 1 ]; then
    [ -t 0 ] || die "без термінала потрібне --yes"
    printf 'Відновлення ПЕРЕЗАПИШЕ БД і media сайту %s на %s:%s.\nВведіть ключ сайту для підтвердження: ' "$key" "$S_HOST" "$S_PATH" >&2
    read -r answer
    [ "$answer" = "$key" ] || die "скасовано"
fi

case "$src" in
    server:*) file="${src#server:}" ;;
    *)
        [ -f "$src" ] || die "нема файлу $src (шлях на сервері — server:/шлях)"
        file="$S_PATH/backups/incoming-$(basename "$src")"
        log "завантаження $(basename "$src") → $(ssh_dest):$file"
        put "$src" "$file"
        ;;
esac
remote restore "$file"
