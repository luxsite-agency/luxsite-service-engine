#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: перемкнути docroot FastPanel на current/public (або --undo): cutover <key> [--undo]
# bin/site cutover <key> [--undo]
#   Лише fastpanel. server.docroot у реєстрі — симлінк панелі (напр. /var/www/<user>/data/www/<домен>), що зараз
#   веде на стару робочу копію. cutover переводить його на <path>/current/public, запам'ятовує стару ціль у
#   <path>/docroot.prev, скидає php-fpm і перевіряє сайт через nginx (відкат автоматично при провалі).
#   --undo повертає стару ціль. Це перехід прод-сайту на рушій — лише з рішення власника.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"
key="" undo=0
for a in "$@"; do
    case "$a" in
        --undo) undo=1 ;;
        -h | --help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $a" ;;
        *) key="$a" ;;
    esac
done
site_ctx "$key"
guard_write
[ "$S_RUNTIME" = fastpanel ] || die "$key: cutover лише для runtime fastpanel"
[ -n "$S_DOCROOT" ] || die "$key: у реєстрі нема server.docroot"
if [ "$undo" = 1 ]; then remote uncutover; else remote cutover; fi
