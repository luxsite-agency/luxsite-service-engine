# site-demo — пак сайту Demo (demo.example.com) для service-engine

Створено `bin/site new` з шаблону `site-template/` рушія. Що тут лежить і як це підхоплює рушій — `docs/site-pack.md`
у `luxsite-agency/service-engine`; експлуатація (деплой, бекап, відкат) — `docs/site-ops.md` там же.

| Тека/файл | Що це |
|---|---|
| `site.yaml` | ключ, назва, мови, домени, версія рушія |
| `config/parameters.yaml` | параметри сайту без секретів → `config/site/parameters.local.yaml` рушія |
| `themes/demo/` | лого, фавікони, хіро, og, `theme.css` → `public/themes/demo/` рушія |
| `translations/servicesite.{uk,ru}.yaml` | бренд-тексти, що перекривають дефолти рушія (порожнє значення = елемент не показується) |
| `content/`, `seo/`, `studio/` | початкові тексти, семантика, промпти студії (без токенів) |
| `docs/CLAUDE.md` | історія для колеги: люди, рішення, що лишилось |

Секретів у паку не буває: `.env.local` живе лише на сервері сайту. Назва бренду, апекс-домен, міста, телефони, GTM —
у БД (адмінка `ServiceSite` / `ServiceCity`), не тут.
