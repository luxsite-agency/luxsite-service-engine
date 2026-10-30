#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# summary: створити пак сайту з шаблону: new <key> <домен> [--from=template|<тека>] [--dir=…] [--github]
#
#   bin/site new <key> <домен> [опції]
#
#   <key>            ключ сайту (a-z, 0-9, '-'): пак site-<key>, тема themes/<key>, тека на сервері site-<key>
#   <домен>          бойовий домен сайту (apex, він же місто за замовчуванням); адмінка — admin.<домен>, якщо не задано --admin
#   --from=SRC       template (типово: site-template/ цього рушія) або тека іншого паку як зразок
#   --name=TEXT      назва сайту для людей (типово — ключ)
#   --admin=HOST     хост адмінки
#   --locales=uk,ru  мови (перша — за замовчуванням), типово uk,ru
#   --scheme=flat|nested  схема URL каталогу (типово nested)
#   --dir=DIR        куди створити пак (типово $SITE_PACKS_DIR/site-<key>, SITE_PACKS_DIR типово — поточна тека)
#   --github         ще й створити приватний репо $SITE_GITHUB_ORG/site-<key> (gh), дати доступ команді $SITE_GITHUB_TEAM (developers), push
#
# Створює теку з git init і першим комітом, перевіряє пак (bin/site validate) і друкує запис для sites.yaml —
# реєстр змінюється PR-ом у рушій, не цією командою. DRY_RUN=1 — лише показати дії.
set -euo pipefail
# shellcheck source=bin/site.d/_lib.sh
source "$SITE_LIB"

TEMPLATE_DIR="$SITE_ENGINE_DIR/site-template"
TEMPLATE_KEY=sitetemplate
GITHUB_ORG="${SITE_GITHUB_ORG:-}"   # організація GitHub для приватних репо паків (--github); порожньо = лише підказка в реєстрі
GITHUB_TEAM="${SITE_GITHUB_TEAM:-developers}"
need_yaml

usage() { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; }

key="" domain="" from=template name="" admin="" locales="uk,ru" scheme=nested dir="" github=0
for arg in "$@"; do
    case "$arg" in
        -h | --help) usage; exit 0 ;;
        --from=*) from="${arg#*=}" ;;
        --name=*) name="${arg#*=}" ;;
        --admin=*) admin="${arg#*=}" ;;
        --locales=*) locales="${arg#*=}" ;;
        --scheme=*) scheme="${arg#*=}" ;;
        --dir=*) dir="${arg#*=}" ;;
        --github) github=1 ;;
        -*) die "new: невідома опція $arg (див. bin/site new --help)" ;;
        *)
            if [ -z "$key" ]; then key="$arg"
            elif [ -z "$domain" ]; then domain="$arg"
            else die "new: зайвий аргумент $arg"
            fi
            ;;
    esac
done
need_key "$key"
[ "$key" != "$TEMPLATE_KEY" ] || die "new: ключ '$key' зарезервовано шаблоном"
host_re='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
[[ "$domain" =~ $host_re ]] || die "new: домен '$domain' — ім'я хоста малими літерами (example.com.ua)"
admin="${admin:-admin.$domain}"
[[ "$admin" =~ $host_re ]] || die "new: --admin '$admin' — ім'я хоста"
[ "$admin" != "$domain" ] || die "new: адмінка й сайт мають бути різними хостами"
[[ "$locales" =~ ^[a-z]{2}(,[a-z]{2})*$ ]] || die "new: --locales — коди мов через кому (uk,ru)"
case "$scheme" in flat | nested) ;; *) die "new: --scheme flat|nested" ;; esac
name="${name:-$key}"
case "$name" in *$'\n'* | *"'"*) die "new: --name без апострофів і переносів рядка" ;; esac

case "$from" in
    template) src="$TEMPLATE_DIR"; src_key="$TEMPLATE_KEY" ;;
    *)
        [ -f "$from/site.yaml" ] || die "new: --from=$from — нема site.yaml (тека паку?)"
        src="$from"
        src_key="$("${SITE_PYTHON:-python3}" -c 'import sys,yaml; print(yaml.safe_load(open(sys.argv[1], encoding="utf-8"))["key"])' "$from/site.yaml")"
        ;;
esac
dir="${dir:-${SITE_PACKS_DIR:-$PWD}/site-$key}"
[ ! -e "$dir" ] || die "new: $dir уже існує — не перезаписую"
if reg_get "$key" key >/dev/null 2>&1; then die "new: сайт '$key' уже є в реєстрі $SITES_FILE"; fi
engine="$(git -C "$SITE_ENGINE_DIR" describe --tags --exact-match 2>/dev/null || git -C "$SITE_ENGINE_DIR" rev-parse --short=7 HEAD)"

log "пак site-$key з $( [ "$from" = template ] && echo "шаблону" || echo "$src" ) → $dir (рушій $engine)"
if [ "$DRY_RUN" = 1 ]; then
    run cp -a "$src/." "$dir/"
    run git -C "$dir" init -q -b main
    if [ "$github" = 1 ]; then [ -n "$GITHUB_ORG" ] || die "new: --github потребує SITE_GITHUB_ORG"; run gh repo create "$GITHUB_ORG/site-$key" --private --source "$dir" --push; fi
    exit 0
fi

mkdir -p "$(dirname "$dir")"
tmp="$(mktemp -d "$(dirname "$dir")/.site-new-$key.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT
tar -C "$src" --exclude=.git --exclude='.env*' --exclude=.secrets -cf - . | tar -C "$tmp" -xf -
if [ -d "$tmp/themes/$src_key" ]; then mv "$tmp/themes/$src_key" "$tmp/themes/$key"; else mkdir -p "$tmp/themes/$key"; fi

"${SITE_PYTHON:-python3}" - "$tmp" "$src_key" "$key" "$name" "$domain" "$admin" "$locales" "$engine" "$scheme" <<'PY'
import os, re, sys, yaml
root, src_key, key, name, domain, admin, locales, engine, scheme = sys.argv[1:10]
sy = os.path.join(root, "site.yaml")
meta = yaml.safe_load(open(sy, encoding="utf-8")) or {}
old_site = (meta.get("domains") or {}).get("site", "sitetemplate.example.com")
old_admin = (meta.get("domains") or {}).get("admin", "admin.sitetemplate.example.com")
old_name = str(meta.get("name", src_key))
pairs = [(old_admin, admin), (old_site, domain), (old_name, name)]
key_re = re.compile(r"(?<![a-z0-9])" + re.escape(src_key) + r"(?![a-z0-9])")
TEXT = {".yaml", ".yml", ".md", ".csv", ".json", ".txt", ".css", ".html", ".twig", ".svg"}
for dp, _, files in os.walk(root):
    for f in files:
        p = os.path.join(dp, f)
        if os.path.splitext(f)[1] not in TEXT:
            continue
        s = open(p, encoding="utf-8").read()
        t = s
        for a, b in pairs:
            t = t.replace(a, b)
        t = key_re.sub(key, t)
        if t != s:
            open(p, "w", encoding="utf-8").write(t)
s = open(sy, encoding="utf-8").read()
s = re.sub(r"(?m)^locales: \[.*\]$", "locales: [" + ", ".join(locales.split(",")) + "]", s)
s = re.sub(r"(?m)^engine: .*$", "engine: " + engine, s)
open(sy, "w", encoding="utf-8").write(s)
py = os.path.join(root, "config/parameters.yaml")
s = open(py, encoding="utf-8").read()
s = re.sub(r"(?m)^(\s*app\.catalog\.url_scheme: ).*$", lambda m: m.group(1) + scheme, s)
open(py, "w", encoding="utf-8").write(s)
PY

"${SITE_PYTHON:-python3}" "$SITE_CMD_DIR/_validate.py" pack "$SITE_ENGINE_DIR" "$tmp" "$SITES_FILE" || die "new: пак не пройшов перевірку (тека не створена)"
mv "$tmp" "$dir"
trap - EXIT

git -C "$dir" init -q -b main
git -C "$dir" add -A
git -C "$dir" -c user.name="${GIT_AUTHOR_NAME:-$(git -C "$SITE_ENGINE_DIR" config user.name || echo site)}" \
    -c user.email="${GIT_AUTHOR_EMAIL:-$(git -C "$SITE_ENGINE_DIR" config user.email || echo site@localhost)}" \
    commit -q -m "Пак сайту $key з $( [ "$from" = template ] && echo "site-template" || echo "$src_key" ) (рушій $engine)"
log "пак створено: $dir"

if [ "$github" = 1 ]; then
    [ -n "$GITHUB_ORG" ] || die "new: --github потребує SITE_GITHUB_ORG (організація GitHub для приватного репо паку)"
    command -v gh >/dev/null || die "new: нема gh CLI — репо створіть вручну (приватний, команда $GITHUB_TEAM)"
    run gh repo create "$GITHUB_ORG/site-$key" --private --source "$dir" --remote origin --push
    run gh api -X PUT "orgs/$GITHUB_ORG/teams/$GITHUB_TEAM/repos/$GITHUB_ORG/site-$key" -f permission=push
    log "репо: https://github.com/$GITHUB_ORG/site-$key (приватний)"
fi

loc_list="${locales//,/, }"
cat <<EOF

Запис для реєстру ($SITES_FILE, додати PR-ом у рушій; server і backup_target — уточнити):
    - key: $key
      name: '$name'
      repo: ${GITHUB_ORG:-<org>}/site-$key
      pack: $dir
      server:
          host: <ssh-аліас сервера>
          user: <користувач FastPanel-акаунта>
          runtime: fastpanel
          path: /var/www/<user>/data/www/site-$key
          php: /opt/php84/bin/php
          fpm_reload: systemctl reload fp2-php84-fpm
          docroot: /var/www/<user>/data/www/$domain
          check_ip: <IP, на якому слухає nginx>
      domains:
          site: $domain
          admin: $admin
      locales: [$loc_list]
      engine: $engine
      status: active
      support: true
      backup_target: <host:/шлях сховища бекапів>

Далі: docs/HANDOVER.md у паку → заповнити; сайт/міста/контакти — в адмінці (ServiceSite, ServiceCity) після першого деплою.
Свій дизайн (не типовий): кольори/лого — config/parameters.yaml (app.theme) і themes/$key/; своя верстка — templates/ у паку
(перекриває однойменні шаблони рушія, як — templates/README.md).
EOF
