#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: своя тема сайту в паку (заготовки шаблонів, theme.css, diff з рушієм): theme new|diff|list <key|тека-паку> [шаблон…] [--copy]
#
#   bin/site theme list                         які шаблони рушія можна перекрити в паку і які блоки має кожен
#   bin/site theme new <key|тека> [шаблон…]     створити тему в паку: themes/<key>/theme.css (якщо нема) і заготовки
#                                               templates/<шаблон> як {% extends '@engine/…' %} з усіма блоками = parent()
#                                               (типово services/base.html.twig і services/home.html.twig)
#   bin/site theme new <key> --copy <шаблон>…   повна копія шаблону рушія в пак (своя верстка з нуля; виправлення рушія
#                                               в цей файл більше не доходять — лише через theme diff)
#   bin/site theme diff <key|тека>              що змінилось у рушії в перекритих шаблонах з версії engine: у site.yaml
#                                               паку (файл, рядки, блоки) — звірка після оновлення рушія
#   --force                                     переписати наявні файли теми
#
# Модель узгоджена з shop-engine (bin/store theme, 11.10.2026): тема живе в паку, Twig бере її перед шаблонами рушія,
# рушій доступний як @engine; перекривати можна лише services/** і partials/** (*.twig). Далі: правити файли в паку,
# закомітити — стенд підхопить sync, прод — bin/site deploy (lint:twig + smoke перед перемиканням). docs/site-pack.md.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

ENGINE_TPL="$SITE_ENGINE_DIR/templates"
DEFAULT_TEMPLATES="services/base.html.twig services/home.html.twig"

usage() { sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; }

# пак за ключем реєстру або текою (як у validate.sh)
pack_dir_of() {
    local target="$1" dir
    if [ -d "$target" ]; then (cd "$target" && pwd); return; fi
    if [ -n "${SITE_PACK:-}" ]; then printf '%s' "$SITE_PACK"; return; fi
    need_key "$target"
    need_yaml
    dir="$(reg_get "$target" pack)" || exit 1
    case "$dir" in "" | /*) ;; *) dir="$SITE_ENGINE_DIR/$dir" ;; esac
    if [ -z "$dir" ] || [ ! -d "$dir" ]; then die "theme: у реєстрі для '$target' нема локального pack — вкажіть теку паку"; fi
    printf '%s' "$dir"
}
pack_key() { sed -n 's/^key:[[:space:]]*//p' "$1/site.yaml" | head -n1 | tr -d "'\" \r"; }
pack_engine() { sed -n 's/^engine:[[:space:]]*//p' "$1/site.yaml" | head -n1 | tr -d "'\" \r"; }

# шаблони рушія, які можна перекрити (лише публічні), відносно templates/
overridable() { (cd "$ENGINE_TPL" && find services partials -name '*.twig' -type f | sed 's#^\./##' | LC_ALL=C sort); }
is_overridable() { case "$1" in services/*.twig | partials/*.twig) [ -f "$ENGINE_TPL/$1" ] ;; *) return 1 ;; esac; }
# блоки, оголошені в самому файлі (їх і можна перевизначити через extends)
# pipefail: grep без збігів (partial без блоків) не має валити скрипт
blocks_of() { { grep -o '{%-\? *block [A-Za-z_][A-Za-z0-9_]* *-\?%}' "$1" 2>/dev/null || true; } | sed -E 's/.*block +([A-Za-z0-9_]+).*/\1/' | awk '!seen[$0]++'; }

cmd_list() {
    local t b
    echo "Шаблони рушія, які пак може перекрити (templates/<шлях> у паку; блоки — для {% extends '@engine/<шлях>' %}):"
    for t in $(overridable); do
        b="$(blocks_of "$ENGINE_TPL/$t" | tr '\n' ' ')"
        if [ -n "$b" ]; then printf '  %-44s блоки: %s\n' "$t" "$b"; else printf '  %-44s (partial, без блоків — лише --copy або include у своїх шаблонах)\n' "$t"; fi
    done
}

write_stub() { # write_stub <пак> <ключ> <шаблон>
    local pack="$1" key="$2" t="$3" dst="$1/templates/$3" b blocks
    blocks="$(blocks_of "$ENGINE_TPL/$t")"
    [ -n "$blocks" ] || { warn "$t — partial без блоків: заготовка-extends неможлива, використайте --copy"; return 0; }
    if [ -e "$dst" ] && [ "$FORCE" != 1 ]; then warn "$dst уже є — пропущено (--force, щоб переписати)"; return 0; fi
    run mkdir -p "$(dirname "$dst")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] заготовка $dst (блоки: $(echo "$blocks" | tr '\n' ' '))"; return 0; }
    {
        printf '{# Тема %s: успадковано з рушія (@engine/%s). Перевизначте потрібні блоки — решту лишіть як parent() або\n' "$key" "$t"
        printf '   видаліть. Змінні контролера — у шапці шаблону рушія. Після оновлення рушія: bin/site theme diff %s. #}\n' "$key"
        printf "{%% extends '@engine/%s' %%}\n" "$t"
        for b in $blocks; do
            printf '\n{%% block %s %%}\n    {{ parent() }}\n{%% endblock %%}\n' "$b"
        done
    } > "$dst"
    log "заготовка: templates/$t (блоки: $(echo "$blocks" | tr '\n' ' '))"
}
write_copy() { # write_copy <пак> <ключ> <шаблон>
    local dst="$1/templates/$3"
    if [ -e "$dst" ] && [ "$FORCE" != 1 ]; then warn "$dst уже є — пропущено (--force, щоб переписати)"; return 0; fi
    run mkdir -p "$(dirname "$dst")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] копія $dst"; return 0; }
    {
        printf '{# Тема %s: ПОВНА КОПІЯ шаблону рушія %s (рушій %s). Виправлення рушія сюди не доходять —\n' "$2" "$3" "$(git -C "$SITE_ENGINE_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
        printf '   після оновлення рушія звіряти: bin/site theme diff %s. #}\n' "$2"
        cat "$ENGINE_TPL/$3"
    } > "$dst"
    log "копія: templates/$3"
}
write_css() { # write_css <пак> <ключ>
    local css="$1/themes/$2/theme.css"
    [ -e "$css" ] && { log "themes/$2/theme.css уже є"; return 0; }
    run mkdir -p "$(dirname "$css")"
    [ "$DRY_RUN" = 1 ] && { log "[dry-run] $css"; return 0; }
    {
        printf '/* Тема %s — CSS поверх типової верстки рушія. Підключення: config/parameters.yaml → app.theme.stylesheet:\n' "$2"
        printf '   themes/%s/theme.css. Кольори бренду — app.theme.colors (CSS-змінні --accent, --accent2, --dark, --navy…),\n' "$2"
        printf '   тут — лише те, чого змінними не зробити. Усі правила — під body.theme-%s, щоб не текли в адмінку. */\n' "$2"
        printf 'body.theme-%s {\n}\n' "$2"
    } > "$css"
    log "створено themes/$2/theme.css"
    grep -q "stylesheet: *themes/$2/theme.css" "$1/config/parameters.yaml" 2>/dev/null \
        || warn "у config/parameters.yaml задайте app.theme.stylesheet: themes/$2/theme.css (зараз CSS не підключено)"
}

cmd_new() {
    local target="" mode=extend t pack key
    local -a tpls=()
    for arg in "$@"; do
        case "$arg" in
            --copy) mode=copy ;;
            --extend) mode=extend ;;
            --force) ;;
            -*) die "theme new: невідома опція $arg" ;;
            *) if [ -z "$target" ]; then target="$arg"; else tpls+=("$arg"); fi ;;
        esac
    done
    [ -n "$target" ] || die "theme new: вкажіть ключ сайту або теку паку"
    pack="$(pack_dir_of "$target")"; key="$(pack_key "$pack")"
    [ -n "$key" ] || die "theme: у $pack/site.yaml нема key"
    [ "${#tpls[@]}" -gt 0 ] || read -r -a tpls <<<"$DEFAULT_TEMPLATES"
    for t in "${tpls[@]}"; do
        t="${t#templates/}"
        is_overridable "$t" || die "theme: '$t' — не шаблон рушія з services/** або partials/** (bin/site theme list)"
    done
    log "тема $key у паку $pack"
    write_css "$pack" "$key"
    for t in "${tpls[@]}"; do
        t="${t#templates/}"
        if [ "$mode" = copy ]; then write_copy "$pack" "$key" "$t"; else write_stub "$pack" "$key" "$t"; fi
    done
    [ "$DRY_RUN" = 1 ] || "${SITE_PYTHON:-python3}" "$SITE_CMD_DIR/_validate.py" pack "$SITE_ENGINE_DIR" "$pack" "$SITES_FILE" >/dev/null 2>&1 \
        || warn "bin/site validate $pack — перевірте пак"
    log "далі: правити templates/ і themes/$key/theme.css у паку → commit → стенд підхопить sync; прод — bin/site deploy $key <тег>"
}

cmd_diff() {
    local target="${1:-}" pack key ref base f rel changed=0 total=0 stat blocks
    [ -n "$target" ] || die "theme diff: вкажіть ключ сайту або теку паку"
    pack="$(pack_dir_of "$target")"; key="$(pack_key "$pack")"; ref="$(pack_engine "$pack")"
    [ -d "$pack/templates" ] || { log "$key: у паку нема templates/ — своєї теми нема, порівнювати нічого"; return 0; }
    [ -n "$ref" ] || die "theme diff: у site.yaml паку нема engine: (тег/коміт рушія, з якого робилась тема)"
    base="$(git -C "$SITE_ENGINE_DIR" rev-parse --verify --quiet "$ref^{commit}" || git -C "$SITE_ENGINE_DIR" rev-parse --verify --quiet "origin/$ref^{commit}" || true)"
    [ -n "$base" ] || die "theme diff: рушій не знає '$ref' (git fetch --tags у $SITE_ENGINE_DIR)"
    log "тема $key: перекриті шаблони проти рушія $ref ($(git -C "$SITE_ENGINE_DIR" rev-parse --short "$base")) → HEAD ($(git -C "$SITE_ENGINE_DIR" rev-parse --short HEAD))"
    while IFS= read -r f; do
        rel="${f#"$pack/templates/"}"
        case "$rel" in README.md | .gitkeep) continue ;; esac
        total=$((total + 1))
        if [ ! -f "$ENGINE_TPL/$rel" ]; then echo "  $rel: у рушії такого шаблону нема (власний partial паку або видалено з рушія)"; continue; fi
        stat="$(git -C "$SITE_ENGINE_DIR" diff --numstat "$base" HEAD -- "templates/$rel" | awk '{print "+"$1" -"$2}')"
        if [ -z "$stat" ]; then echo "  $rel: без змін у рушії"; continue; fi
        changed=$((changed + 1))
        blocks="$(git -C "$SITE_ENGINE_DIR" diff "$base" HEAD -- "templates/$rel" | { grep -E '^[-+].*\{%-? *block ' || true; } | sed -E 's/.*block +([A-Za-z0-9_]+).*/\1/' | sort -u | tr '\n' ' ')"
        echo "  $rel: ЗМІНЕНО у рушії ($stat рядків)${blocks:+; блоки: $blocks} → git -C $SITE_ENGINE_DIR diff $ref HEAD -- templates/$rel"
    done < <(find "$pack/templates" -type f | LC_ALL=C sort)
    log "перекрито $total, змінилось у рушії $changed. Повну копію злити руками; extends-заготовка підхопить зміни неперекритих блоків сама"
}

FORCE=0
for a in "$@"; do [ "$a" = --force ] && FORCE=1; done
sub="${1:-}"; shift || true
case "$sub" in
    -h | --help | "") usage; [ -n "$sub" ] && exit 0 || exit 1 ;;
    list) cmd_list ;;
    new) cmd_new "$@" ;;
    diff) cmd_diff "$@" ;;
    *) die "theme: підкоманда list|new|diff (див. bin/site theme --help)" ;;
esac
