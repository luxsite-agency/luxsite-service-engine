#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: бекап БД і media з ротацією і копією в сховище: backup <key>|--all [--copy-only]
# bin/site backup <key> [--copy-only]     bin/site backup --all [--copy-only]
#   На сервері: <path>/backups/<key>-<час>.tar (db.sql.gz без CREATE DATABASE/USE, media.tar.gz, manifest, SHA256SUMS),
#   ротація SITE_BACKUP_KEEP_DAYS (14, щонайменше 3 останні). Потім копія в backup_target з реєстру:
#   «/шлях» або «<ця машина>:/шлях» — стягується сюди; «host:/шлях» інший — через тимчасову теку rsync-ом туди.
#   На цілі лишаються SITE_COPY_KEEP_DAYS (60). --copy-only — лише скопіювати останній наявний бекап.
#   --all — усі active сайти реєстру за новою схемою (archived/handed-over і стара схема пропускаються).
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

copy_only=0 all=0 key=""
for a in "$@"; do
    case "$a" in
        --copy-only) copy_only=1 ;;
        --all) all=1 ;;
        -h | --help) sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $a" ;;
        *) key="$a" ;;
    esac
done

copy_to_target() {
    local name="$1" thost tpath dest
    if [ -z "$S_BACKUP_TARGET" ]; then warn "$S_KEY: backup_target у реєстрі не задано — копії поза сервером нема"; return 0; fi
    case "$S_BACKUP_TARGET" in
        *:*) thost="${S_BACKUP_TARGET%%:*}"; tpath="${S_BACKUP_TARGET#*:}" ;;
        *) thost=""; tpath="$S_BACKUP_TARGET" ;;
    esac
    case "$thost" in "" | local | localhost | "$(hostname 2>/dev/null | cut -d. -f1)") thost="" ;; esac
    if [ -z "$thost" ]; then
        dest="$tpath"
        run mkdir -p "$dest"
        get "$S_PATH/backups/$name" "$dest"
        if [ "$DRY_RUN" != 1 ]; then
            # shellcheck disable=SC2012
            ls -1t "$dest/$S_KEY"-*.tar 2>/dev/null | tail -n +4 | while read -r f; do
                [ -n "$(find "$f" -mtime +"${SITE_COPY_KEEP_DAYS:-60}")" ] && rm -f "$f"
            done
        fi
        log "$S_KEY: копія → $dest/$name"
    else
        local tmp; tmp="$(mktemp -d "${TMPDIR:-/tmp}/site-backup.XXXXXX")"
        get "$S_PATH/backups/$name" "$tmp"
        run rsync -a --partial --mkpath -e "ssh -o BatchMode=yes" "$tmp/$name" "$thost:$tpath/"
        rm -rf "$tmp"
        log "$S_KEY: копія → $thost:$tpath/$name"
    fi
}

backup_one() {
    site_ctx "$1"
    guard_write
    local name
    if [ "$copy_only" = 1 ]; then
        name="$(remote doctor | sed -n 's/^backup.last=//p')"
        [ -n "$name" ] || { [ "$DRY_RUN" = 1 ] && return 0; die "$S_KEY: на сервері ще нема бекапів"; }
    else
        name="$(basename "$(remote backup | tail -n1)")"
        [ "$DRY_RUN" = 1 ] && [ -z "$name" ] && name="$S_KEY-dry-run.tar"
        [ -n "$name" ] || die "$S_KEY: бекап не повернув імені архіву"
    fi
    copy_to_target "$name"
    echo "$name"
}

if [ "$all" = 1 ]; then
    rc=0
    for k in $(reg_keys active); do
        site_ctx "$k"
        is_site_layout || { log "$k: стара схема — пропущено"; continue; }
        ( backup_one "$k" ) || { warn "$k: бекап не вдався"; rc=1; }
    done
    exit "$rc"
fi
backup_one "$key"
