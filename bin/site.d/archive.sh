#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: заархівувати сайт (бекап → копія → зупинка/маркер ARCHIVED): archive <key>
# bin/site archive <key>
#   Бекап з міткою archive → копія в backup_target (без неї відмова; свідомо обійти — SITE_NO_COPY=1) → перевірка
#   архіву → docker: зупинка стека; fastpanel: сайт у панелі вимикає власник → cron вимкнено → маркер ARCHIVED.
#   Після цього змініть status: archived у sites.yaml (PR у рушій).
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"
case "${1-}" in -h | --help) sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;; esac
site_ctx "${1-}"
guard_write
if [ -z "$S_BACKUP_TARGET" ] && [ "${SITE_NO_COPY:-0}" != 1 ]; then
    die "$S_KEY: backup_target не задано — архів без копії поза сервером; свідомо: SITE_NO_COPY=1"
fi
name="$(remote backup archive | tail -n1)"
[ "$DRY_RUN" = 1 ] && [ -z "$name" ] && name="$S_PATH/backups/$S_KEY-dry-run-archive.tar"
if [ "${SITE_NO_COPY:-0}" != 1 ]; then
    bash "$SITE_CMD_DIR/backup.sh" "$S_KEY" --copy-only >/dev/null || die "копія архіву не вдалася — архівацію зупинено"
fi
remote archive "$name"
log "далі: status: archived для $S_KEY у $SITES_FILE"
