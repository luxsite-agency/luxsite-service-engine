# LuxSite Service Engine

Рушій сайтів для сервісного бізнесу: виклик майстра, клінінг, логістика, оренда, будь-яка послуга з містами,
прайсом і заявками. Один рушій обслуговує багато сайтів (мультитенант по Host), кожен сайт описаний окремим
паком: параметри, тема, переклади, за потреби свій дизайн.

Symfony 8.1, PHP 8.4, MySQL 8, EasyAdmin. Мови інтерфейсу: uk, ru.

## Що вміє
- **Міста й райони:** піддомен або домен на місто, свої тексти, ціни, FAQ і месенджери міста, сторінки районів, 301 між містами.
- **Каталог послуг:** категорії, послуги з цінами по містах, калькулятор вартості, фото робіт, відгуки, команда, FAQ, блог.
- **Заявки:** форма на кожній сторінці, лід-магніт, кнопки Viber / Telegram / WhatsApp, захист від дублів і спаму,
  UTM і gclid у заявці, відправка на пошту й у CRM.
- **Аналітика:** GTM / GA4 / Clarity з БД міста, події заявок і кліків, відкладений старт тегів без втрати пасивних візитів.
- **Швидкість:** webp через Liip, стилі в сторінці, `?v=` на файлах теми. Реальні сайти дають 90+ у PageSpeed на мобільному.
- **Свій дизайн:** `templates/` у паку перекриває шаблони рушія; оновлення рушія їх не ламають.
- **Менеджери й Claude Code:** персональні API-токени з правами, REST `/api/admin/v1`, студія контенту `/api/studio`,
  MCP `/mcp`, живе редагування дизайну з перевіркою рендера, історією й відкатом.
- **Імпорт:** `app:joomla:import` зі збереженням адрес (301), `app:site:seed` для наповнення нового сайту з YAML.
- **Експлуатація:** `bin/site deploy | rollback | doctor | backup | restore | sync | new | validate | theme` —
  реліз із бекапом, міграціями, smoke-перевіркою й автоматичним відкатом.

## Швидкий старт
```bash
composer install
cp .env .env.local                      # DATABASE_URL, APP_SECRET, MAILER_DSN
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
bash bin/site new mysite                # пак сайту з site-template/
php bin/console app:site:seed path/to/seed.yaml
symfony serve                           # або docker compose -f docker-compose.local.yml up
```

Реєстр живих сайтів для `bin/site` живе поза рушієм і передається через `SITES_FILE` (приклад — [`sites.example.yaml`](sites.example.yaml)).

Документація: [`docs/site-pack.md`](docs/site-pack.md) (пак сайту, параметри, свій дизайн),
[`docs/site-ops.md`](docs/site-ops.md) (деплой, бекап, відкат, стенди), [`docs/admin-api.md`](docs/admin-api.md)
(токени, REST, MCP, живий дизайн).

## Розробка
Зміни — через pull request у `main` із зеленим CI (lint, схема проти міграцій, тести `bin/site`). Релізи — теги `vX.Y.Z`.
Як долучитися: [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Ліцензія
Apache License 2.0 — [`LICENSE`](LICENSE). Рушій можна вільно використовувати, змінювати й запускати, зокрема в комерційних
проєктах. У копіях і похідних роботах зберігається файл [`NOTICE`](NOTICE) зі згадкою про розробників платформи.

**Розробники платформи: [LuxSite](https://luxsite.agency).** Назва й логотип LuxSite — торгова марка, ліцензія на код
права на них не дає. Запуск під ключ, наповнення, аналітика й підтримка: [service.luxsite.agency](https://service.luxsite.agency).

---

*LuxSite Service Engine is a multi-tenant Symfony platform for service-business websites (cities, districts, services,
prices, leads, analytics, manager API). Licensed under Apache 2.0; platform developed by LuxSite — keep the NOTICE file.*
