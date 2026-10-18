# Свій дизайн сайту (необов'язково)

Усе, що лежить тут, деплой копіює в `templates/site/` рушія, і Twig бере ці файли **замість** однойменних шаблонів
рушія (`templates/` у `luxsite-agency/service-engine`). Тека порожня = типовий дизайн рушія з кольорами й лого
з `config/parameters.yaml` (`app.theme`) і `themes/<key>/theme.css`.

- Перекривати можна лише публічні шаблони: `services/**` (сторінки, `base.html.twig`) і `partials/**`. Адмінку — ні.
- Успадкувати шаблон рушія й змінити лише частину: `{% extends '@engine/services/home.html.twig' %}` + свої блоки
  (`title`, `metaDescription`, `schema`, `body`; у `base.html.twig` ще `nav`, `stylesheets`, `javascripts`, `meta`, `analytics`).
- Повністю свій шаблон — просто файл з тим самим шляхом без `extends`. Змінні контролера ті самі (див. шаблон рушія).
- Нові partial-и паку класти в `partials/` і підключати зі своїх шаблонів (`include 'partials/_my.html.twig'`).
- Деплой на сервері робить `lint:twig templates/site` і smoke головних сторінок; помилка = реліз не вмикається.
- Після зміни дизайну на проді — `/analytics-healthcheck` (GTM, події, форма), як після будь-якої правки шаблонів.

Старт: `bin/site theme new <key>` у рушії створює `theme.css` і заготовки `services/base.html.twig`, `services/home.html.twig`
(extends `@engine`, усі блоки = `parent()`); `bin/site theme list` — що ще можна перекрити; `--copy <шаблон>` — повна копія;
після оновлення рушія `bin/site theme diff <key>` — що змінилось у перекритих файлах.

Приклад — `tests/site/pack-demo/templates/services/home.html.twig` у рушії.
