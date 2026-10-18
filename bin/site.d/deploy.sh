#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: зібрати реліз рушій+пак і розгорнути з відкатом: deploy <key> [ref] [--pack <тека>]
# bin/site deploy <key> [ref] [--pack <тека паку>]
#   ref  — тег/коміт рушія (типово engine з реєстру, далі HEAD); деплоїться закомічене, не робоча копія.
#   пак  — --pack, або SITE_PACK, або pack (локальний шлях) з реєстру, або клон repo (owner/name на GitHub).
# Локально: git archive рушія + з паку config/parameters.yaml → config/site/parameters.local.yaml, themes/<key>/ →
# public/themes/<key>/, translations/*.yaml → translations/site/, templates/ (свій дизайн) → templates/site/; файл SITE_RELEASE (версії) → на сервер у
# <path>/releases/<тег>, тег = <sha рушія 12>-<хеш паку 8>. На сервері (bin/site.d/_remote.sh deploy): shared
# (.env.local, media, log) → composer install у релізі → бекап БД → міграції → cache:warmup → smoke (ядро Symfony) →
# перемикання current (+ скидання php-fpm) → перевірка через nginx; провал після перемикання — відкат на попередній тег.
# Змінні: DRY_RUN=1, UNARCHIVE=1, SITE_CRON=0 (без cron бекапу), SITE_SKIP_BACKUP=1.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

key="" ref="" pack="${SITE_PACK:-}"
while [ $# -gt 0 ]; do
    case "$1" in
        --pack) pack="${2:?--pack <тека>}"; shift 2 ;;
        --pack=*) pack="${1#--pack=}"; shift ;;
        -h | --help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "невідомий параметр $1" ;;
        *) if [ -z "$key" ]; then key="$1"; elif [ -z "$ref" ]; then ref="$1"; else die "зайвий аргумент $1"; fi; shift ;;
    esac
done
site_ctx "$key"
guard_write
[ -n "$S_SITE" ] || die "$key: у реєстрі нема domains.site"

# --- рушій ---
cd "$SITE_ENGINE_DIR"
ref="${ref:-${S_ENGINE:-HEAD}}"
# Гілка (main, feat/…) береться з origin після fetch — локальна копія гілки на операторській машині може відставати;
# HEAD, тег і sha — як є.
if [ "$ref" != HEAD ]; then git fetch -q origin --tags 2>/dev/null || warn "git fetch origin не вдався — беру локальні refs"; fi
if [ "$ref" != HEAD ] && sha="$(git rev-parse --verify -q --short=12 "origin/$ref^{commit}" 2>/dev/null)"; then :
else
    sha="$(git rev-parse --verify -q --short=12 "$ref^{commit}")" || die "нема коміту '$ref' у рушії"
fi
if [ "$ref" = HEAD ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    warn "у робочій копії рушія є незакомічені зміни — деплоїться лише коміт $sha"
fi

# --- пак ---
work="$(mktemp -d "${TMPDIR:-/tmp}/site-deploy-$key.XXXXXX")"
trap 'rm -rf "$work"' EXIT
pack="${pack:-$S_PACK}"
if [ -z "$pack" ]; then
    [ -n "$S_REPO" ] || die "$key: нема паку (--pack, SITE_PACK, pack або repo у реєстрі)"
    url="$S_REPO"; case "$url" in *:* | /*) ;; *) url="git@github.com:$S_REPO.git" ;; esac
    log "клон паку $url"
    git clone -q --depth 1 "$url" "$work/pack" || die "не вдалося клонувати пак $url"
    pack="$work/pack"
fi
[ -d "$pack" ] || die "нема теки паку $pack"
pack="$(cd "$pack" && pwd)"
[ -f "$pack/config/parameters.yaml" ] || die "у паку нема config/parameters.yaml ($pack)"
[ -d "$pack/themes/$key" ] || warn "у паку нема themes/$key/ — тема лише з параметрів"
pack_git="$(git -C "$pack" rev-parse --short=12 HEAD 2>/dev/null || echo -)"
[ "$pack_git" != - ] && [ -n "$(git -C "$pack" status --porcelain 2>/dev/null)" ] && pack_git="$pack_git+dirty"
pack_hash="$(cd "$pack" && { find config/parameters.yaml "themes/$key" translations templates -type f 2>/dev/null || true; } | LC_ALL=C sort | xargs -r sha256sum | sha256sum | cut -c1-8)"
tag="$sha-$pack_hash"
log "сайт $key: рушій $sha ($ref), пак $pack ($pack_git) → тег $tag, сервер $(ssh_dest):$S_PATH ($S_RUNTIME)"

log "bin/site validate $key"
SITE_PACK="$pack" bash "$SITE_CMD_DIR/validate.sh" "$key" || die "пак не пройшов bin/site validate"

# --- контекст збірки ---
ctx="$work/ctx"
mkdir -p "$ctx"
git archive --format=tar "$sha" | tar -x -C "$ctx"
[ -f "$ctx/bin/site.d/_remote.sh" ] || die "у рушії $sha ще нема bin/site — деплойте новіший коміт"
mkdir -p "$ctx/config/site" "$ctx/public/themes" "$ctx/translations/site" "$ctx/templates/site"
{
    echo "# Згенеровано bin/site deploy для сайту $key з паку (config/parameters.yaml). Не редагувати — наступний деплой перепише."
    cat "$pack/config/parameters.yaml"
} > "$ctx/config/site/parameters.local.yaml"
if [ -d "$pack/themes/$key" ]; then
    rm -rf "$ctx/public/themes/$key"
    cp -a "$pack/themes/$key" "$ctx/public/themes/$key"
fi
if ls "$pack"/translations/*.yaml >/dev/null 2>&1; then cp -a "$pack"/translations/*.yaml "$ctx/translations/site/"; fi
# свій дизайн: templates/ паку → templates/site/ рушія (Twig шукає там першим; на сервері lint:twig перед smoke)
if [ -d "$pack/templates" ]; then cp -a "$pack/templates/." "$ctx/templates/site/"; fi
rm -rf "$ctx/local" "$ctx/.github" "$ctx/tests"
{
    echo "key=$key"
    echo "engine_ref=$ref"
    echo "engine_sha=$sha"
    echo "pack_hash=$pack_hash"
    echo "pack_git=$pack_git"
    echo "built_at=$(date -Is)"
    echo "built_by=$(id -un)@$(hostname 2>/dev/null | cut -d. -f1)"
} > "$ctx/SITE_RELEASE"

# --- завантаження контексту на сервер (якщо цього тегу там ще нема) ---
rel="$S_PATH/releases/$tag"
# shellcheck disable=SC2016  # виконується на сервері, $1 — шлях релізу
upload='set -e; d="$1"; [ -d "$d" ] && { cat >/dev/null; echo exists; exit 0; }; mkdir -p "$d.part"; tar -x -C "$d.part"; mv "$d.part" "$d"; echo uploaded'
if [ "$DRY_RUN" = 1 ]; then
    log "[dry-run] контекст $(du -sh "$ctx" | cut -f1) → $(ssh_dest):$rel"
elif is_local_host; then
    tar -C "$ctx" -cf - . | bash -c "$upload" _ "$rel" | sed 's/^/>> контекст: /' >&2
else
    tar -C "$ctx" -cf - . | ssh -o BatchMode=yes "$(ssh_dest)" "bash -c $(printf '%q' "$upload") _ $(printf '%q' "$rel")" | sed 's/^/>> контекст: /' >&2
fi

remote deploy "$tag"
