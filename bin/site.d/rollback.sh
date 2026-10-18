#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: повернути попередній реліз (current → SITE_PREV_TAG): rollback <key>
# bin/site rollback <key>
#   Перемикає current на попередній реліз із release.env, скидає php-fpm (fastpanel) або піднімає контейнери з
#   попереднього релізу (docker), перевіряє сайт. Міграції БД НЕ відкочуються: якщо нова версія змінила схему
#   несумісно — спершу bin/site restore <key> server:<path>/backups/<…-pre-deploy.tar>.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"
case "${1-}" in -h | --help) sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;; esac
site_ctx "${1-}"
guard_write
remote rollback
