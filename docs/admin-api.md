# Персональні API-токени менеджерів і REST адмінки (15.10.2026)

## Швидкий старт для агента й менеджера (23.10.2026)

**Головне: API живе на двох різних хостах.** REST і MCP — лише на хості адмінки (`ADMIN_HOST`, напр. `admin.example.com`),
приймач content-studio — лише на доменах сайту (по одному на місто). На «чужому» хості відповідь — 404 `wrong_host` з
правильною адресою. Готові адреси свого сайту — адмінка → **API → API-токени** → «Як підключити» (блоки з кнопкою
«Копіювати»), а також `GET /me` (поле `endpoints`).

| Що | Хост | Адреса | Дозвіл |
|---|---|---|---|
| Хто я, адреси, що можна цим токеном | адмінки | `GET https://<ADMIN_HOST>/api/admin/v1/me` | будь-який чинний токен |
| Тексти, SEO, FAQ, активність послуг, категорій, районів, постів | адмінки | `GET`/`PATCH https://<ADMIN_HOST>/api/admin/v1/<site>/<kind>/<id>` | `content:read` / `content:write` |
| Назви розділів (категорій) | адмінки | `PATCH …/<site>/categories/<id>` `{"title_uk":"…","title_ru":"…"}` | `content:write` |
| Тексти послуги чи категорії для одного міста | адмінки | `POST …/<site>/item-texts`, `…/category-texts` | `content:write` |
| Ціни | адмінки | `PATCH …/<site>/prices/<id>` | `content:write` + `prices:write` |
| Кольори, шрифти, відступи | адмінки | `PUT …/<site>/theme/theme.css` | `theme:write` |
| Розмітка блоків (шаблони `services/**`, `partials/**`) | адмінки | `GET`/`PUT …/<site>/theme/<path>` | `theme:write` |
| Claude Code (усе вище інструментами) | адмінки | `https://<ADMIN_HOST>/mcp` | ті самі |
| Статті в блог з content-studio | **кожного міста** | `https://<домен міста>/api/studio` (`/ping`, `PUT /articles/<id>`) | `studio:write` |
| Фото, порядок, міста, slug, налаштування, заявки | — | **лише адмінка** (нижче «Що не через API») | — |

`<site>` — slug сайту з `/me` (`sites[].slug`); `<kind>` — `items`, `categories`, `districts`, `posts`, `prices`,
`item-texts`, `category-texts`. Дозвіл діє, лише коли він є в токені **і** у власника токена є роль `ROLE_MODERATOR`.

### Чекліст для Claude Code та інших агентів

1. **Спершу `GET /me`** (MCP: інструмент `me`). Адреси бери з `endpoints` (`admin_api_url`, `mcp_url`,
   `sites[].site_api_url`, `sites[].studio[].studio_api_url`), не вгадуй і не перебирай шляхи на домені сайту.
2. Що можна цим токеном — `capabilities`: `scopes` (діючі дозволи), `write.<kind>` (поля для `PATCH`/`update`),
   `theme.paths`, `studio.allowed`, `inactive_scopes` (дозвіл є в токені, але ролі бракує), `admin_only`, `recipes`.
3. Поле, якого нема у `write.<kind>`, через API не змінюється — не шукай іншу адресу. Картинки й фото — лише адмінка.
4. 404 `wrong_host` — повтори запит на `correct_url` (той самий токен). 401/403 — не перебирай адреси, читай розділ
   «Типові помилки».
5. Дизайн: `theme_list` → `theme_get` → `theme_put`; extends `@engine/…` з перевизначенням блоків; лого й перемикач мов зберігати.
6. Після правок — відкрий живу сторінку й перевір результат. Живий дизайн не потрапляє в git паку: перед запуском сайту
   попроси розробника перенести його в пак.

### Типові помилки

| Відповідь | Що означає | Що робити |
|---|---|---|
| **404** `{"error":"wrong_host", "correct_url": …}` | запит пішов не на той хост: REST/MCP на домен сайту або `/api/studio` на хост адмінки | повторити на `correct_url`; адреси — `GET /me` → `endpoints` |
| **404** `{"error":"Сайт не знайдено: …"}` / `"… #12: нема в сайті …"` | неправильний slug сайту чи id запису | slug — з `/me`, id — з `GET …/<site>/<kind>` |
| **401** `unauthorized` / `invalid_token` | токена нема, він відкликаний, строк минув або власника вимкнено | створити новий токен в адмінці; заголовок — `Authorization: Bearer svc_…` |
| **403** `insufficient_scope` (`scope=…`) / «Токену бракує дозволу … або власнику — ролі» | у токені нема потрібного дозволу або в людини нема ролі | новий токен з потрібним дозволом; роль видає адміністратор |
| **422** «Невідомі або заборонені поля: … Дозволені: …» | поле не можна змінювати через API (часто — картинка, slug, порядок) | лише поля з переліку; решта — в адмінці |
| **422** «Шаблон не пройшов перевірку живої теми: …» / «Сторінка не рендериться після зміни, файл повернуто: …» | живий дизайн відхилено стражем (рядок у тексті) або рендер дав 5xx — файл повернуто | виправити рядок (нижче «Що можна в живому шаблоні») і повторити |
| **422** «Дозволені лише services/\*\*.twig, partials/\*\*.twig і theme.css» | шлях дизайну поза дозволеними | лише шляхи з `capabilities.theme.paths` |
| **409** + `Retry-After: 5` | живу тему зараз змінює інший запит | повторити за кілька секунд |
| **429** `too_many_requests` + `Retry-After` | забагато невдалих спроб входу з цієї адреси | зачекати `Retry-After` секунд, перевірити токен |
| **200** «Нічого не змінено» | значення збігаються з поточними | не помилка |

### Що не через API

Лише в адмінці (`https://<ADMIN_HOST>/admin`): **картинки й фото** (завантаження, заміна, обкладинки; через API — тільки
підпис `image_alt`), **порядок** послуг і категорій, **міста й домени**, де послуга надається й де показуємо ціну,
**slug** (адреси сторінок), категорія послуги, створення й видалення послуг, категорій, районів, **відгуки**, **команда**,
«Наші роботи», калькулятор, **користувачі** й ролі, **налаштування сайту** (GTM, GA4, Ads, вхід через Google, коди), **заявки**.
Новий пост у блог через API — лише приймачем студії (`/api/studio`). Той самий перелік — `GET /me` → `capabilities.admin_only`.

## Історія й модель доступу

Рішення власника 15.10.2026: менеджери проєктів отримують персональний токен, як у shop-engine (`docs/admin-api.md` там), і
самі підключають сайт до content-studio, правлять контент через Claude Code чи скрипт. Токен дає рівно те, що може сам
менеджер в адмінці, і не більше: дозволи токена ∩ роль власника (`ScopeVoter`). Заявки, користувачі, сайти/міста,
медіа, налаштування (адмінка → «Налаштування сайту», лише `ROLE_ADMIN`, `docs/site-ops.md`) — не через API.

## Для менеджера

1. Адмінка (`https://<ADMIN_HOST>/admin`) → меню **API → API-токени → Створити токен**: назва («content-studio»,
   «Claude Code на ноутбуці»), дозволи, строк (до 90 днів).
2. Значення `svc_…` показується **один раз** — скопіюйте в змінну оточення чи налаштування студії, не у файл проєкту.
   Під списком токенів — «Як підключити»: справжні адреси цього сайту й готові команди (з плейсхолдером `$SVC_TOKEN`).
3. **content-studio (lingualcloud.com):** у студії додати сайт з движком `service_sites`, адреса `https://<домен міста>/api/studio`
   (домен сайту, **не** адмінки; для кожного міста — своя, список — «Як підключити» або `GET /me`), токен — ваш `svc_…`
   (потрібен дозвіл `studio:write`). Перевірка: `curl -H "Authorization: Bearer $SVC_TOKEN" https://<домен міста>/api/studio/ping`
   → `{"ok":true,…}`. Спільний `STUDIO_API_TOKEN` з `.env.local` працює, як і раніше.
4. **Claude Code (MCP):** один раз на ноутбуці
   ```bash
   export SVC_TOKEN='svc_…'
   claude mcp add --transport http service https://<ADMIN_HOST>/mcp --header "Authorization: Bearer ${SVC_TOKEN}"
   ```
   PowerShell: `$env:SVC_TOKEN = 'svc_…'`, у команді — `"Authorization: Bearer $env:SVC_TOKEN"`. Далі в Claude Code:
   «подивись послуги example», «онови лід послуги 12» — інструменти `me`, `list`, `get`, `update`, `upsert_city_texts`,
   `theme_*` (ті самі дозволи, що в REST). Сервер без сесій і SSE: кожен запит самодостатній. Першим агент кличе `me`:
   опис інструментів і `instructions` сервера кажуть йому, що можна, а що лише в адмінці.
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
`initialize` віддає `instructions` (спершу `me`; що лише в адмінці; назви категорій — `update kind=categories`, кольори —
`theme_put path=theme.css`), описи `me`, `update`, `theme_list` повторюють це коротко. На домені сайту `/mcp` відповідає HTTP 404
з JSON-RPC помилкою `-32001` (`message` починається з `wrong_host:` і містить правильну адресу, `error.data.mcp_url`).

## Дизайн під токеном (`theme:write`, 15.10.2026)

Власник: «варто додати можливість правити шаблон сайту з-під токена; ризики є, але мінімальні». Живі файли — у
`<root>/shared/theme` сайту (переживають релізи): `templates/**` Twig бере **першими** (перед `templates/` паку і рушія),
`public/theme.css` підключається після стилів рушія й паку як `/theme-live/theme.css?v=`. Запобіжники: лише `services/**`,
`partials/**` (*.twig) і `theme.css`; шаблон до запису проходить страж (синтаксис і allowlist, нижче) — інакше 422 з
переліком рядків; після запису рендеряться головна, каталог, райони, FAQ, блог, перша послуга й район через HTTP — 5xx чи
обірваний HTML → файл повертається, відповідь 422; кожна попередня версія — в `history/` (до 20), `revert` (з тим самим
стражем: стара версія із забороненим — 422); компільований кеш скидається одразу. У git паку ці правки не
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

### Що можна в живому шаблоні (allowlist, з 22.10.2026)

Порт виправлень shop-engine PR #108 (рев'ю M1–M3). До того страж перевіряв лише синтаксис, і живий шаблон міг вивести
`{{ app.request.server.get('DATABASE_URL') }}` — `$_SERVER` з усім оточенням (`APP_SECRET`, ключі) — на публічній сторінці,
або викликати `{{ render(controller('App\\…::метод')) }}`: sub-request не проходить firewall (той працює лише для main
request), тож будь-який контролер ішов би без входу. Тепер `ThemeTemplateGuard` іде по AST і дозволяє лише те, що є в
`src/AdminApi/LiveThemePolicy.php`; невідоме ім'я = відмова:

- теги, фільтри, функції, тести — зі списків; `render`, `render_*`, `controller`, `include_only`, `attribute`, `source`,
  `dump`, `|invoke`, тест `constant` — нема; `constant()` — лише `JSON_*`; `filter`/`map`/`reduce`/`sort`/`find` і оператори
  `has some`/`has every` — лише зі стрілочною функцією (не рядок `'system'`);
- `app` — лише точні ланцюжки: `app.request.locale|pathInfo|uri|requestUri|host|schemeAndHttpHost`,
  `app.request.query.get|has|all`, `app.user` (далі — властивості зі списку, напр. `email`, `displayName`), `app.flashes`,
  `app.current_route`, `app.locale`; саме `app.request` — лише як умова (`{% if app.request %}`, `is null`, `not`, `and/or`).
  `app` чи `app.request` у `set`, аргументі, `with`, `app.session`, `app.token`, `app.request.server|headers|cookies|attributes`
  — відмова; `_context`, `_parent` і `_self` (крім `{% import _self %}`) — відмова; `loop` — лише `loop.index|index0|revindex|
  revindex0|first|last|length` (`loop.parent` — весь контекст); присвоєння у виразі (`x = …`, деструктуризація `{key: v} = obj`,
  `[a, b] = …`) — відмова: вона читає властивість об'єкта за ключем повз список (`{request: r} = loop['parent']['app']`);
- властивості й методи `x.name` — лише імена зі списку (немає `parent`, `token`, `request`, `session`, `password`, `roles`,
  `settings`, сетерів); ключ масиву — `x['token']` (на об'єкті Twig так методу не викличе);
- `extends`/`include`/`embed`/`import`/`use`/`block(name, template)` — лише літерал `services/**`, `partials/**` або те саме
  з `@engine/` — не адмінка, не листи, не шаблони бандлів, не вираз.

Шаблони рушія (`templates/services/**`, `templates/partials/**`) проходять страж як є — це перевіряє тест
`ThemeTemplateGuardTest::testEveryOverridableEngineTemplatePasses`: нова функція чи властивість у шаблоні рушія = свідомо
додати ім'я в `LiveThemePolicy` у тому ж PR. Шаблони паків стражем не перевіряються (вони йдуть через git і рев'ю); дизайнер,
який бере шаблон паку за основу, може отримати 422 з точним рядком (найчастіше `app.request.attributes.get('_route')` →
`app.current_route`, `x.key` масиву → `x['key']`).

**Замок (M3).** `put`/`delete`/`revert` — під одним замком на живу тему сайту (`symfony/lock`, `FlockStore` у `var/theme` =
`shared/theme`, ключ `theme-live`): без нього два PUT паралельно знімали той самий `previous`, і провал першого відкочував
успішний другий. Другий запис чекає до `app.theme_lock_wait` с (30), далі **409** + `Retry-After: 5`, файл не змінено.
Загальний `framework.lock` вимкнено (`lock: false`) — rate limiter-и працюють як раніше.

**Прийняті ризики (як у shop-engine, ADR п. 9).** Менеджер з `theme:write` (або той, хто вкрав токен) керує HTML/JS/CSS
сайту: фішинг-форма чи скрипт, що читає введене, страж не ловить — пом'якшення: `ROLE_MODERATOR`, строк токена, журнал,
історія. Методи об'єктів контексту з allowlist-у (геттери сутностей) доступні шаблону. Між записом і відкатом після провалу
рендера (кілька секунд) відвідувач може побачити зламану сторінку. `theme.css` не перевіряється (може `@import` чужий URL).

## REST `/api/admin/v1` (лише на `ADMIN_HOST`, firewall `admin_api`, stateless)

| Метод і шлях | Що |
|---|---|
| `GET /me` | користувач, токен (без значення), дозволи, активні сайти з містами і `studio_api_url` (апекс; з 23.10 — і в кожного міста, плюс `site_api_url`); `endpoints` — адреси REST, MCP, студії по містах; `capabilities` — діючі дозволи, поля для запису, дизайн, студія, «лише в адмінці», рецепти (нові ключі лише додаються) |
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

**Підказки `wrong_host` (з 23.10.2026, `AdminHostSubscriber` до firewall — токен не потрібен).** На домені сайту
`/api/admin/*` → 404 JSON `{"error":"wrong_host","error_description":…,"correct_url":"https://<ADMIN_HOST>/<той самий шлях>",
"admin_api_url","me_url","mcp_url","docs_url"}`; `/mcp` → JSON-RPC помилка (вище). На хості адмінки `/api/studio/*` → 404 JSON
`wrong_host` з `site`, `studio_api_urls` (місто, хост, адреса — лише сайт цієї адмінки: апекс якого — суфікс `ADMIN_HOST`, або
єдиний у БД; чужі сайти мультисайту не перелічуються; не визначився — список порожній, адреси — у `/me`) і `correct_url`.
Хост адмінки публічний і так; query-рядок у `correct_url` не повертається; `X-Robots-Tag: noindex`. `/admin` і `/login` на
хостах сайту — як і раніше голий 404 без адреси адмінки. Статуси на правильних хостах не змінились (401 без токена).
422 на поле картинки, slug, порядку чи міста додає «… змінюються лише в адмінці».

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
