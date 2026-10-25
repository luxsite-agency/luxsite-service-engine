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
| секрети | `.env.local` на сервері | `DATABASE_URL`, `MAILER_DSN`, `MAIL_FROM`, `ADMIN_HOST`, `DEFAULT_URI`, `STUDIO_API_TOKEN`, `GOOGLE_CLIENT_*` (запасне джерело — див. нижче), `PIPEDRIVE_API_TOKEN`, `BITRIX24_WEBHOOK_URL`, `LEAD_RATE_LIMIT_ALLOWLIST`, `SETTINGS_SECRET_KEY` (ключ шифрування секретів «Налаштування сайту»; `bin/site deploy` дописує сам) — у пак не потрапляють. |

Відгуки на сайті залишають через вхід Google. Ключі OAuth клієнт вводить сам в адмінці → «Налаштування сайту» → «Вхід через Google» (з 21.10.2026, мають пріоритет; там же адреси для Google Cloud Console по кожному місту й кнопка «Перевірити налаштування» — `docs/site-ops.md`, розділ «Налаштування в адмінці»); запасне джерело, поки перемикач в адмінці вимкнено, — `GOOGLE_CLIENT_ID` і `GOOGLE_CLIENT_SECRET` у `.env.local`. Без ключів блок «Залишити відгук» на `/reviews/` не показується (кнопки й технічних повідомлень відвідувач не бачить), попередження з посиланням на налаштування — на дашборді адмінки, у «Відгуках клієнтів» і в лозі (`/connect/google`). Свій шаблон відгуків у паку — з тією ж умовою: `{% if app.user or google_oauth.configured %}`. Параметри паку `app.clarity_id` і `app.site_verification` лишаються запасними значеннями: якщо ці поля задано в «Налаштуваннях сайту», діють вони.

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
| `app.catalog.url_scheme` | `flat` | `flat` = `/services/<послуга>/`; `nested` = `/services/<категорія>/<послуга>/` + сторінки категорій; `root` = `/<категорія>/<послуга>/` від кореня (як у сайтів з Joomla; фіксовані сторінки мають пріоритет, невідомий `/<x>/` — legacy-редирект або 404). Послуга під чужою категорією — 301 на справжню адресу (nested/root). Маршрути — `config/routes/catalog/<scheme>.yaml`, вибір у `Kernel::configureRoutes`. У коді посилання на послугу — лише `service_path(item, locale)` (Twig) / `CatalogUrls::itemParams()` (PHP). |
| `app.site_verification` | `''` | `<meta name="google-site-verification">` у `<head>`. |
| `app.lead_events` | `[generate_lead]` | події dataLayer на `/thanks` після заявки (усі з `lead_type`, `city`, `site_host`); чужий GTM з ціллю на `form_submit` — `[generate_lead, form_submit]`. |
| `app.analytics_defer` | `false` | `true` = GTM/gtag і Clarity стартують після `window.load` + 800 мс або на першу взаємодію (прапорець проти подвійного старту; пасивні візити рахуються таймером). Знімає ~310 КБ з ланцюжка LCP (PSI mobile 69–75 → 89–94). Для нових паків `site-template` ставить `true`. Після вмикання на проді — `/analytics-healthcheck` (page_view без взаємодії через 10 с). |
| `app.viber_plus` | `true` | Viber-посилання `viber://chat?number=%2B<номер>` (так чат відкривають усі клієнти). `false` — без плюса, лише якщо чужий тригер GTM шукає `number=380…`. |
| `app.clarity_id` | `''` | id проєкту Microsoft Clarity — тег у `<head>` поруч із GTM на всіх хостах сайту (коли Clarity не в GTM). |
| `app.theme` | `{}` | ключі `SiteTheme::DEFAULTS`: `key`, `colors{accent, accent2, accent_ink, dark, navy, footer_bg, footer_line, footer_text, footer_ink}`, `logo`, `favicon_ico`, `favicon_png`, `apple_touch_icon`, `og_image`, `hero_image`, `hero_srcset`, `hero_width`, `hero_height`, `stylesheet`, `stylesheet_inline` (true = CSS у `<style>` замість `<link>`, відносні `url(…)` переписуються від теки файлу), `options` (довільна мапа для шаблонів паку, `theme.option('ключ')`). Шляхи — від `public/`. `theme.url()` додає `?v=<хеш файлу>` (кеш статики на місяці). |
| `app.schema` | порожні | `legal_name`, `founder`, `opening_hours` для JSON-LD бізнесу (LocalBusiness). |
| `app.legacy_redirects` | `{}` | 301 зі старого сайту (`App\ServiceSite\LegacyUrls`, спрацьовує до роутингу). Ключ = старий шлях без слешів по краях (`старий-слаг`, `services/heating/old-slug`, `ru/services/x`), exact-match зі слешем і без. Значення: цільовий шлях рядком (`/services/heating/new-slug/`), `{ path: … }` або старий формат `{city, item, route, locale}`. Збирається з `seo/legacy-redirects.yaml` паку командою `app:site:redirects` (нижче). |
| `app.gone_prefixes` | `[]` | Прибрані розділи старого сайту → 410 Gone (`['/n/']` — дорвеї зламу). |
| `app.seo_not_found_limit`, `app.seo_not_found_days` | `10000`, `90` | Журнал 404 в адмінці (розділ «SEO → Журнал 404»): скільки адрес на сайт і скільки днів з останнього звернення зберігати; решта прибирається сама (див. «Редиректи зі старого сайту»). |
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
| `app.custom_fields` | `{}` | Додаткові поля записів без міграцій (рішення власника 13.10.2026): `{ item\|post\|category: [ { key, label: {uk, ru}, suffix: {uk, ru}, localized, filter, categories: [slug_uk…] } ] }`. Значення — JSON-колонка `extra` запису; адмінка показує вкладку «Додаткові поля» сама; шаблони — `custom_fields(entity, locale)` → `[{key, label, value, raw, filter}]` (`filter: true` — для фільтра/групування списку, напр. група автопарку), сире значення — `entity.extraValue(key, locale)`. |
| `app.blog_per_page` | `12` | Статей на сторінці списку блогу (`/blog/`, `/blog/page/N/`); `0` = усі на одній. У sitemap лише `/blog/`. |
| `app.district_suburb_groups` | `[]` | `/districts/`: список `group_uk`, які є передмістям. Заданий → усі інші групи міста показуються акордеоном «Райони %cityGen%» з чипами місцевостей (назва групи веде на її primary-сторінку), а ці — розділом «Передмістя». Порожньо → стара схема (картки primary-районів + групи без primary у «передмісті»). |
| `app.crm` | `{system: auto, bitrix24: {}}` | Куди йдуть заявки форм і калькулятора — див. «CRM сайту» нижче. `auto` = Pipedrive, якщо в `.env.local` є `PIPEDRIVE_API_TOKEN`, інакше без CRM. Пак задає мапу цілком. |
| `app.pipedrive` | порожні | `title_prefix`, `fields{city, deal_type, source, utm_source, object}` (хеші кастомних полів), `source_option`, `city_options{host: id}`, `type_by_slug{slug: id}`. Порожні `fields` = у CRM ідуть лише контакт, лід і примітка. |
| `app.calculator.mode` | `auto` | Калькулятор вартості (див. «Калькулятор» нижче): `auto` — «за площею», якщо в сайту є типи прибирання, інакше «за прайсом», якщо в прайсі міста є позиції з «У калькуляторі»; `area` / `pricelist` — лише один режим; `off` — калькулятора нема (404, посилань нема). |
| `app.calculator.path` | `{}` | Адреса калькулятора `{uk: '/…/', ru: '/ru/…/'}`. Порожньо = типова за режимом: площа — `/calculator-cleaning/`, прайс — `/calculator/` (інша типова → 301). Задана паком — статична, обидві типові адреси → 301 на неї з переносом query; перший сегмент резервується серед слагів категорій (схема `root`). |
| `app.html` | `{strip_comments: true}` | Чистий HTML (стандарт рушія з 25.10.2026, див. «Чистий HTML» нижче): з відповідей на хостах сайтів прибираються коментарі розробника — HTML, CSS у `<style>`, JS в інлайн-скриптах; статика теми в релізі чиститься при deploy. `strip_comments: false` — лишити коментарі (пак, якому вони потрібні для чужого інструменту). |
| `env(PIPEDRIVE_OWNER_ID)` | `'0'` | id власника лідів (не секрет). |

### Чистий HTML: без коментарів розробника (стандарт рушія з 25.10.2026)

Вимога власника: у `view-source` сторінок сайтів не має бути коментарів розробника — ні HTML `<!-- -->`, ні CSS `/* */`, ні JS
`/* */` та `// …`. Рушій робить це сам, на всіх сайтах, без правок паків:

- **Відповіді сайту** (`App\EventSubscriber\HtmlCommentsSubscriber`, після `CityWordsSubscriber`, лише `text/html` на хостах
  сайтів — не адмінка, не `/api`, не `/mcp`, не редиректи): один прохід по тексту без DOM (`App\ServiceSite\Html\CommentStripper`,
  сторінка 130 КБ — частки мілісекунди). Прибирається: HTML-коментарі (і маркери `<!--city-raw-->`, уже спожиті підміною міста),
  `/* */` у `<style>` з схлопуванням порожніх рядків, `/* */` і `// …` у `<script>` без `type` або з JS-типом. Контент з БД (тексти
  менеджерів) чиститься так само.
- **Не чіпається:** умовні коментарі `<!--[if …]>…<![endif]-->`, вміст `<pre>`, `<textarea>`, `<code>`, атрибути, JSON-LD
  (`application/ld+json`), `text/template`, ліцензійні `/*! … */`; `<noscript>` обробляється як HTML.
- **JS без ризику:** маленький токенайзер розрізняє рядки (`' " \``), шаблонні рядки з `${…}`, регулярні вирази (за попереднім
  токеном: після `( , = : [ ! & | ? { } ;`, `return` тощо) і URL у рядках. Якщо розбір непевний (незакритий рядок, regex без
  кінця) — скрипт лишається як є: краще коментар, ніж зламаний JS.
- **Статика теми** (`public/themes/<key>/*.css|*.js`, nginx віддає з `?v=`): при deploy, після `composer install`, у теці релізу
  виконується `app:html:strip-static public/themes` — тією ж логікою. Оригінали в паку не змінюються; `?v=` рахується з файлу
  релізу, тож кеш браузерів оновиться. Руками: `php bin/console app:html:strip-static <тека> [--dry-run]`.
- **Twig-шаблони рушія:** коментарі `{# … #}` на окремому рядку написані як `{#- … #}`, щоб не лишати порожніх рядків у виводі;
  у шаблонах паку (`templates/`) робіть так само.
- **Вимкнути** в паку: `app.html: { strip_comments: false }` (коментарі лишаться і в HTML, і в статиці теми — deploy команду
  викликає завжди, але вона прибирає лише коментарі, тож для «потрібних» коментарів вимикайте параметр і тримайте їх у
  `templates/` паку, а не в `themes/`). Перевірка на живому сайті — `bin/site doctor <key>` («коментарі у HTML: 0»).

### Калькулятор (стандарт рушія з 24.10.2026)

Калькулятор є на кожному сайті, де його є чим наповнити; режим обирає `App\ServiceSite\CalculatorAvailability` для міста запиту:

| Режим | Коли | Адреса (типова) | Що на сторінці |
|---|---|---|---|
| «за площею» (`area`) | у сайту є активні типи прибирання (адмінка → Калькулятор → Типи) | `/calculator-cleaning/`, `/ru/calculator-cleaning/` | тип × площа × коефіцієнти + додаткові послуги (клінінг, як було); міні-калькулятор на головній/послузі/районі |
| «за прайсом» (`pricelist`) | типів нема, але в прайсі міста є активні позиції з прапорцем **«У калькуляторі»** (`in_calculator`) | `/calculator/`, `/ru/calculator/` | акордеони категорія → послуга → нумеровані позиції прайсу: назва, ціна з прайсу (не редагується), обсяг з одиницею, «Разом» за рядок; «Сума» під послугою, «Всього» під категорією, загальна сума в підсумку; форма «Надіслати заявку з розрахунком» |
| нема | ні типів, ні позицій з прапорцем (або `app.calculator.mode: off`) | — | сторінка 404; кнопка «Повний розрахунок» під цінами, пункт меню, футер, sitemap — без калькулятора |

**Як увімкнути режим «за прайсом»** (santekhniky, elektryky та будь-який сайт «за позиціями»): в адмінці → Прайс (або REST/MCP `in_calculator: true`)
поставити «У калькуляторі» потрібним позиціям міста. Зважайте на одиниці: «Одиниця» (`unit_uk`/`unit_ru`: м², м. п., точка…) показується біля
поля обсягу, порожня = «шт.»; «Макс. кількість» (`max_qty`, типово 30) обмежує обсяг; ціна множиться на обсяг (обсяг 0 = позиція не обрана).
Позиція без числа (лише «Текст ціни», напр. «За домовленістю») обирається, у сумі йде як «+ ціна уточнюється». Поважаються «Не надається в містах»
і «Ціни не показувати в містах» послуги. Більше нічого вмикати не треба: кнопка під таблицею цін, пункт меню, sitemap і 301 зі старої адреси
`/calculator-cleaning/` з'являються самі. Заявка йде тим самим шляхом, що й з калькулятора площі (`sv_calc_lead`, лист + CRM, `lead_type: calculator`);
сума перераховується на сервері з цін БД — з форми приймаються лише id позиції й обсяг.

**Тексти** (ключі `servicesite`, пак перекриває в `translations/`): `prices.note` — примітка під таблицями цін на головній і сторінці послуги
(типово «Ціни на сайті орієнтовні. Остаточну вартість називає майстер після уточнення деталей.»; порожньо = не показувати); `calc.disclaimer` — та
сама примітка в підсумку калькулятора (обох режимів); `calc.pricelist.*` — підписи режиму «за прайсом» (`intro`, `col_*`, `unit_default` «шт.»,
`price_tbd`, `subtotal`, `group_total`, `submit`…); `meta.calculator_pricelist` — description сторінки. Для клінінгу («менеджер», а не «майстер»)
пак перекриває `prices.note` і `calc.disclaimer` своїм текстом.

**Адреса** — `app.calculator.path` (таблиця вище). У шаблонах і паках посилання лишається `path('sv_calculator')`; показувати його — лише коли
`calculator_available()` (Twig; `site_has_calculator(site)` — старе ім'я з тим самим змістом).

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

### Редиректи в адмінці й журнал 404 (з 23.10.2026, модель shop-engine E3)

Крім мапи паку, правила живуть у БД і правляться без розробника: адмінка → **SEO → Редиректи** і **Журнал 404**
(`ROLE_MODERATOR`), REST `/api/admin/v1/{site}/redirects` і MCP (`docs/admin-api.md`). Таблиці `service_redirect` і
`service_not_found` (міграція `Version20261023100000`, адитивна). Без правил у БД поведінка сайту та сама, що до цього.

**Порядок спрацювання на запит хоста міста:**

1. **Пак, до роутингу** (`LegacyUrlSubscriber`, як і раніше): 410 за `app.gone_prefixes` → `/index.php` → мапа
   `app.legacy_redirects` → слеш, зайвий query, `prefix_redirects`, район в іншому місті без слеша.
2. Маршрут і контролер. Жива сторінка — 200; правила з БД живих сторінок **не перекривають**.
3. **404 → правило з БД** (`NotFoundRedirectSubscriber`): спершу правило для цього міста, потім правило сайту (місто
   порожнє). 301/302 — на нову адресу (query їде далі за правилами паку: трекінг лишається, `?Itemid=` зрізається;
   `Cache-Control: no-store`, бо правила змінні), 410 — сторінка `services/error410.html.twig` (пак перекриває як
   404), 404 — звичайна 404 без запису в журнал. Правило, заведене без слеша, спрацьовує і на адресу зі слешем.
4. Правила нема, але нормалізована адреса (нижній регістр, без подвійних слешів) жива → 301 на неї, без журналу.
5. Район є в іншому місті сайту → 301 туди (`cross_city`, як і раніше).
6. Інакше — адреса в **журнал 404** (хост міста + шлях, звернення, останній referer без параметрів; без IP, UA і query;
   сканування ботів, `/admin`, `/api`, статика не пишуться). Ліміт на сайт — `app.seo_not_found_limit` /
   `app.seo_not_found_days`: прибирається саме й командою `app:seo:not-found:prune` (cron, `docs/site-ops.md`).

**Що лишається в паку, що йде в БД.** У паку — структурні правила про схему URL: слеш, query, `/index.php`,
`prefix_redirects` (`/uk/` → `/`), 410-префікси цілих розділів, масові переїзди з Joomla (сотні рядків
`seo/legacy-redirects.yaml`, перевіряються в CI паку). У БД — усе, що править спеціаліст після запуску: окремі адреси з
журналу 404 і GSC, 410 на видалені сторінки, тимчасові 302, правила лише для одного міста. Мапа паку має перевагу: доки
адреса є в `app.legacy_redirects`, правило з БД для неї стоїть у резерві. Адреса, яку впізнає лише пак (мапа,
`prefix_redirects`, невідомий `/<x>/` root-схеми), для перевірки «жива» живою не вважається — тому правило в БД для неї
заводиться (і з паку переноситься), а спрацює, щойно її приберуть із паку.

**Авто-301.** Зміна слага (uk або ru) послуги, категорії (схеми nested/root — разом з її послугами), поста → правило
«авто» зі старої адреси на нову для всіх міст сайту; району — лише для хоста його міста. Повернули слаг — правило
знімається, зворотне записується. Видалення чи вимкнення сторінки правил не створює: 410 — вручну. Приховали послугу
в місті (`hiddenCities`) — як і раніше, 301 у місто, де вона є.

**Перенесення з паку.** Адмінка → Редиректи → «Перенести з паку» (або `bin/console app:seo:redirects:from-pack <slug>`
на сервері сайту): `app.legacy_redirects` з цільовим шляхом → правила 301 «з паку», корені `app.gone_prefixes` → 410.
Ідемпотентно; ручні й авто-правила з тією ж адресою не чіпаються; правила старого формату `{city, item, route}` лишаються
в паку. Після перенесення блок `app.legacy_redirects` у паку можна очистити (тоді правлять лише в адмінці) або лишити
як запасний (рішення власника сайту); 410-префікси паку діють на всі вкладені адреси й лишаються в паку.

**CSV.** Експорт та імпорт `source;code;target[;city]` (`;`, UTF-8, до 5000 рядків з адмінки; більше —
`app:seo:redirects:import <slug> <файл>`). `city` — slug міста для правила на один хост, порожньо — усі міста.
Наявні правила з тією ж адресою замінюються; ланцюги зливаються (ціль одразу кінцева), цикли й живі адреси — у звіті.
Правило на всі міста перевіряється на цикл і через правила кожного міста (місто `B→A` + сайт `A→B` — відмова), бо на
хості міста воно діє поверх них; кінцеву ціль правила сайту правила міст не змінюють (на такому хості можливі два стрибки).

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
