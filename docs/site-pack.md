# Пак сайту і реєстр сайтів (чернетка 10.10.2026)

Схема — дзеркало «Системи магазинів» shop-engine (`docs/store-pack.md` там): один рушій `service-engine`
з версіями-тегами, кожен сайт — окремий **пак** (свій приватний репо `site-<key>`), усі сайти — у
**реєстрі** `sites.yaml`, який живе поза рушієм і передається через `SITES_FILE` (приклад — `sites.example.yaml`). Команди `bin/site` (порт `bin/store`) — `docs/site-ops.md`: `new`, `validate`,
`deploy`, `rollback`, `cutover`, `backup`, `restore`, `archive`, `doctor`. Шаблон нового паку — `site-template/`.

## Реєстр `sites.yaml`

Поля й правила — у коментарі на початку `sites.example.yaml`. Секретів у реєстрі нема. Справжній реєстр — поза рушієм
(приватний ops-репо або файл на операторській машині): `SITES_FILE=/path/sites.yaml bin/site …`; типовий шлях `<рушій>/sites.yaml`
у `.gitignore`. Приклад містить три типові записи: сайт на FastPanel (`nested`), прод у Docker за edge і стенд за traefik.

## Структура паку

```
site-<key>/
├── site.yaml                       # format, key, name, locales, domains, engine — без сервера/статусу (це реєстр)
├── config/parameters.yaml          # → config/site/parameters.local.yaml рушія (без секретів)
├── themes/<key>/                   # → public/themes/<key>/ рушія: лого, фавікони, хіро, og, theme.css
├── translations/servicesite.*.yaml # → translations/site/ рушія: бренд-тексти, що перекривають дефолти
├── templates/                      # → templates/site/ рушія: свій дизайн (шаблони замість однойменних у рушії), необов'язково
├── content/                        # початкові тексти (для нового сайту; на проді живуть у БД)
├── seo/                            # семантика, meta, технічні зауваження
├── studio/                         # промпти content-studio (без токенів)
└── docs/brief.md, docs/CLAUDE.md
```

Клієнтського PHP у паку нема: потрібна функція — PR у рушій (як у shop-engine, п. 8 консиліуму 06.10). Свій дизайн —
є: Twig-шаблони в `templates/` паку (розділ «Свій дизайн»), без PHP.

## Як рушій підхоплює пак

| Що в паку | Куди в рушії (поза git) | Механізм |
|---|---|---|
| `config/parameters.yaml` | `config/site/parameters.local.yaml` | `src/Kernel.php::configureContainer` імпортує файл після `services.yaml` (ігнорує відсутність; у `test` не вантажиться). Дефолти всіх ключів — у `config/services.yaml`, зразок — `config/site/parameters.local.yaml.dist`. |
| `themes/<key>/*` | `public/themes/<key>/` | `App\ServiceSite\SiteTheme` (`app.theme`, лише відмінні ключі; невідомий ключ — виняток). Twig-глобал `theme`: `theme.url('logo')`, `theme.cssVars()`, `theme.get('key')` → клас `body.theme-<key>`, `stylesheet` — додатковий CSS. |
| `translations/servicesite.{uk,ru}.yaml` | `translations/site/` | `framework.translator.paths` (`config/packages/translation.yaml`). Файли вантажаться в порядку повного шляху: `servicesite.*` < `site/…`, тож ключі паку перекривають дефолти рушія. **Теку не перейменовувати**: ім'я має сортуватися після `servicesite`. Порожнє значення ключа = елемент не показується (слоган, переваги, футерний рядок міст, `about.legal`, напрями робіт району). |
| `templates/**` | `templates/site/` | `twig.paths` (`config/packages/twig.yaml`): `templates/site` стоїть перед `templates`, тож однойменний шаблон паку перекриває шаблон рушія; рушій доступний ще й як `@engine/…` для `extends`. На сервері перед smoke — `lint:twig templates/site`. |
| секрети | `.env.local` на сервері | `DATABASE_URL`, `MAILER_DSN`, `MAIL_FROM`, `ADMIN_HOST`, `DEFAULT_URI`, `STUDIO_API_TOKEN`, `GOOGLE_CLIENT_*`, `PIPEDRIVE_API_TOKEN`, `BITRIX24_WEBHOOK_URL`, `LEAD_RATE_LIMIT_ALLOWLIST` — у пак не потрапляють. |

Відгуки на сайті залишають через вхід Google: без `GOOGLE_CLIENT_ID` і `GOOGLE_CLIENT_SECRET` у `.env.local` блок «Залишити відгук» на `/reviews/` не показується (кнопки й технічних повідомлень відвідувач не бачить), попередження — на дашборді адмінки, у «Відгуках клієнтів» і в лозі (`/connect/google`). Свій шаблон відгуків у паку — з тією ж умовою: `{% if app.user or google_oauth.configured %}`.

### Свій дизайн (шаблони паку), 11.10.2026

Рішення власника 11.10.2026: будь-який сайт може ввімкнути власний дизайн, відмінний від типового. Три рівні, від дешевого:

1. **Кольори, лого, картинки** — `app.theme` у `config/parameters.yaml` + файли в `themes/<key>/` (як було).
2. **CSS поверх типової верстки** — `app.theme.stylesheet: themes/<key>/theme.css` + клас `body.theme-<key>` (як було).
3. **Своя верстка** — `templates/` у паку: файл з тим самим шляхом, що в рушії (`services/home.html.twig`,
   `services/base.html.twig`, `services/catalog/item.html.twig`, `partials/_img.html.twig`…), перекриває шаблон рушія.
   Частково: `{% extends '@engine/services/home.html.twig' %}` і свої блоки; повністю: свій файл без `extends`
   (змінні контролера ті самі — список у шаблоні рушія). Власні partial-и паку — у `partials/`.

**Команда:** `bin/site theme new <key>` створює тему в паку — `themes/<key>/theme.css` (якщо нема) і заготовки
`templates/services/base.html.twig`, `templates/services/home.html.twig` як `{% extends '@engine/…' %}` з усіма блоками
= `parent()` (інші шаблони — аргументами, перелік і блоки — `bin/site theme list`); `--copy <шаблон>` — повна копія для
сторінки, де дизайн справді інший. Після оновлення рушія `bin/site theme diff <key>` показує, які перекриті файли й блоки
змінились у рушії з версії `engine:` у `site.yaml` паку (extends-заготовки підхоплюють неперекриті блоки самі, повну копію
зливати руками). Модель спільна з shop-engine (`bin/store theme`, 11.10.2026); заборонена зона для теми — адмінка, schema,
analytics, листи (вони в рушії, не в шаблонах паку).

Четвертий рівень — **живий дизайн під токеном менеджера** (`docs/admin-api.md` «Дизайн»): `shared/theme/templates`
сайту Twig бере ще раніше за `templates/` паку; `shared/theme/public/theme.css` — після стилів паку. Пріоритет:
live (токен) > пак > рушій.

**Точки перекриття (13.10.2026, сайт зі схемою root; за замовчуванням усе як було):**
- **Шапка** — `{% block header %}` у `services/base.html.twig` (топбар, бренд, меню, перемикач мов; `block nav` усередині
  лишається): `{% extends '@engine/services/base.html.twig' %}{% block header %}…свій…{% endblock %}`. Поруч — `block footer`.
- **Сторінка 404** — `templates/services/error404.html.twig` у паку (рушій рендерить її з
  `bundles/TwigBundle/Exception/error404.html.twig` через include, тож шлях `bundles/` паку не потрібен). Шаблон самодостатній:
  рендериться поза тенантом — без `site`/`city`, доступні `app.request`, `theme`, `path()`/`asset()`; мова — з шляху (`/ru/`).
- **Опції теми** — `app.theme.options: { будь-які ключі }` (рушій їх не читає): у шаблонах паку `theme.option('ключ', дефолт)`.
  Для налаштувань самого дизайну (слаг розділу для мегаменю, додаткові кольори), щоб не зашивати їх у шаблон.

Правила: лише `services/**` і `partials/**` (адмінку пак не перекриває; `bin/site validate` відмовляє), лише `*.twig`;
файл без відповідника в рушії — попередження валідатора (новий partial — норма, одрук — ні). Зміна `templates/` змінює
хеш паку → `bin/site sync` передеплоює стенд. На сервері деплой робить `lint:twig templates/site`, потім smoke; помилка —
реліз не вмикається. Ризик рівня 3: шаблон паку не отримує нових блоків/змінних рушія автоматично — після оновлення
рушія звіряти перекриті файли з `@engine` (CI рушія лінтить лише фікстуру `tests/site/pack-demo/templates`; паки
перевіряє їхній деплой). Після зміни дизайну на проді — `/analytics-healthcheck`.

### Швидкість (рецепт для всіх сайтів, 11.10.2026)

Виведено на сайті з Joomla-імпортом (PSI mobile 69–77 → 81–100):
1. `app.theme.stylesheet_inline: true` і `app.analytics_defer: true` — **лише разом** (окремо кожне дає половину ефекту:
   без inline лишається ланцюжок link → woff2, без defer — 310 КБ gtm+gtag у ланцюжку LCP).
2. Шрифти — лише `@font-face` з `font-display: swap|optional`, без `<link rel=preload>` (у headless Chrome блокує кадр на 2 с).
3. Текст акцентним кольором — через `--accent-ink` (контраст 4.5+), не `--accent`.
4. Фото послуги — рушій сам віддає webp через Liip (`item_hero`), оригінал лишається для og:image.
5. Після вмикання на проді — `/analytics-healthcheck`: page_view без взаємодії через 10 с, `gtm.js` = 1 при взаємодії на 0-й с.
`site-template` для нових паків ставить обидва `true`; на вже живих сайтах — вмикати лише з «так» власника сайту.

### Параметри `config/parameters.yaml` (дефолти рушія в `config/services.yaml`)

| Параметр | Дефолт | Для чого |
|---|---|---|
| `app.catalog.url_scheme` | `flat` | `flat` = `/services/<послуга>/`; `nested` = `/services/<категорія>/<послуга>/` + сторінки категорій; `root` = `/<категорія>/<послуга>/` від кореня (як у сайтів з Joomla; фіксовані сторінки мають пріоритет, невідомий `/<x>/` — legacy-редирект або 404); `slug` = послуга `/<послуга>/` і категорія `/<категорія>/` від кореня одним сегментом (старі CMS, де кожна сторінка жила на `/<слаг>/`; невідомий `/<x>/` — legacy-редирект або 404, не категорія; див. «Адреси від кореня» нижче). Послуга під чужою категорією — 301 на справжню адресу (nested/root). Маршрути — `config/routes/catalog/<scheme>.yaml`, вибір у `Kernel::configureRoutes`. У коді посилання на послугу — лише `service_path(item, locale)` (Twig) / `CatalogUrls::itemParams()` (PHP). |
| `app.site_verification` | `''` | `<meta name="google-site-verification">` у `<head>`. |
| `app.lead_events` | `[generate_lead]` | події dataLayer на `/thanks` після заявки (усі з `lead_type`, `city`, `site_host`); чужий GTM з ціллю на `form_submit` — `[generate_lead, form_submit]`. |
| `app.analytics_defer` | `false` | `true` = GTM/gtag і Clarity стартують після `window.load` + 800 мс або на першу взаємодію (прапорець проти подвійного старту; пасивні візити рахуються таймером). Знімає ~310 КБ з ланцюжка LCP (PSI mobile 69–75 → 89–94). Для нових паків `site-template` ставить `true`. Після вмикання на проді — `/analytics-healthcheck` (page_view без взаємодії через 10 с). |
| `app.viber_plus` | `true` | Viber-посилання `viber://chat?number=%2B<номер>` (так чат відкривають усі клієнти). `false` — без плюса, лише якщо чужий тригер GTM шукає `number=380…`. |
| `app.clarity_id` | `''` | id проєкту Microsoft Clarity — тег у `<head>` поруч із GTM на всіх хостах сайту (коли Clarity не в GTM). |
| `app.theme` | `{}` | ключі `SiteTheme::DEFAULTS`: `key`, `colors{accent, accent2, accent_ink, dark, navy, footer_bg, footer_line, footer_text, footer_ink}`, `logo`, `favicon_ico`, `favicon_png`, `apple_touch_icon`, `og_image`, `hero_image`, `hero_srcset`, `hero_width`, `hero_height`, `stylesheet`, `stylesheet_inline` (true = CSS у `<style>` замість `<link>`, відносні `url(…)` переписуються від теки файлу), `options` (довільна мапа для шаблонів паку, `theme.option('ключ')`). Шляхи — від `public/`. `theme.url()` додає `?v=<хеш файлу>` (кеш статики на місяці). |
| `app.schema` | порожні | `legal_name`, `founder`, `opening_hours` для JSON-LD бізнесу (LocalBusiness). |
| `app.legacy_redirects` | `{}` | 301 зі старого сайту (`App\ServiceSite\LegacyUrls`, спрацьовує до роутингу). Ключ = старий шлях без слешів по краях (`старий-слаг`, `services/heating/old-slug`, `ru/services/x`), exact-match зі слешем і без. Значення: цільовий шлях рядком (`/services/heating/new-slug/`), `{ path: … }` або старий формат `{city, item, route, locale}`. Збирається з `seo/legacy-redirects.yaml` паку командою `app:site:redirects` (нижче). |
| `app.gone_prefixes` | `[]` | Прибрані розділи старого сайту → 410 Gone (`['/n/']` — дорвеї зламу). |
| `app.clean_urls` | див. services.yaml | Гігієна URL: `trailing_slash` (301 на адресу зі слешем, крім файлів із розширенням і `skip_prefixes`), `strip_query` на сторінках за `strip_query_paths` (зрізає `?task=no`, лишає трекінг `keep_query`: `utm_*`, `gclid`, `fbclid`…; слеш і query зрізаються одним 301), `cross_city` (404 району на хості одного міста, а район є в іншому місті сайту → 301 на його хост). Пак перекриває лише потрібні ключі. |
| `app.home_faq_limit` | `8` | Скільки питань FAQ міста на головній (FAQPage теж); `0` = усі. |
| `app.district_faq_fallback` | `false` | Район без власного FAQ показує FAQ міста (з FAQPage-розміткою). |
| `app.team_pages` | `[about]` | Де показувати блок «Наша команда»: `about`, `contacts`, `home` (можна кілька; на головній — після відгуків). |
| `app.gbp_reviews` | `{token: '', base: 'https://gbp-manager.app', cities: {}}` | Віджет відгуків gbp-manager на /reviews/: `token` — публічний токен віджета (gbp-manager → картка → «Відгуки на сайті» → Код вставки). Заданий — блок віджета (Google + відгуки з сайту, загальна оцінка з мікророзміткою, форма) замість власних форми й списку рушія, і власна оцінка рушія (рядок + `aggregateRating`/`review[]`) вимикається на всіх сторінках: на сторінці одна оцінка, розмітка = видима. Перед увімкненням імпортуйте відгуки сайту в gbp-manager (вкладка «Відгуки з сайту» → імпорт). Параметр один на весь пак, тобто на всі міста-піддомени: картка Google лише одного міста — не сюди, а перекриття `templates/services/page/reviews.html.twig` (`extends '@engine/…'`, віджет при `city.slug == '<місто>'`, інакше `parent()`). `cities: {slug-міста: токен}` — токен для окремого міста (мультиміський сайт, картка Google лише в одному місті): віджет на /reviews/ і загальна оцінка gbp-manager на головній і послугах лише в цих містах, решта міст — оцінка рушія. На головній біля рядка оцінки gbp-manager — посилання «Усі відгуки» на /reviews/. |
| `app.reviews.own_rating` | `true` | Власна загальна оцінка рушія: рядок «Середня оцінка X з 5 за N відгуками» (/reviews/, головна) і `aggregateRating` + `review[]` у JSON-LD (LocalBusiness на головній і /reviews/, Product на сторінці послуги). `false` — коли оцінку з мікророзміткою дає віджет (gbp-manager, «Яку оцінку показувати»): на сторінці лишається одна оцінка, і розмітка збігається з видимою. Самі картки відгуків рушія це не ховає. |
| `app.brand_name` | `{}` | Коротка назва бренду `{uk: …, ru: …}` для текстів, якщо в адмінці (Сайти-бренди → «Коротка назва бренду») порожньо; нема й тут — назва сайту. Підставляється як `%site%` у title/H1 сторінки відгуків: «Відгуки клієнтів про компанію %site%» (типово з 18.10.2026; інший шаблон — ключі `title.reviews` / `reviews.h1` у перекладах паку, доступні `%site%`, `%city%`, `%in%`). Загальне слово беріть у лапки: `'«Майстри»'`. |
| `app.reviews.title_city` | `auto` | Місто в title/H1/og:title сторінки відгуків (з 18.10.2026): `auto` — «Відгуки клієнтів про компанію %site% у Києві», якщо в сайту більше одного активного міста або це хост не апексного міста (інакше на піддоменах міст заголовки однакові); `always` — завжди з містом (сайт одного міста, де місто в заголовку потрібне); `never` — без міста (загальнонаціональна компанія з містом-заглушкою). Місто — місцевий відмінок з ServiceCity («Києві», «Чернівцях»), прийменник `%in%` узгоджується з кінцем назви бренду: «Майстри» **в** Чернівцях, Cleanmax **у** Києві, перед голосною — «в Одесі». Тексти: `title.reviews_city` / `reviews.h1_city` (з містом) і `title.reviews` / `reviews.h1` (без) — пак, що перекриває одну пару, перекриває й другу. |
| `app.home_lead_position` | `after_works` | Форма заявки на головній (один блок): `after_works` (як було) або `after_hero` — лід-магніт одразу під першим екраном. На головній форма має ключі паку `lead.subtitle` (абзац під заголовком) і `lead.messengers_hint` (підпис до месенджерів міста під формою); порожні = нема. На `/thank-you/` з неї `lead_type: magnet`. |
| `app.footer_catalog` | `false` | `true` = у футері повний каталог: категорії (посилання в nested) з усіма послугами колонками, назви без міста; на мобільному категорії згорнуті (`details`). Заголовок — ключ `footer.catalog_title` (порожній = без). Футер обгорнуто в `{% block footer %}` — пак може дописати своє через `extends '@engine/services/base.html.twig'`. |
| `app.home_districts` | `{}` | Чипи районів на головній: `{kyiv: [pozniaky, obolon, …], chernivtsi: '*'}` — slug міста → список `slug_uk` у порядку показу або `'*'` (усі активні). Нема ключа = лише primary-райони. |
| `app.blog_prefix` | `blog` | Сегмент розділу статей: `/blog/` або інший (`news` → `/news/`, `/ru/news/`). |
| `app.page_meta` | `{}` | `<title>`/description фіксованих сторінок 1:1 зі старого сайту: `{ sv_blog: { title_uk, title_ru, desc_uk, desc_ru }, sv_about: {…}, sv_privacy: {…} }` (ключ — маршрут без локалі). Порожньо = як будує шаблон. |
| `app.pages` | `false` | Сторінки від кореня (адмінка → «Сторінки»): вакансії, ліцензії, будь-який текст поза каталогом на `/<слаг>/` і `/<батько>/<слаг>/` (ru — `/ru/…`), без `/page/`. Див. «Адреси від кореня» нижче. `false` — маршрут не спрацьовує, пункту меню нема, футер і карта як були. |
| `app.custom_fields` | `{}` | Додаткові поля записів без міграцій (рішення власника 13.10.2026; `page` — з 20.10.2026): `{ item\|post\|category\|page: [ { key, label: {uk, ru}, suffix: {uk, ru}, localized, filter, categories: [slug_uk…] } ] }`. Значення — JSON-колонка `extra` запису; адмінка показує вкладку «Додаткові поля» сама; шаблони — `custom_fields(entity, locale)` → `[{key, label, value, raw, filter}]` (`filter: true` — для фільтра/групування списку, напр. група автопарку), сире значення — `entity.extraValue(key, locale)`. |
| `app.blog_per_page` | `12` | Статей на сторінці списку блогу (`/blog/`, `/blog/page/N/`); `0` = усі на одній. У sitemap лише `/blog/`. |
| `app.district_suburb_groups` | `[]` | `/districts/`: список `group_uk`, які є передмістям. Заданий → усі інші групи міста показуються акордеоном «Райони %cityGen%» з чипами місцевостей (назва групи веде на її primary-сторінку), а ці — розділом «Передмістя». Порожньо → стара схема (картки primary-районів + групи без primary у «передмісті»). |
| `app.crm` | `{system: auto, bitrix24: {}}` | Куди йдуть заявки форм і калькулятора — див. «CRM сайту» нижче. `auto` = Pipedrive, якщо в `.env.local` є `PIPEDRIVE_API_TOKEN`, інакше без CRM. Пак задає мапу цілком. |
| `app.pipedrive` | порожні | `title_prefix`, `fields{city, deal_type, source, utm_source, object}` (хеші кастомних полів), `source_option`, `city_options{host: id}`, `type_by_slug{slug: id}`. Порожні `fields` = у CRM ідуть лише контакт, лід і примітка. |
| `env(PIPEDRIVE_OWNER_ID)` | `'0'` | id власника лідів (не секрет). |

### CRM сайту (`app.crm`, 13.10.2026)

Заявка спершу зберігається в БД і йде листом, потім — best effort — у CRM через конектор
(`App\ServiceSite\Crm\LeadCrmConnector`: `none`, `pipedrive`, `bitrix24`; нова CRM = новий клас конектора). Помилка CRM
заявку не губить: текст лягає в «Помилка CRM» в адмінці, добирає `app:crm:sync` (крон, `docs/site-ops.md`). Поля заявки
нейтральні: `crm_external_id` (id ліда/угоди), `crm_system`, `crm_synced_at`, `crm_error`.

| `system` | Що робить | Налаштування | Доступ (`.env.local`) |
|---|---|---|---|
| `auto` (дефолт) | як до конекторів: Pipedrive за наявності токена, інакше `none` | — | — |
| `none` | лише БД і лист | — | — |
| `pipedrive` | контакт (без дубля за номером) + лід у Leads Inbox + примітка | `app.pipedrive` | `PIPEDRIVE_API_TOKEN`, `PIPEDRIVE_OWNER_ID` |
| `bitrix24` | `crm.contact.add` → `crm.deal.add` з `CONTACT_ID`; `crm_external_id` = id угоди | `app.crm.bitrix24` | `BITRIX24_WEBHOOK_URL` |

`app.crm.bitrix24` (усі ключі необов'язкові; рушій не знає UF-кодів клієнта — вони лише тут):
`category_id` (воронка), `stage_id`, `title` (дефолт `'Lead from: {host}'`), `source_id` (`website`), `utm_medium_default`
(`'_organic'` — коли в заявці нема utm_medium), `register_sonet_event` (`true`), `contact_fields` і `deal_fields` —
мапи «поле Bitrix → значення». Значення: назва джерела (`page_url` — повна адреса сторінки форми, `site_url` —
`https://<хост>/`, `host`, `city`, `service` — назва послуги зі сторінки форми, `name`, `phone`, `email` (у формі нема),
`message`, `comments` (зведення для менеджера), `utm_source|medium|campaign|content|term`, `click_id`, `referrer`,
`landing_url`, `locale`, `lead_id`); шаблон з `{джерело}` (`'Замовлення дзвінка: {service}'`, висячий `: ` без
значення прибирається); `'=текст'` — константа дослівно (навіть якщо збігається з назвою джерела); інший рядок чи
число — константа. Порожні значення не передаються. Стандартні поля угоди (`TITLE`, `SOURCE_ID`, `CATEGORY_ID`,
`STAGE_ID`, `COMMENTS`, `UTM_*`) `deal_fields` може перекрити. Адресу вебхука в пак класти заборонено (`bin/site validate`
відмовить).

```yaml
parameters:
    app.crm:
        system: bitrix24
        bitrix24:
            category_id: 29
            stage_id: 'C29:NEW'
            contact_fields:
                UF_CRM_AAAA: 'Замовлення дзвінка: {service}'   # без послуги поле не шлеться (усі підстановки порожні)
                UF_CRM_BBBB: page_url
                UF_CRM_CCCC: 'Україна'
            deal_fields:
                UF_CRM_BBBB: page_url
                UF_CRM_DDDD: site_url
```

Категорія в nested-схемі має прапорець «Посадкова сторінка» (адмінка, `ServiceCategory.landing`, типово так): ні — сторінка
`/services/<кат>/` лишається (адреси послуг не змінюються), але noindex,follow, без sitemap і без посилань з каталогу й крихт.
Верх сторінки категорії — як у послуги (H1, лід = `desc` без HTML, кнопки; картинка справа) на всіх сайтах.

Послуга по містах (14.10.2026): в адмінці послуги, блок «Міста» — «Не надається в містах» (послуги там нема в каталозі,
категорії, на головній, у районах, футері, sitemap, «інших послугах», калькуляторі; сторінка послуги на хості такого міста →
301 на місто, де вона є, апекс першим, або 404; категорія без жодної послуги в місті зникає) і «Ціни не показувати в
містах» (послуга є, але без таблиці цін, вкладки цін на головній, Offer у JSON-LD і калькулятора; прайс лишається).
Ціни самі по собі теж за містом (ServicePrice.city): місто без рядків прайсу показує послугу без таблиці.

Тексти під місто (10.2026, проти дублів між хостами): `ServiceItemCity` (назва/лід/описи/FAQ/SEO послуги для міста),
`ServiceCategoryCity` (те саме для категорії), `ServiceCity.about` (власний «Про нас» міста) — у БД, адмінка «… тексти по містах»;
порожнє поле = значення з базової сутності (Twig `ov(override, base, 'поле', locale)`); у списках послуг (каталог,
категорія, головна, «інші послуги») назва — з самої послуги, без міста (власник 11.10.2026: «у списку назва міста виглядає
спамно»), лід у картках категорії — `item_text(item, 'lead', locale)` з перевизначенням поточного міста; H1/title/крихти/JSON-LD
сторінки послуги — з перевизначення.

### Адреси від кореня: схема `slug` і сторінки `app.pages` (20.10.2026)

Для сайту, що переїжджає зі старої CMS з адресами `/<слаг>/` (послуги, хвороби, напрями, «Вакансії», «Ліцензії») 1:1.
Дві незалежні можливості, обидві вимкнені за замовчуванням і на наявних сайтах нічого не змінюють:

| Що | Параметр | Адреси |
|---|---|---|
| Послуги й категорії від кореня | `app.catalog.url_scheme: slug` | послуга `/<слаг>/`, `/ru/<слаг ru>/`; категорія `/<слаг>/` (сторінка категорії як у nested, у карті сайту; `landing: false` — noindex). Крихти: Головна → категорія → послуга (без «Каталог», як у root). `/services/` лишається сторінкою «Усі послуги» |
| Сторінки | `app.pages: true` | `/<слаг>/`, дочірня `/<батько>/<слаг>/` (один рівень), ru — `/ru/…`; працює з будь-якою схемою каталогу |

Порядок розбору `/<x>/`: фіксовані сторінки рушія (`/about/`, `/services/`, `/contacts/`, префікс блогу …) → сторінка
`app.pages` → послуга → категорія (схема `slug`) / `/<категорія>/` (схема `root`) → 301 з `app.legacy_redirects` → 404.
`app.gone_prefixes` (410) і legacy-301 спрацьовують ще до роутингу. Маршрути з однаковим шаблоном розводить умова
маршруту `service('root_slugs').matches(…)` (`App\ServiceSite\RootSlugs`): маршрут спрацьовує лише для наявного активного
запису з таким слагом цієї мови, інакше роутер іде далі — невідомий слаг не стає «категорією» і не дає 500.

**Збіги слагів.** У корені живуть фіксовані розділи (`CatalogUrls::RESERVED_SEGMENTS` + префікс блогу), сторінки, категорії
(root, slug) і послуги (slug). Два записи на одній адресі (з урахуванням ru: порожній `slugRu` = uk-слаг) — одна з
адрес недосяжна. Адмінка не дає зберегти такий слаг (послуга, категорія, сторінка: помилка біля поля «Слаг uk/ru» з тим,
чим адреса зайнята); дані, що прийшли імпортом/seed, показує `bin/console app:site:slugs [slug сайту]` (код 1 — є збіги)
і `bin/site doctor` (FAIL «слаги в корені»). Слаг послуги й категорії — до 160 символів (було 64; міграція 20261020161500).

**Сторінки** (`ServicePage`, адмінка → «Сторінки», пункт є лише з `app.pages: true`): сайт, батько (лише сторінка верхнього
рівня), заголовок і слаг uk/ru, вступ, текст (редактор), обкладинка, **галерея** (вкладка «Галерея»: скани ліцензій і
сертифікатів — сіткою без обрізання, Liip `page_gallery`, клік відкриває оригінал), «У футері» (посилання в нижньому меню),
порядок, «Не показувати в містах» (на хості такого міста — 404, нема в карті й футері), SEO, `app.custom_fields.page`.
Сторінка з дочірніми показує їх списком (вакансії); вимкнений батько вимикає дочірні; батька з дочірніми адмінка не видаляє.
Карта сайту — на кожному хості свої видимі сторінки. Шаблон — `services/page/show.html.twig` (пак перекриває цілком або
блоками через `extends '@engine/services/page/show.html.twig'`; різні сторінки — розгалуження за `page.slugUk`).
Наповнення з YAML — `pages:` у `app:site:seed` (`slug`, `slug_ru`, `parent`, `title_uk`, `lead_uk`, `desc_uk`, `meta_*`,
`position`, `footer`, `active`, `hidden_cities`).

**Як увімкнути на паку** (новий сайт або переїзд; на живому сайті — спершу `app:site:slugs` на копії БД):

```yaml
# config/parameters.yaml паку
parameters:
    app.catalog.url_scheme: slug      # послуги й категорії від кореня (за потреби; сторінки працюють і з flat/nested/root)
    app.pages: true                   # сторінки від кореня
    app.blog_prefix: novosti          # якщо в старого сайту інший розділ новин
```

Далі: `bin/site validate <key>` → деплой (міграція створить таблиці сторінок) → адмінка «Сторінки» або `pages:` у seed →
`bin/console app:site:slugs` (0 збігів) → `bin/site doctor <key>` → жива перевірка `/<послуга>/`, `/<категорія>/`,
`/<сторінка>/`, `/ru/…`, невідомого `/<x>/` (404) і `sitemap.xml` кожного хоста.

Що лишається в БД (адмінка), а не в паку: назва й апекс-домен бренду, schema.org-тип, «Про нас», юридичні тексти
(`ServiceSite`); хости, телефони, email, адреси, GTM/gtag, карти, noindex, тексти головної, FAQ (`ServiceCity`);
каталог, ціни, райони, відгуки, блог, роботи, калькулятор.

### Порядок підключення паку (що робить `bin/site deploy`; руками — лише для локального стека)

1. `cp site-<key>/config/parameters.yaml <рушій>/config/site/parameters.local.yaml`
2. `rsync -a site-<key>/themes/<key>/ <рушій>/public/themes/<key>/`
3. `cp site-<key>/translations/*.yaml <рушій>/translations/site/`
4. `.env.local` на сервері (секрети), `composer install --no-dev -o`, `bin/console cache:clear`,
   `bin/console doctrine:migrations:migrate`.
5. Жива перевірка (головна, каталог, послуга, район, форма, адмінка, sitemap, robots, hreflang) + `/analytics-healthcheck`.

### Локальний стек рушія

`docker-compose.local.yml`: проєкт `service-engine`, nginx на `8113`, MySQL на `33113` (БД/юзер/пароль `service_engine`).
Dev-хости: `<slug>.localhost:8113` (апекс-місто сайту), `<city>.<slug>.localhost:8113`, адмінка `service-sites.localhost:8113`.
Пак підключається тими самими трьома копіюваннями, що й на проді.

## Редиректи зі старого сайту

Пак тримає правила у зручному для зняття з `.htaccess` вигляді — `seo/legacy-redirects.yaml`:

```yaml
legacy_redirects:
    '/services/heating/montazh-hazovoho-kotla': '/services/heating/gas-boiler-installation/'
    '/ru/services/artemivka': '/ru/districts/artemivka/'
```

`bin/console app:site:redirects <тека паку>` перевіряє правила (ціль зі слеша, не дорівнює джерелу, без ланцюжків 301→301
і дублів з/без слеша) і перезаписує блок `app.legacy_redirects` у `config/parameters.yaml` паку між маркерами
`# >>> app.legacy_redirects` … `# <<< app.legacy_redirects` (решта файлу не чіпається). `--check` — лише звірити,
чи блок актуальний (для CI паку). Правило спрацьовує на будь-якому місті бренду й лишається на поточному хості.

## Імпорт сайту з Joomla/Seblod (`app:joomla:import`)

Generic-імпортер для сайтів на Joomla + Seblod. Джерело — дамп таблиць Joomla (`aim_*`), залитий у
сусідню схему того самого MySQL (`joomla_src`); усе сайтове — у YAML-мапінгу паку `seo/joomla-mapping.yaml`
(`App\ServiceSite\Import\JoomlaMapping`): id міст (`domains` → ServiceCity, `site_id` з `cck_core_sites`), категорії
Joomla → URL-слаги й тексти, шляхи до FAQ-JSON, правила цін, нормалізація посилань, шаблон H1 головної.

```
bin/console app:joomla:import --mapping=<пак>/seo/joomla-mapping.yaml [--source-schema=joomla_src] \
    [--media-root=<корінь Joomla з images/>] [--with-leads] [--only=domains,catalog,area,iblog,ipage,prices,reviews] [--dry-run]
```

Переносить: бренд і міста (NAP, відмінки, мета головної, тексти, міський FAQ з маркерного формату Seblod, команду), категорії,
послуги (усі поля uk/ru: lead/desc/desc_2/desc_3/tabtitle/utp/мета + FAQ з JSON або маркерів; ru-мета з колонок-запасників
`joomla_import.ru_fallback_columns: [en]`, коли `_ru` порожнє), райони з групами (`apl_district`)
і FAQ, пости, «Про нас» (ipage), прайс (`apl_prices`: сума/безкоштовно/за домовленістю, «від»/«до»), відгуки, заявки
(`--with-leads`). Ідемпотентно по `legacyId` (команда — по місту й позиції). Чернетки Joomla імпортуються вимкненими.
Картинки з `--media-root` копіюються в `public/media/<тип>/` (ім'я `<тип>-<legacy id>-<файл>`). Наприкінці — таблиця
кількостей і список «не лягло» (сирітські ціни, сторінки без місця в рушії, дублі головних сторінок районів).

**Кілька Seblod-типів і сайти без `domains`** (з 13.10.2026, перший сайт з кількома типами; зразок — `seo/joomla-mapping.yaml`
його пака). Без нових розділів мапінг працює як раніше (перевірено на першому сайті з Joomla/Seblod: імпорт старим і новим
кодом дає однакову БД).
- `items:` — список типів, що стають послугами: `{type, slug: item_content|content_alias, category: catid|<id>, fields, specs}`.
  `fields` — поле рушія → основа колонки (`<type>_<основа>_<мова>`) або `{uk: колонка, ru: колонка}`; поля: title, lead, desc,
  desc2, desc3, tab_title, meta_title, meta_desc, faq, img, utp, name (коротка назва для карток). `extra` — значення
  додаткових полів (`app.custom_fields` нижче): `{ключ: {value: колонка|{uk, ru}, prefix: {column, map}}}`; ключі, яких
  у мапінгу нема (заповнені в адмінці), повторний імпорт не чіпає.
- `category_source: {type, fields}` — тексти категорії (title, lead, desc, мета, FAQ, картинка) із Seblod-типу на категорії
  Joomla; `pages.categories: {<id категорії>: <id ipage>}` — те саме з ipage; значення в `categories.<id>` мають пріоритет;
  `category_defaults.landing` / `categories.<id>.landing` — прапорець «посадкова».
- `cities[]` без `legacy_id` — місто лише з мапінгу (`name_nom_uk` … `name_loc_ru`, `phone`, `email`, `hours`, `address_*`,
  `viber`, `telegram`, `whatsapp`); `pages.home` — SEO, текст і FAQ головної з ipage; `pages.privacy` — політика.
- `posts: {type: inews, fields: {meta_title: meta_title}}` — тип постів; `reviews: {table, city: legacy|location}`
  (`location` — колонка city = текст «звідки автор» → `ServiceReview.location`, без колонки stars — 5);
  `team: {table: apl_team}` + тип `team` у `types` — команда з таблиці на апекс.
- FAQ-маркери Seblod: `template_faq_*` і `tmpl_faq_*`.
- `app.clean_urls.prefix_redirects: { '/uk/': '/' }` — префіксні 301 (мовний префікс Joomla для мови за замовчуванням).
Тека `local/` у паку (дамп джерела) git-ignored і `bin/site validate` її не перевіряє.
