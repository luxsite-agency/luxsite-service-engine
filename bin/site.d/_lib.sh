# shellcheck shell=bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
# Спільні функції bin/site. Підключення в підкоманді: source "$SITE_LIB".
# Не виконується сам; змінні SITE_ENGINE_DIR, SITE_CMD_DIR, SITES_FILE експортує bin/site.
set -euo pipefail

: "${SITE_ENGINE_DIR:?запускати через bin/site}"
: "${SITES_FILE:=$SITE_ENGINE_DIR/sites.yaml}"
DRY_RUN="${DRY_RUN:-0}"

log() { printf '>> %s\n' "$*" >&2; }
warn() { printf '!! %s\n' "$*" >&2; }
die() { printf 'site: %s\n' "$*" >&2; exit 1; }

# run <команда…>: виконати або (DRY_RUN=1) лише надрукувати
run() {
    if [ "$DRY_RUN" = 1 ]; then
        printf '[dry-run] %s\n' "$(printf '%q ' "$@")" >&2
        return 0
    fi
    "$@"
}

# Ключ сайту: латиниця/цифри/дефіс, з літери або цифри (пак site-<key>, тема themes/<key>, body.theme-<key>, тека на сервері)
valid_key() { [[ "$1" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]]; }
need_key() {
    [ -n "${1:-}" ] || die "не вказано ключ сайту"
    valid_key "$1" || die "некоректний ключ '$1' (дозволено a-z, 0-9, '-', до 31 символу)"
}

need_yaml() { "${SITE_PYTHON:-python3}" -c 'import yaml' 2>/dev/null || die "потрібні ${SITE_PYTHON:-python3} і PyYAML (apt install python3-yaml)"; }

# reg_get <key> <поле.через.крапку> [дефолт] — значення з реєстру sites.yaml ("${SITE_PYTHON:-python3}" + PyYAML).
reg_get() {
    local key="$1" path="$2" def="${3-}"
    [ -f "$SITES_FILE" ] || die "нема реєстру $SITES_FILE (реєстр живе поза рушієм: SITES_FILE=/шлях/sites.yaml; приклад — sites.example.yaml)"
    "${SITE_PYTHON:-python3}" - "$SITES_FILE" "$key" "$path" "$def" <<'PY' | tr -d '\r'
import sys, yaml
f, key, path, default = sys.argv[1:5]
data = yaml.safe_load(open(f, encoding="utf-8")) or {}
sites = data.get("sites", data) if isinstance(data, dict) else data
if isinstance(sites, dict):
    rec = sites.get(key)
    if isinstance(rec, dict):
        rec = {"key": key, **rec}
else:
    rec = next((s for s in sites or [] if isinstance(s, dict) and str(s.get("key")) == key), None)
if rec is None:
    sys.stderr.write(f"site: сайту '{key}' нема в реєстрі {f}\n"); sys.exit(3)
cur = rec
for part in path.split("."):
    cur = cur.get(part) if isinstance(cur, dict) else None
    if cur is None:
        break
if cur is None:
    print(default)
elif isinstance(cur, bool):
    print("yes" if cur else "no")
elif isinstance(cur, (list, dict)):
    print(" ".join(map(str, cur)) if isinstance(cur, list) else yaml.safe_dump(cur).strip())
else:
    print(cur)
PY
}

# reg_keys [статус] — ключі всіх сайтів реєстру (за статусом, якщо задано)
reg_keys() {
    [ -f "$SITES_FILE" ] || die "нема реєстру $SITES_FILE (реєстр живе поза рушієм: SITES_FILE=/шлях/sites.yaml; приклад — sites.example.yaml)"
    "${SITE_PYTHON:-python3}" - "$SITES_FILE" "${1-}" <<'PY' | tr -d '\r'
import sys, yaml
f, status = sys.argv[1], sys.argv[2]
data = yaml.safe_load(open(f, encoding="utf-8")) or {}
sites = data.get("sites", data) if isinstance(data, dict) else data
items = [{"key": k, **v} for k, v in sites.items()] if isinstance(sites, dict) else (sites or [])
for s in items:
    if isinstance(s, dict) and (not status or s.get("status") == status):
        print(s["key"])
PY
}

# ---------- Контекст сайту з реєстру ----------
# site_ctx <key> — заповнює S_* одним викликом python:
#   S_HOST S_USER S_PATH S_RUNTIME (fastpanel|docker) S_PROXY (docker: traefik — стенд, docker-compose.traefik.yml;
#   edge — прод за зовнішнім nginx у docker-мережі edge, docker-compose.edge.yml; fastpanel: порожньо) S_COMPOSE (docker: свій
#   compose-файл — ім'я в релізі або абсолютний шлях на сервері; порожньо = типовий для proxy) S_PHP (бінарник php на сервері) S_FPM_RELOAD (команда після
#   перемикання релізу, порожньо = без) S_DOCROOT (симлінк docroot панелі, лише fastpanel) S_LEGACY_APP (стара тека
#   застосунку: звідки перший деплой бере .env.local і public/media) S_CHECK_IP (IP, на якому слухає nginx сервера;
#   порожньо = перевірка через nginx пропускається) S_SITE S_ADMIN S_EXTRA (domains.extra через пробіл: інші хости міст
#   на окремих доменах — smoke, http-перевірка, TLS у doctor) S_ENGINE S_STATUS S_SUPPORT S_BACKUP_TARGET S_PACK S_REPO
site_ctx() {
    need_key "$1"
    need_yaml
    local out
    out="$("${SITE_PYTHON:-python3}" - "$SITES_FILE" "$1" <<'PY' | tr -d '\r'
import shlex, sys, yaml
f, key = sys.argv[1:3]
data = yaml.safe_load(open(f, encoding="utf-8")) or {}
sites = data.get("sites", data) if isinstance(data, dict) else data
items = [{"key": k, **v} for k, v in sites.items()] if isinstance(sites, dict) else (sites or [])
rec = next((s for s in items if isinstance(s, dict) and str(s.get("key")) == key), None)
if rec is None:
    sys.stderr.write(f"site: сайту '{key}' нема в реєстрі {f}\n"); sys.exit(3)
srv = rec.get("server") or {}; dom = rec.get("domains") or {}
def b(v): return ("yes" if v else "no") if isinstance(v, bool) else ("" if v is None else str(v))
vals = {
    "S_HOST": srv.get("host"), "S_USER": srv.get("user"), "S_PATH": srv.get("path"), "S_RUNTIME": srv.get("runtime", "fastpanel"),
    "S_PROXY": srv.get("proxy") or ("traefik" if srv.get("runtime") == "docker" else ""), "S_COMPOSE": srv.get("compose"),
    "S_NETWORK": srv.get("network"), "S_ENTRYPOINT": srv.get("entrypoint", "websecure"), "S_CERTRESOLVER": srv.get("certresolver"),
    "S_WWW": srv.get("www_redirect", False),
    "S_PHP": srv.get("php", "php"), "S_FPM_RELOAD": srv.get("fpm_reload"), "S_DOCROOT": srv.get("docroot"),
    "S_DOCROOT_EXTRA": " ".join(str(x) for x in (srv.get("docroot_extra") or [])),
    "S_LEGACY_APP": srv.get("legacy_app"), "S_CHECK_IP": srv.get("check_ip"),
    "S_SITE": dom.get("site"), "S_ADMIN": dom.get("admin"), "S_EXTRA": " ".join(str(x) for x in (dom.get("extra") if isinstance(dom.get("extra"), list) else [dom.get("extra")] if dom.get("extra") else [])),
    "S_ENGINE": rec.get("engine"), "S_STATUS": rec.get("status", "active"),
    "S_SUPPORT": rec.get("support"), "S_BACKUP_TARGET": rec.get("backup_target"), "S_PACK": rec.get("pack"), "S_REPO": rec.get("repo"),
    "S_AUTO_UPDATE": rec.get("auto_update", False),
}
for k, v in vals.items():
    print(f"{k}={shlex.quote(b(v))}")
PY
)" || exit 1
    eval "$out"
    S_KEY="$1"
    if [ -z "$S_HOST" ] || [ -z "$S_PATH" ]; then die "$S_KEY: у реєстрі нема server.host або server.path"; fi
    case "$S_RUNTIME" in fastpanel | docker) ;; *) die "$S_KEY: server.runtime має бути fastpanel|docker, а не '$S_RUNTIME'" ;; esac
    case "$S_RUNTIME:$S_PROXY" in fastpanel: | docker:traefik | docker:edge | docker:traefik-ext) ;;
        *) die "$S_KEY: server.proxy '$S_PROXY' — лише для runtime docker: traefik (стенд) | edge (прод за зовнішнім nginx) | traefik-ext (прод за чужим traefik)" ;;
    esac
    if [ -n "$S_COMPOSE" ] && [ "$S_RUNTIME" != docker ]; then die "$S_KEY: server.compose — лише для runtime docker"; fi
}

# Схема теки на сервері: …/site-<key>. Старі робочі копії (<key>-app з git checkout + post-receive) bin/site не чіпає ніколи.
is_site_layout() { case "$S_PATH" in */site-"$S_KEY") return 0 ;; *) return 1 ;; esac; }

# guard_write — для команд, що змінюють сайт (deploy/rollback/backup/restore/archive/cutover)
guard_write() {
    is_site_layout || die "$S_KEY: server.path '$S_PATH' не за схемою …/site-$S_KEY — стара схема (git checkout + post-receive), bin/site її не чіпає"
    case "$S_STATUS" in
        archived) [ "${UNARCHIVE:-0}" = 1 ] || die "$S_KEY: status archived — лише з UNARCHIVE=1 і рішенням власника" ;;
        handed-over) die "$S_KEY: status handed-over — сайт передано клієнту, нашого доступу нема" ;;
    esac
}

is_local_host() { case "$S_HOST" in local | localhost | "$(hostname 2>/dev/null | cut -d. -f1)") return 0 ;; *) return 1 ;; esac; }
ssh_dest() { if [ -n "$S_USER" ] && [ "${S_SSH_AS_USER:-0}" = 1 ]; then echo "$S_USER@$S_HOST"; else echo "$S_HOST"; fi; }

# remote <дія> [аргументи] — виконати bin/site.d/_remote.sh на сервері сайту (stdout — його stdout).
# Скрипт передається в тимчасовий файл на сервері, тож його stdin вільний; змінні — через env.
# На FastPanel ssh іде під root (аліас сервера), файлові дії робляться `sudo -u <user>` усередині _remote.sh.
remote() {
    local action="$1"; shift
    local envs=(DRY_RUN="$DRY_RUN" UNARCHIVE="${UNARCHIVE:-0}" SITE_CRON="${SITE_CRON:-1}"
        SITE_SKIP_BACKUP="${SITE_SKIP_BACKUP:-0}" SITE_BACKUP_KEEP_DAYS="${SITE_BACKUP_KEEP_DAYS:-14}"
        SITE_RUNTIME="$S_RUNTIME" SITE_PROXY="$S_PROXY" SITE_COMPOSE_FILE="$S_COMPOSE" SITE_USER="$S_USER" SITE_PHP="$S_PHP" SITE_FPM_RELOAD="$S_FPM_RELOAD"
        SITE_DOCROOT="$S_DOCROOT" SITE_DOCROOT_EXTRA="$S_DOCROOT_EXTRA" SITE_LEGACY_APP="$S_LEGACY_APP" SITE_CHECK_IP="$S_CHECK_IP"
        SITE_HOST="$S_SITE" SITE_ADMIN="$S_ADMIN" SITE_EXTRA="$S_EXTRA"
        SITE_PROXY_NETWORK="$S_NETWORK" SITE_TRAEFIK_ENTRYPOINT="$S_ENTRYPOINT" SITE_TRAEFIK_CERTRESOLVER="$S_CERTRESOLVER" SITE_WWW_REDIRECT="$S_WWW")
    if is_local_host; then
        env "${envs[@]}" bash "$SITE_CMD_DIR/_remote.sh" "$action" "$S_KEY" "$S_PATH" "$@" </dev/null
        return
    fi
    local cmd
    cmd="f=\$(mktemp) && cat > \"\$f\" && env $(printf '%q ' "${envs[@]}") bash \"\$f\" $(printf '%q ' "$action" "$S_KEY" "$S_PATH" "$@"); rc=\$?; rm -f \"\$f\"; exit \$rc"
    if [ "$DRY_RUN" = 1 ]; then
        printf '[dry-run] ssh %s %s <_remote.sh\n' "$(ssh_dest)" "$action $*" >&2
        return 0
    fi
    ssh -o BatchMode=yes "$(ssh_dest)" "$cmd" < "$SITE_CMD_DIR/_remote.sh"
}

# notify <текст> — сповіщення оператору (bin/site sync --notify, doctor --notify). Канали, по черзі:
#   SITE_NOTIFY_CMD — команда, що читає текст зі stdin (напр. власний скрипт);
#   файл $SITE_NOTIFY_ENV (типово <рушій>/../.secrets/site-notify.env, 600) з TELEGRAM_BOT_TOKEN і TELEGRAM_CHAT_ID —
#   Bot API sendMessage (бот, не акаунт власника; токен ніде не друкується);
#   інакше — лише попередження в stderr. Текст — як є (без markdown), до 4000 символів.
notify() {
    local text="$1" envf="${SITE_NOTIFY_ENV:-$SITE_ENGINE_DIR/../.secrets/site-notify.env}"
    text="${text:0:4000}"
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] notify: $(printf '%s' "$text" | head -n1)"; return 0; fi
    if [ -n "${SITE_NOTIFY_CMD:-}" ]; then printf '%s\n' "$text" | bash -c "$SITE_NOTIFY_CMD" && return 0; fi
    if [ -r "$envf" ]; then
        local tok chat
        tok="$(sed -n 's/^TELEGRAM_BOT_TOKEN=//p' "$envf" | tr -d '"\r' | tail -n1)"
        chat="$(sed -n 's/^TELEGRAM_CHAT_ID=//p' "$envf" | tr -d '"\r' | tail -n1)"
        if [ -n "$tok" ] && [ -n "$chat" ]; then
            curl -s -o /dev/null --max-time 20 -X POST "https://api.telegram.org/bot$tok/sendMessage" \
                --data-urlencode "chat_id=$chat" --data-urlencode "text=$text" --data-urlencode "disable_web_page_preview=1" \
                && return 0
            warn "notify: Telegram не прийняв повідомлення"
            return 0
        fi
    fi
    warn "notify: канал не налаштовано ($envf або SITE_NOTIFY_CMD) — повідомлення лише в лозі"
    return 0
}

# put <локальний файл> <шлях на сервері> / get <шлях на сервері> <локальна тека>
put() {
    if is_local_host; then run cp "$1" "$2"; else run rsync -a --partial -e "ssh -o BatchMode=yes" "$1" "$(ssh_dest):$2"; fi
}
get() {
    if is_local_host; then run cp -p "$1" "$2/"; else run rsync -a --partial -e "ssh -o BatchMode=yes" "$(ssh_dest):$1" "$2/"; fi
}
