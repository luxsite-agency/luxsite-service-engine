# Персональні API-токени менеджерів і REST адмінки (15.10.2026)

Рішення власника 15.10.2026: менеджери проєктів отримують персональний токен, як у shop-engine (`docs/admin-api.md` там), і
самі підключають сайт до content-studio, правлять контент через Claude Code чи скрипт. Токен дає рівно те, що може сам
менеджер в адмінці, і не більше: дозволи токена ∩ роль власника (`ScopeVoter`). Заявки, користувачі, сайти/міста,
медіа, налаштування (адмінка → «Налаштування сайту», лише `ROLE_ADMIN`, `docs/site-ops.md`) — не через API.

## Для менеджера

1. Адмінка (`https://<ADMIN_HOST>/admin`) → меню **API → API-токени → Створити токен**: назва («content-studio»,
   «Claude Code на ноутбуці»), дозволи, строк (до 90 днів).
2. Значення `svc_…` показується **один раз** — скопіюйте в змінну оточення чи налаштування студії, не у файл проєкту.
3. **content-studio (lingualcloud.com):** у студії додати сайт з движком `service_sites`, адреса `https://<сайт>/api/studio`,
   токен — ваш `svc_…` (потрібен дозвіл `studio:write`). Перевірка: `curl -H "Authorization: Bearer $TOKEN"
   https://<сайт>/api/studio/ping` → `{"ok":true,…}`. Спільний `STUDIO_API_TOKEN` з `.env.local` працює, як і раніше.
4. **Claude Code (MCP):** один раз на ноутбуці
   ```bash
   export SVC_TOKEN='svc_…'
   claude mcp add --transport http service https://<ADMIN_HOST>/mcp --header "Authorization: Bearer ${SVC_TOKEN}"
   ```
   далі в Claude Code: «подивись послуги example», «онови лід послуги 12» — інструменти `me`, `list`, `get`, `update`,
   `upsert_city_texts` (ті самі дозволи, що в REST). Сервер без сесій і SSE: кожен запит самодостатній.
5. **Скрипт (REST):**
   ```bash
   export SVC_TOKEN='svc_…'
   curl -sS -H "Authorization: Bearer $SVC_TOKEN" https://<ADMIN_HOST>/api/admin/v1/me          # хто я, дозволи, сайти й міста
   curl -sS -H "Authorization: Bearer $SVC_TOKEN" https://<ADMIN_HOST>/api/admin/v1/example/items?q=унітаз
   curl -sS -X PATCH -H "Authorization: Bearer $SVC_TOKEN" -H 'Content-Type: application/json' \
        -d '{"lead_uk":"Новий лід","meta_title_uk":"…"}' https://<ADMIN_HOST>/api/admin/v1/example/items/12
   ```
6. Строк минув — створіть новий токен, старий відкликайте. Загубили — відкликайте одразу (рядок → «Відкликати»).
   У списку видно лише свої токени: префікс, дозволи, стан, останній запит і адреса (анонімізована).

## Для адміністратора

- Той самий список показує токени **всіх** користувачів з колонкою «Власник»; відкликати можна будь-який.
- Деактивація користувача (Користувачі → Активний = ні) відкликає всі його токени; чинний токен вимкненого користувача
  й так не проходить (`ActiveUserChecker`).
- Події — у логах каналу `app` (`api_token.created|revoked|auth_failed|new_ip`, `admin_api.update|create` з полями, без
  значень токенів і без текстів).

## Дозволи (скоупи)

| Скоуп | Що дає | Потрібна роль |
|---|---|---|
| `content:read` | `GET` списків і записів: послуги, категорії, райони, пости, прайс, тексти по містах | ROLE_MODERATOR |
| `content:write` | `PATCH` текстів, SEO, FAQ, активності; `POST` текстів послуги/категорії для міста | ROLE_MODERATOR |
| `prices:write` | `PATCH` прайсу (разом із `content:write`) | ROLE_MODERATOR |
| `studio:write` | приймач content-studio `/api/studio` на хостах сайту (статті в блог) | ROLE_MODERATOR |
| `theme:write` | живий дизайн: шаблони `services/**`, `partials/**` і `theme.css` сайту (`/theme`, MCP `theme_*`) | ROLE_MODERATOR |

У коді: `#[IsGranted('scope:content:write')]` на дії → `ScopeVoter` (скоуп у токені **і** роль власника через `role_hierarchy`).

## MCP `/mcp` (лише на `ADMIN_HOST`, той самий firewall)

JSON-RPC 2.0 поверх POST (Streamable HTTP без потоку подій: GET → 405). Методи `initialize`, `ping`, `tools/list`,
`tools/call`; інструменти — обгортка `ContentService` (спільне ядро з REST): `me`, `list(site, kind, q|city|category|item)`,
`get(site, kind, id)`, `update(site, kind, id, fields)`, `upsert_city_texts(site, kind, item_id|category_id, city_id, fields)`.
Помилки операції — `isError` з текстом (403 без дозволу, 404, 422 з переліком дозволених полів); «нічого не змінено» — не помилка.

## Дизайн під токеном (`theme:write`, 15.10.2026)

Власник: «варто додати можливість правити шаблон сайту з-під токена; ризики є, але мінімальні». Живі файли — у
`<root>/shared/theme` сайту (переживають релізи): `templates/**` Twig бере **першими** (перед `templates/` паку і рушія),
`public/theme.css` підключається після стилів рушія й паку як `/theme-live/theme.css?v=`. Запобіжники: лише `services/**`,
`partials/**` (*.twig) і `theme.css`; синтаксис Twig перевіряється до запису; після запису рендеряться головна, каталог,
райони, FAQ, блог, перша послуга й район через HTTP — 5xx чи обірваний HTML → файл повертається, відповідь 422;
кожна попередня версія — в `history/` (до 20), `revert`; компільований кеш скидається одразу. У git паку ці правки не
потрапляють — розробник переносить у `templates/` паку (`theme_get` віддає поточний текст). Відповідальність за
зовнішній вигляд — на менеджері; після правок на проді — `/analytics-healthcheck`.

| Метод і шлях | Що |
|---|---|
| `GET /{site}/theme` | перелік шаблонів рушія з позначкою `engine|pack|live`, `theme.css`, кількість версій |
| `GET /{site}/theme/{path}` | поточний текст (звідки — `source`) і версії в історії |
| `PUT /{site}/theme/{path}` | тіло — текст файлу (`text/plain`) або JSON `{"content": …}` |
| `DELETE /{site}/theme/{path}` | прибрати живе перекриття |
| `POST /{site}/theme/{path}/revert` | `{"version": "YYYYMMDD-HHMMSS"}` |

MCP: `theme_list`, `theme_get`, `theme_put`, `theme_delete`, `theme_revert`. Порада Claude Code: extends `@engine/…`
з перевизначенням блоків, а не повна копія — виправлення рушія доходитимуть у неперекриті блоки.

## REST `/api/admin/v1` (лише на `ADMIN_HOST`, firewall `admin_api`, stateless)

| Метод і шлях | Що |
|---|---|
| `GET /me` | користувач, токен (без значення), дозволи, активні сайти з містами і `studio_api_url` |
| `GET /{site}/{kind}` | список; `kind` = `items`, `categories`, `districts`, `posts`, `prices`, `item-texts`, `category-texts`. Фільтри: `?q=` (назва/слаг), `?category=<id>` (items), `?city=<slug>` (districts, prices, тексти), `?item=<id>` (prices) |
| `GET /{site}/{kind}/{id}` | запис з усіма дозволеними полями |
| `PATCH /{site}/{kind}/{id}` | JSON `{поле: значення}` лише з дозволених полів (перелік у 422 на невідоме поле); FAQ — `[{"q":"…","a":"…"}]`; відповідь — запис після змін + `changed` |
| `POST /{site}/item-texts` | `{item_id, city_id, …поля}` — створити/оновити тексти послуги для міста (upsert) |
| `POST /{site}/category-texts` | `{category_id, city_id, …поля}` |

`{site}` — slug сайту з `/me`. Зміни пишуться одразу (як збереження в адмінці); slug, категорія послуги, картинки, порядок
послуг і міста (де надається, де ціни) — лише в адмінці.

Відповіді за RFC 6750: 401 `invalid_token` (`WWW-Authenticate: Bearer realm="service-admin-api", error=…`), 403
`insufficient_scope` зі `scope=`, 429 `too_many_requests` + `Retry-After` (понад `app.admin_api_auth_rate_limit` невдалих
автентифікацій з IP за хвилину), 404 на хостах сайтів (`/api/admin` там нема — `AdminHostSubscriber`).

## Параметри (`config/services.yaml`, пак — `config/parameters.yaml`)

```yaml
app.admin_api_token_max_days: 90      # найдовший строк токена
app.admin_api_auth_rate_limit: 30     # невдалих автентифікацій з IP за хвилину
```

## Експлуатація

- Таблиця `admin_api_token` (міграція `Version20261015100000`): `user_id` FK CASCADE, `token_hash` unique (sha256),
  `prefix` (12 символів), `scopes` JSON, `expires_at`, `revoked_at`, `revoked_by_id`, `last_used_at`, `last_used_ip`
  (анонімізований; оновлюється не частіше ніж раз на хвилину), `created_at`.
- Токен у логах не буває: лише префікс і причина. Значення після створення живе лише в сесії адмінки до першого показу.
- Після оновлення сайту до тега з цією функцією: міграція йде в `bin/site deploy`; у паку нічого не міняти.
