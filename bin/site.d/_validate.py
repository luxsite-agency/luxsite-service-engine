#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
"""Перевірка реєстру sites.yaml і паку сайту (bin/site validate). Лише python3 + PyYAML.

  _validate.py registry <sites.yaml>
  _validate.py pack <тека рушія> <тека паку> <sites.yaml> [<key>]

Правила: docs/site-pack.md. Секретів ні в реєстрі, ні в паку бути не може — тут це перевіряється грубо (назви файлів,
шаблони DSN/токенів), тонке — guard у CI рушія.
"""
import json
import os
import re
import sys

import yaml

KEY_RE = re.compile(r"^[a-z0-9][a-z0-9-]{0,30}$")
HOST_RE = re.compile(r"^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")
SECRET_FILE_RE = re.compile(r"(^|/)(\.env(\..*)?|.*\.sql(\.gz)?|.*\.pem|id_rsa.*|id_ed25519.*|.*\.p12|\.secrets.*)$")
SECRET_TEXT_RE = re.compile(r"mysql://[^:/\s]+:[^@/\s]{6,}@|(PIPEDRIVE_API_TOKEN|STUDIO_API_TOKEN|APP_SECRET|GOOGLE_CLIENT_SECRET)\s*[:=]\s*['\"]?[A-Za-z0-9_-]{12,}")
TEXT_EXT = {".yaml", ".yml", ".md", ".json", ".txt", ".css", ".twig", ".html", ".svg", ".csv"}
MAX_PACK_MB = 30
MAX_FILE_MB = 5

errors = []
warnings = []


def err(msg):
    errors.append(msg)


def warn(msg):
    warnings.append(msg)


def load(path):
    with open(path, encoding="utf-8") as f:
        return yaml.safe_load(f)


def registry_items(data):
    sites = data.get("sites", data) if isinstance(data, dict) else data
    if isinstance(sites, dict):
        return [{"key": k, **(v or {})} for k, v in sites.items()]
    return [s for s in (sites or []) if isinstance(s, dict)]


def check_registry(path):
    try:
        data = load(path)
    except Exception as e:  # noqa: BLE001
        err(f"реєстр {path}: не читається ({e})")
        return []
    if not isinstance(data, dict) or data.get("version") != 1:
        err(f"реєстр {path}: очікується version: 1")
    items = registry_items(data)
    seen = set()
    for s in items:
        key = str(s.get("key", ""))
        where = f"реєстр: {key or '<без key>'}"
        if not KEY_RE.match(key):
            err(f"{where}: некоректний key")
        if key in seen:
            err(f"{where}: ключ повторюється")
        seen.add(key)
        srv = s.get("server") or {}
        for f in ("host", "path"):
            if not srv.get(f):
                err(f"{where}: нема server.{f}")
        runtime = srv.get("runtime", "fastpanel")
        if runtime not in ("fastpanel", "docker"):
            err(f"{where}: server.runtime має бути fastpanel|docker")
        proxy = srv.get("proxy")
        if proxy is not None and (runtime != "docker" or proxy not in ("traefik", "edge")):
            err(f"{where}: server.proxy — лише для runtime docker: traefik (стенд) | edge (прод за зовнішнім nginx)")
        compose = srv.get("compose")
        if compose is not None and (runtime != "docker" or not isinstance(compose, str) or not compose.strip()):
            err(f"{where}: server.compose — лише для runtime docker: ім'я compose-файлу в релізі або абсолютний шлях на сервері")
        if runtime == "docker" and proxy == "edge" and not srv.get("user"):
            warn(f"{where}: docker/edge без server.user — shared/.env.local (600) лишиться root-овим, а php у контейнері — www-data")
        path_ = str(srv.get("path", ""))
        if path_ and not path_.endswith(f"/site-{key}"):
            warn(f"{where}: server.path не за схемою …/site-{key} — bin/site такий сайт не обслуговує (стара схема)")
        if runtime == "fastpanel" and path_.endswith(f"/site-{key}") and not srv.get("docroot"):
            warn(f"{where}: fastpanel без server.docroot — doctor не перевірить, куди дивиться панель")
        dom = s.get("domains") or {}
        for f in ("site", "admin"):
            v = dom.get(f)
            if not v or not HOST_RE.match(str(v)):
                err(f"{where}: domains.{f} — ім'я хоста малими літерами")
        if dom.get("site") and dom.get("site") == dom.get("admin"):
            err(f"{where}: сайт і адмінка мають бути різними хостами")
        # інші домени міст (місто на окремому домені, ServiceCity.host): smoke, http-перевірка і TLS у doctor
        extra = dom.get("extra")
        if extra is not None:
            if not isinstance(extra, list) or not all(isinstance(x, str) and HOST_RE.match(x) for x in extra):
                err(f"{where}: domains.extra — список імен хостів малими літерами")
            elif len(set(extra)) != len(extra) or set(extra) & {dom.get("site"), dom.get("admin")}:
                err(f"{where}: domains.extra — без повторів і без domains.site/domains.admin")
        locs = s.get("locales")
        if not isinstance(locs, list) or not locs or not all(re.match(r"^[a-z]{2}$", str(x)) for x in locs):
            err(f"{where}: locales — список кодів мов (uk, ru)")
        if not s.get("engine"):
            err(f"{where}: нема engine (тег або sha рушія)")
        if s.get("status", "active") not in ("active", "archived", "handed-over"):
            err(f"{where}: status має бути active|archived|handed-over")
        if "support" in s and not isinstance(s.get("support"), bool):
            err(f"{where}: support — true|false")
        if "auto_update" in s and not isinstance(s.get("auto_update"), bool):
            err(f"{where}: auto_update — true|false")
        if s.get("repo") and not re.match(r"^[\w.-]+/[\w.-]+$", str(s["repo"])):
            err(f"{where}: repo — owner/name")
        txt = yaml.safe_dump(s, allow_unicode=True)
        if SECRET_TEXT_RE.search(txt):
            err(f"{where}: схоже на секрет у реєстрі (DSN/токен) — секрети лише на сервері")
    return items


def engine_params(engine_dir):
    """Відомі рушію параметри app.* і env(...) з config/services.yaml; ключі теми — з SiteTheme::DEFAULTS."""
    params = {}
    try:
        data = load(os.path.join(engine_dir, "config/services.yaml")) or {}
        params = {k: v for k, v in (data.get("parameters") or {}).items() if k.startswith("app.") or k.startswith("env(")}
    except Exception as e:  # noqa: BLE001
        warn(f"рушій: config/services.yaml не прочитано ({e}) — перевірку параметрів пропущено")
    theme_keys, color_keys = set(), set()
    try:
        src = open(os.path.join(engine_dir, "src/ServiceSite/SiteTheme.php"), encoding="utf-8").read()
        m = re.search(r"DEFAULTS\s*=\s*\[(.*?)\n\s*\];", src, re.S)
        if m:
            body = m.group(1)
            cm = re.search(r"'colors'\s*=>\s*\[(.*?)\n\s*\],", body, re.S)
            if cm:
                color_keys = set(re.findall(r"'([a-z0-9_]+)'\s*=>", cm.group(1)))
                body = body.replace(cm.group(0), "'colors' => [],")
            theme_keys = set(re.findall(r"'([a-z0-9_]+)'\s*=>", body))
    except OSError:
        warn("рушій: src/ServiceSite/SiteTheme.php не прочитано — перевірку теми пропущено")
    return params, theme_keys, color_keys


# Перші сегменти фіксованих маршрутів сайту — як CatalogUrls::RESERVED_SEGMENTS (src/ServiceSite/CatalogUrls.php)
RESERVED_SEGMENTS = {
    "services", "districts", "blog", "news", "about", "contacts", "faq", "reviews", "privacy-policy",
    "terms-of-service", "thank-you", "calculator-cleaning", "lead", "sitemap.xml", "robots.txt",
    "ru", "uk", "api", "admin", "account", "connect", "login", "logout", "media", "theme-live", "bundles", "_",
}


def check_pack(engine_dir, pack_dir, reg_path, key=None):
    where = f"пак {pack_dir}"
    if not os.path.isdir(pack_dir):
        err(f"{where}: теки нема")
        return
    for f in ("site.yaml", "config/parameters.yaml", "README.md"):
        if not os.path.isfile(os.path.join(pack_dir, f)):
            err(f"{where}: нема {f}")
    meta = {}
    try:
        meta = load(os.path.join(pack_dir, "site.yaml")) or {}
    except Exception as e:  # noqa: BLE001
        err(f"{where}: site.yaml не читається ({e})")
    pkey = str(meta.get("key", ""))
    if not KEY_RE.match(pkey):
        err(f"{where}: site.yaml key некоректний")
    if key and pkey != key:
        err(f"{where}: site.yaml key='{pkey}', а в реєстрі '{key}'")
    if meta.get("format") != 1:
        err(f"{where}: site.yaml format має бути 1")
    dom = meta.get("domains") or {}
    for f in ("site", "admin"):
        if not dom.get(f) or not HOST_RE.match(str(dom[f])):
            err(f"{where}: site.yaml domains.{f} — ім'я хоста")
    if key:
        try:
            reg = next((s for s in registry_items(load(reg_path)) if str(s.get("key")) == key), None)
        except Exception:  # noqa: BLE001
            reg = None
        if reg:
            rdom = reg.get("domains") or {}
            for f in ("site", "admin"):
                if dom.get(f) and rdom.get(f) and dom[f] != rdom[f]:
                    warn(f"{where}: domains.{f} у паку '{dom[f]}' ≠ реєстр '{rdom[f]}' (стенд? хости живуть у БД, це лише довідка)")
    # параметри
    params, theme_keys, color_keys = engine_params(engine_dir)
    try:
        pdata = load(os.path.join(pack_dir, "config/parameters.yaml")) or {}
    except Exception as e:  # noqa: BLE001
        err(f"{where}: config/parameters.yaml не читається ({e})")
        pdata = {}
    pp = pdata.get("parameters") if isinstance(pdata, dict) else None
    if not isinstance(pp, dict):
        err(f"{where}: config/parameters.yaml — очікується ключ parameters:")
        pp = {}
    for k in set(pdata or {}) - {"parameters"}:
        err(f"{where}: config/parameters.yaml — зайвий верхній ключ '{k}' (лише parameters:)")
    for k, v in pp.items():
        if params and k not in params:
            err(f"{where}: невідомий рушію параметр '{k}' (дефолти — config/services.yaml)")
        if k.startswith("env(") and re.search(r"TOKEN|SECRET|PASSWORD|DSN|DATABASE|WEBHOOK", k):
            err(f"{where}: '{k}' — секрет у паку заборонено")
    scheme = pp.get("app.catalog.url_scheme")
    if scheme is not None and scheme not in ("flat", "nested", "root", "slug"):
        err(f"{where}: app.catalog.url_scheme має бути flat|nested|root|slug")
    if "app.pages" in pp and not isinstance(pp.get("app.pages"), bool):
        err(f"{where}: app.pages — true|false")
    blog = pp.get("app.blog_prefix")
    if blog is not None:
        # дзеркало перевірки в src/Kernel.php::configureRoutes і CatalogUrls::RESERVED_SEGMENTS
        if not isinstance(blog, str) or not re.fullmatch(r"[a-z0-9-]+", blog):
            err(f"{where}: app.blog_prefix — сегмент [a-z0-9-] (blog, news…), отримано {blog!r}")
        elif blog in RESERVED_SEGMENTS - {"blog", "news"}:
            err(f"{where}: app.blog_prefix '{blog}' — зарезервований сегмент іншого розділу сайту")
    crm = pp.get("app.crm")
    if crm is not None:
        if not isinstance(crm, dict):
            err(f"{where}: app.crm — мапа {{system: …}}")
        else:
            if str(crm.get("system", "auto")) not in ("auto", "none", "pipedrive", "bitrix24"):
                err(f"{where}: app.crm.system має бути auto|none|pipedrive|bitrix24")
            if re.search(r"/rest/\d+/[A-Za-z0-9]{6,}", json.dumps(crm, ensure_ascii=False)):
                err(f"{where}: app.crm — схоже на адресу вебхука Bitrix24 з токеном; це секрет, лише BITRIX24_WEBHOOK_URL у .env.local")
    theme = pp.get("app.theme") or {}
    if not isinstance(theme, dict):
        err(f"{where}: app.theme — мапа")
        theme = {}
    if theme_keys:
        for k in theme:
            if k not in theme_keys:
                err(f"{where}: app.theme.{k} — невідомий ключ теми (SiteTheme::DEFAULTS)")
        for k in (theme.get("colors") or {}):
            if color_keys and k not in color_keys:
                err(f"{where}: app.theme.colors.{k} — невідомий колір")
    if theme.get("key") and theme["key"] != pkey:
        err(f"{where}: app.theme.key='{theme['key']}' ≠ key паку '{pkey}'")
    for k, v in (theme.get("colors") or {}).items():
        if not re.match(r"^#[0-9a-fA-F]{6}$", str(v)):
            err(f"{where}: app.theme.colors.{k}='{v}' — очікується #rrggbb")
    if not theme.get("logo"):
        warn(f"{where}: app.theme.logo порожній — у шапці кожного сайту має бути логотип (правило власника 09.10.2026); додайте themes/<key>/logo.svg")
    for k in ("logo", "favicon_ico", "favicon_png", "apple_touch_icon", "hero_image", "og_image", "stylesheet"):
        v = theme.get(k)
        if v:
            if str(v).startswith("/"):
                err(f"{where}: app.theme.{k} — шлях від public/ без початкового слеша")
            elif not os.path.isfile(os.path.join(pack_dir, str(v))):
                err(f"{where}: app.theme.{k}='{v}' — файлу нема в паку")
    if theme.get("hero_srcset"):
        for part in str(theme["hero_srcset"]).split(","):
            p = part.strip().split(" ")[0]
            if p.startswith("/themes/") and not os.path.isfile(os.path.join(pack_dir, p.lstrip("/"))):
                err(f"{where}: hero_srcset '{p}' — файлу нема в паку")
    # переклади
    tdir = os.path.join(pack_dir, "translations")
    if os.path.isdir(tdir):
        for f in sorted(os.listdir(tdir)):
            if not f.endswith(".yaml"):
                continue
            if not re.match(r"^servicesite\.[a-z]{2}\.yaml$", f):
                err(f"{where}: translations/{f} — очікується servicesite.<мова>.yaml")
            try:
                load(os.path.join(tdir, f))
            except Exception as e:  # noqa: BLE001
                err(f"{where}: translations/{f} не читається ({e})")
    # свій дизайн: templates/ паку → templates/site/ рушія. Лише публічні шаблони (services/, partials/) і лише *.twig:
    # адмінку (admin/, bundles/, security/) пак не перекриває. Файл без відповідника в рушії — попередження (новий partial
    # паку — норма, але одрук у назві тихо нічого б не перекривав).
    tpl = os.path.join(pack_dir, "templates")
    if os.path.isdir(tpl):
        for dp, _dirs, files in os.walk(tpl):
            for f in files:
                rel = os.path.relpath(os.path.join(dp, f), tpl).replace(os.sep, "/")
                if rel in ("README.md", ".gitkeep"):
                    continue
                if not f.endswith(".twig"):
                    err(f"{where}: templates/{rel} — у шаблонах паку лише *.twig")
                elif not (rel.startswith("services/") or rel.startswith("partials/")):
                    err(f"{where}: templates/{rel} — пак перекриває лише services/ і partials/ (не адмінку)")
                elif engine_dir and not os.path.isfile(os.path.join(engine_dir, "templates", rel)):
                    warn(f"{where}: templates/{rel} — у рушії такого шаблону нема (новий partial паку або одрук у назві)")
    # файли: секрети, розміри
    total = 0
    for dp, dirs, files in os.walk(pack_dir):
        # local/ у корені паку — git-ignored робочі файли (дамп Joomla для app:joomla:import), у реліз не йдуть
        dirs[:] = [d for d in dirs if d != ".git" and not (dp == pack_dir and d == "local")]
        for f in files:
            p = os.path.join(dp, f)
            rel = os.path.relpath(p, pack_dir).replace(os.sep, "/")
            if SECRET_FILE_RE.match(rel) or SECRET_FILE_RE.match("/" + rel):
                err(f"{where}: {rel} — файл секретів/дампів у паку заборонено")
            size = os.path.getsize(p)
            total += size
            if size > MAX_FILE_MB * 1024 * 1024:
                err(f"{where}: {rel} — більше {MAX_FILE_MB} МБ")
            if os.path.splitext(f)[1] in TEXT_EXT and size < 2 * 1024 * 1024:
                try:
                    txt = open(p, encoding="utf-8", errors="ignore").read()
                except OSError:
                    continue
                if SECRET_TEXT_RE.search(txt):
                    err(f"{where}: {rel} — схоже на секрет (DSN/токен)")
    if total > MAX_PACK_MB * 1024 * 1024:
        err(f"{where}: пак більше {MAX_PACK_MB} МБ ({total // 1024 // 1024} МБ)")
    if pkey and not os.path.isdir(os.path.join(pack_dir, "themes", pkey)):
        warn(f"{where}: нема themes/{pkey}/ — тема лише з параметрів")


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 2
    mode = sys.argv[1]
    if mode == "registry":
        check_registry(sys.argv[2])
    elif mode == "pack":
        engine_dir, pack_dir, reg_path = sys.argv[2:5]
        key = sys.argv[5] if len(sys.argv) > 5 else None
        check_registry(reg_path)
        check_pack(engine_dir, pack_dir, reg_path, key)
    else:
        print(__doc__)
        return 2
    for w in warnings:
        print(f"!! {w}", file=sys.stderr)
    for e in errors:
        print(f"ПОМИЛКА: {e}", file=sys.stderr)
    if errors:
        return 1
    print(f"validate: OK ({mode})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
