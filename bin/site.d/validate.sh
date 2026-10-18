#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: перевірити реєстр і паки: validate [--all | <key> | <тека-паку>]
#
#   bin/site validate              реєстр sites.yaml (схема, без секретів)
#   bin/site validate <key>        реєстр + пак сайту (SITE_PACK=<тека> або поле pack реєстру)
#   bin/site validate <тека>       пак у теці (site-template, новий пак до запису в реєстр)
#   bin/site validate --all        реєстр + усі паки з локальною копією (CI рушія: оновлення рушія не ламає паки)
#
# Пак: обов'язкові файли, site.yaml, параметри — лише відомі рушію (config/services.yaml), тема — ключі
# App\ServiceSite\SiteTheme::DEFAULTS і файли в themes/<key>/, переклади — валідний YAML, жодних секретів і файлів
# .env/ключів/дампів, ліміти розміру. Детально: docs/site-pack.md.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

VALIDATOR="$SITE_CMD_DIR/_validate.py"
need_yaml

pack_of() {
    local dir
    if [ -n "${SITE_PACK:-}" ]; then printf '%s' "$SITE_PACK"; return; fi
    dir="$(reg_get "$1" pack)" || exit 1
    case "$dir" in "" | /*) ;; *) dir="$SITE_ENGINE_DIR/$dir" ;; esac
    [ -n "$dir" ] || die "у реєстрі для '$1' нема локального pack — клонуйте $(reg_get "$1" repo) і вкажіть теку: bin/site validate <тека>"
    printf '%s' "$dir"
}

target="${1:-}"
case "$target" in
    -h | --help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    "") exec "${SITE_PYTHON:-python3}" "$VALIDATOR" registry "$SITES_FILE" ;;
    --all)
        rc=0
        "${SITE_PYTHON:-python3}" "$VALIDATOR" registry "$SITES_FILE" || rc=1
        for key in $(reg_keys ""); do
            dir="$(reg_get "$key" pack)"
            case "$dir" in "" | /*) ;; *) dir="$SITE_ENGINE_DIR/$dir" ;; esac
            if [ -z "$dir" ] || [ ! -d "$dir" ]; then
                warn "$key: локальної копії паку нема (${dir:-pack не задано}) — пропущено"
                continue
            fi
            "${SITE_PYTHON:-python3}" "$VALIDATOR" pack "$SITE_ENGINE_DIR" "$dir" "$SITES_FILE" "$key" || rc=1
        done
        exit "$rc"
        ;;
    *)
        if [ -d "$target" ]; then
            exec "${SITE_PYTHON:-python3}" "$VALIDATOR" pack "$SITE_ENGINE_DIR" "$target" "$SITES_FILE"
        fi
        need_key "$target"
        dir="$(pack_of "$target")"
        exec "${SITE_PYTHON:-python3}" "$VALIDATOR" pack "$SITE_ENGINE_DIR" "$dir" "$SITES_FILE" "$target"
        ;;
esac
