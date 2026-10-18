# Site Template (sitetemplate.example.com): історія для колеги

## Проєкт і люди
- Клієнт / хто вирішує: …
- Чого не чіпати без прямого «так»: прод і його БД.

## Як влаштовано
- Рушій: `luxsite-agency/service-engine`, версія в `site.yaml` → `engine`; сервер — у реєстрі `sites.yaml` рушія.
- Деплой: `bin/site deploy sitetemplate` з операторської машини (де лежить реєстр SITES_FILE). Відкат: `bin/site rollback sitetemplate`.
- Аналітика: GTM/GA4 у БД міста (адмінка → Міста). Після правок — `/analytics-healthcheck`.

## Історія
- (дата) — пак створено з шаблону.

## Що лишилось
- Лого й фавікони в `themes/sitetemplate/` (правило 09.10.2026: лого в шапці обов'язкове).
