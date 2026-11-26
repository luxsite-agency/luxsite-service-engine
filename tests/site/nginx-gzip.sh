#!/usr/bin/env bash
# shellcheck disable=SC2317  # функції викликаються через trap і check "$@"
# gzip у nginx стенда (docker/nginx/traefik.conf): справжній nginx:1.27-alpine з цим конфігом поверх тимчасового public/.
# Перевіряє дію, а не рядок у конфігу: текстові відповіді з Accept-Encoding: gzip стиснуті (і розпаковуються в оригінал),
# без заголовка — ні; картинки не стискаються. Запуск: bash tests/site/nginx-gzip.sh (потрібен docker); код 0 = усе гаразд.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
conf="${1:-docker/nginx/traefik.conf}"
name="engine-gzip-test-$$"
root="$(mktemp -d)"
cleanup() { docker rm -f "$name" >/dev/null 2>&1; rm -rf "$root"; }
trap cleanup EXIT
mkdir -p "$root/public/media" "$root/public/themes/t"
chmod 755 "$root" "$root/public" "$root/public/media" "$root/public/themes" "$root/public/themes/t"
{ echo '<!DOCTYPE html><html><body>'; for i in $(seq 1 400); do echo "<p>Рядок $i головної сторінки стенда</p>"; done; echo '</body></html>'; } > "$root/public/page.html"
for i in $(seq 1 300); do echo ".c$i{color:#2f4a3a;margin:0 auto}"; done > "$root/public/themes/t/theme.css"
for i in $(seq 1 300); do echo "window.dataLayer.push({event:'e$i'});"; done > "$root/public/themes/t/app.js"
{ printf '{"items":['; for i in $(seq 1 300); do printf '{"id":%d,"name":"item"},' "$i"; done; printf '{}]}'; } > "$root/public/data.json"
{ echo '<svg xmlns="http://www.w3.org/2000/svg">'; for i in $(seq 1 200); do echo "<rect x=\"$i\" y=\"$i\" width=\"10\" height=\"10\"/>"; done; echo '</svg>'; } > "$root/public/themes/t/logo.svg"
head -c 20000 /dev/urandom > "$root/public/media/photo.jpg"
echo 'tiny' > "$root/public/tiny.txt"
chmod -R a+rX "$root/public"

docker run -d --name "$name" --network none --add-host php:127.0.0.1 \
    -v "$PWD/$conf:/etc/nginx/conf.d/default.conf:ro" -v "$root/public:/app/public:ro" nginx:1.27-alpine >/dev/null || { echo "FAIL nginx не стартував"; exit 1; }
for _ in $(seq 1 20); do docker exec "$name" wget -q -O /dev/null http://127.0.0.1/tiny.txt 2>/dev/null && break; sleep 0.3; done

fail=0
# req <шлях> <gzip|plain> → заголовки відповіді у $HEAD, тіло у контейнері /tmp/body
req() {
    local hdr=(); [ "$2" = gzip ] && hdr=(--header 'Accept-Encoding: gzip')
    HEAD="$(docker exec "$name" wget -q -S -O /tmp/body "${hdr[@]}" "http://127.0.0.1$1" 2>&1)"
}
check() { # check <опис> <команда…>
    local what="$1"; shift
    if "$@"; then echo "ok   $what"; else fail=$((fail + 1)); echo "FAIL $what"; printf '     | %s\n' "${HEAD//$'\n'/$'\n'     | }"; fi
}
encoded() { grep -qi '^ *Content-Encoding: gzip' <<<"$HEAD"; }
not_encoded() { ! grep -qi '^ *Content-Encoding:' <<<"$HEAD"; }
same_as() { docker exec "$name" sh -c "gunzip -c /tmp/body" | cmp -s - "$1"; }

for path in /page.html /themes/t/theme.css /themes/t/app.js /data.json /themes/t/logo.svg; do
    req "$path" gzip
    check "gzip: $path з Accept-Encoding → Content-Encoding: gzip" encoded
    check "gzip: $path розпаковується в оригінал" same_as "$root/public$path"
    check "gzip: $path має Vary: Accept-Encoding" grep -qi '^ *Vary:.*Accept-Encoding' <<<"$HEAD"
    req "$path" plain
    check "gzip: $path без Accept-Encoding → без стиснення" not_encoded
done
req /media/photo.jpg gzip
check "gzip: картинка не стискається" not_encoded
check "gzip: /media/ лишає Cache-Control immutable" grep -qi '^ *Cache-Control: public, immutable' <<<"$HEAD"
req /tiny.txt gzip
check "gzip: відповідь, менша за поріг, не стискається" not_encoded

[ "$fail" = 0 ] && echo "nginx-gzip: OK" || echo "nginx-gzip: FAIL $fail"
exit "$fail"
