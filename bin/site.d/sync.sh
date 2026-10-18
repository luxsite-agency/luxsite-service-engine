#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: довести сайти до engine з реєстру (pull-модель для cron, з відкатом і сповіщенням): sync [--all | <key>…] [--notify]
# bin/site sync --all [--notify]        bin/site sync alpha beta
#   Для кожного active сайту з auto_update: true (або названого явно) порівнює engine з реєстру (гілка — origin/<ref>,
#   тег/sha — як є) з релізом на сервері (release.engine_sha); якщо відстає — bin/site deploy <key> (бекап, міграції,
#   smoke, перемикання, відкат при провалі). Сайти без auto_update і зі старою схемою пропускаються (--all) або
#   деплояться лише коли названі явно. Підсумок — у stdout; --notify шле його в канал (див. notify у _lib.sh), якщо
#   хоч щось оновлено або впало. Код виходу 1, якщо хоч один деплой не вдався.
#   Cron на операторській машині: git pull рушія → bin/site sync --all --notify (docs/site-ops.md, «Автооновлення»).
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

notify=0 all=0 keys=()
for a in "$@"; do
    case "$a" in
        --notify) notify=1 ;;
        --all) all=1 ;;
        -h | --help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $a" ;;
        *) keys+=("$a") ;;
    esac
done
if [ "$all" = 1 ]; then
    mapfile -t keys < <(reg_keys active)
fi
[ "${#keys[@]}" -gt 0 ] || die "вкажіть ключі сайтів або --all"

cd "$SITE_ENGINE_DIR"
git fetch -q origin --tags 2>/dev/null || warn "git fetch origin не вдався — порівнюю з локальними refs"

want_sha() { # want_sha <ref> → sha12 (гілка з origin, тег/sha як є)
    local ref="$1"
    git rev-parse --verify -q --short=12 "origin/$ref^{commit}" 2>/dev/null \
        || git rev-parse --verify -q --short=12 "$ref^{commit}" 2>/dev/null || true
}

updated=() failed=() skipped=() same=()
for key in "${keys[@]}"; do
    site_ctx "$key"
    if ! is_site_layout; then skipped+=("$key: стара схема"); continue; fi
    if [ "$all" = 1 ] && [ "$S_AUTO_UPDATE" != yes ]; then skipped+=("$key: auto_update вимкнено"); continue; fi
    case "$S_STATUS" in archived | handed-over) skipped+=("$key: $S_STATUS"); continue ;; esac
    want="$(want_sha "${S_ENGINE:-main}")"
    if [ -z "$want" ]; then failed+=("$key: у рушії нема '$S_ENGINE'"); continue; fi
    doc="$(remote doctor 2>/dev/null || true)"
    have="$(printf '%s
' "$doc" | sed -n 's/^release.engine_sha=//p' | head -n1)"
    have_pack="$(printf '%s
' "$doc" | sed -n 's/^release.pack_hash=//p' | head -n1)"
    # хеш паку — та сама формула, що в deploy.sh (config/parameters.yaml, themes/<key>, translations, templates): змінився пак → деплой
    want_pack=""
    pack_dir="$S_PACK"; case "$pack_dir" in "" | /*) ;; *) pack_dir="$SITE_ENGINE_DIR/$pack_dir" ;; esac
    if [ -n "$pack_dir" ] && [ -d "$pack_dir" ]; then
        want_pack="$(cd "$pack_dir" && { find config/parameters.yaml "themes/$key" translations templates -type f 2>/dev/null || true; } | LC_ALL=C sort | xargs -r sha256sum | sha256sum | cut -c1-8)"
    fi
    if [ "$DRY_RUN" = 1 ]; then have=""; fi
    if [ "$have" = "$want" ] && { [ -z "$want_pack" ] || [ "$have_pack" = "$want_pack" ]; }; then same+=("$key: $want-$have_pack"); continue; fi
    log "$key: на сервері ${have:-нічого}-${have_pack:-}, у реєстрі $S_ENGINE ($want) + пак ${want_pack:-?} → deploy"
    if bash "$SITE_CMD_DIR/deploy.sh" "$key" 2>&1 | tail -n 15 >&2; then
        updated+=("$key: ${have:-—} → $want")
    else
        failed+=("$key: deploy не вдався (${have:-—} → $want), див. лог; відкат — автоматичний")
    fi
done

summary="bin/site sync @ $(hostname 2>/dev/null | cut -d. -f1) $(date '+%F %R')"
[ "${#updated[@]}" -gt 0 ] && summary+=$'\n'"оновлено: ${updated[*]}"
[ "${#failed[@]}" -gt 0 ] && summary+=$'\n'"ПОМИЛКА: ${failed[*]}"
[ "${#same[@]}" -gt 0 ] && summary+=$'\n'"актуальні: ${same[*]}"
[ "${#skipped[@]}" -gt 0 ] && summary+=$'\n'"пропущено: ${skipped[*]}"
echo "$summary"
if [ "$notify" = 1 ] && { [ "${#updated[@]}" -gt 0 ] || [ "${#failed[@]}" -gt 0 ]; }; then
    notify "$summary"
fi
[ "${#failed[@]}" = 0 ]
