#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
"""Лічильник коментарів розробника у HTML сторінки (bin/site doctor, пункт «коментарі у HTML: 0», вимога власника 25.10.2026).

  _htmlcomments.py <url|файл> [<url|файл>…]   — кожна сторінка окремо
  _htmlcomments.py --site https://host/       — головна + одна послуга з sitemap.xml (перша адреса /services/<послуга>/, інакше
                                                 перша не-головна з двома сегментами)

Рахує поза <script type="application/ld+json">: `<!--` (крім умовних `<!--[if`/`<!--<![endif]`), `/*`, рядки `^\\s*//`.
Вивід: `page=<адреса> comments=N html=N block=N line=N` на сторінку і `total=N`. Код виходу 0 — порахувано (навіть якщо > 0),
2 — сторінку не отримано. Лише stdlib: працює й на операторській машині без PyYAML.
"""
import re
import sys
import urllib.request
import xml.etree.ElementTree as ET

UA = "bin/site doctor (html comments check)"
LD = re.compile(r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>.*?</script>', re.I | re.S)


def fetch(src: str) -> str:
    if not re.match(r'^https?://', src):
        with open(src, encoding='utf-8', errors='replace') as f:
            return f.read()
    req = urllib.request.Request(src, headers={'User-Agent': UA, 'Accept': 'text/html,application/xml'})
    with urllib.request.urlopen(req, timeout=25) as r:
        return r.read().decode('utf-8', errors='replace')


def count(html: str) -> dict:
    body = LD.sub('', html)
    return {
        'html': len(re.findall(r'<!--(?!\[if|<!\[endif)', body)),
        'block': len(re.findall(r'/\*', body)),
        'line': len(re.findall(r'^\s*//', body, re.M)),
    }


def service_url(site: str) -> str | None:
    try:
        xml = fetch(site.rstrip('/') + '/sitemap.xml')
        locs = [e.text.strip() for e in ET.fromstring(xml.encode()).iter() if e.tag.endswith('loc') and e.text]
    except Exception:  # noqa: BLE001 — нема sitemap → перевіряємо лише головну
        return None
    for loc in locs:
        if re.search(r'/services/[^/]+/', loc):  # сторінка послуги (flat/nested), не каталог /services/
            return loc
    for loc in locs:
        path = re.sub(r'^https?://[^/]+', '', loc).strip('/')
        if path.count('/') >= 1 and not path.startswith('ru/'):
            return loc
    return None


def main(argv: list[str]) -> int:
    if not argv or argv[0] in ('-h', '--help'):
        print(__doc__.strip())
        return 0
    pages = argv
    if argv[0] == '--site':
        site = argv[1].rstrip('/') + '/'
        pages = [site]
        svc = service_url(site)
        if svc:
            pages.append(svc)
    total = 0
    rc = 0
    for page in pages:
        try:
            c = count(fetch(page))
        except Exception as e:  # noqa: BLE001
            print(f'page={page} error={e.__class__.__name__}: {e}')
            rc = 2
            continue
        n = sum(c.values())
        total += n
        print(f"page={page} comments={n} html={c['html']} block={c['block']} line={c['line']}")
    print(f'total={total}')
    return rc


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
