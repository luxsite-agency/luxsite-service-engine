# luxsite/gmb-reviews-bundle — відгуки Google на сайті (Symfony 7/8)

Блок із відгуками Google, рейтингом, кількістю, мікророзміткою і формою «написати відгук».
Дані віддає GMB Manager (контракт `/w/v1/{token}.json`); які відгуки показувати, вигляд і поведінка
форми налаштовуються **там**, а не в коді сайту.

## Встановлення

```bash
composer require luxsite/gmb-reviews-bundle
```

`config/bundles.php` (Flex додасть сам):
```php
Luxsite\GmbReviewsBundle\GmbReviewsBundle::class => ['all' => true],
```

`config/packages/gmb_reviews.yaml`:
```yaml
gmb_reviews:
    token: '%env(GMB_REVIEWS_TOKEN)%'    # зі сторінки «Відгуки на сайті» картки в GMB Manager
    # base_url: 'https://gmb.luxsite.agency'
    # ttl: 600            # секунд між зверненнями до GMB Manager (далі — з кешу)
    # cache_pool: cache.app
```

`.env`: `GMB_REVIEWS_TOKEN=0123456789abcdef0123456789abcdef`

## Використання

```twig
{{ gmb_reviews() }}                                  {# типовий токен, розкладка з GMB Manager #}
{{ gmb_reviews(null, {layout: 'carousel', theme: 'dark'}) }}
{{ gmb_reviews('інший_токен', {layout: 'badge', schema: false}) }}   {# кілька карток на сайті #}
```

Мікророзмітка додається одразу після блоку. Якщо хочете її в `<head>`:
```twig
{% block head %}{{ gmb_reviews_jsonld(null, app.request.uri) }}{% endblock %}
{{ gmb_reviews(null, {schema: false}) }}
```

Своя верстка — сирі дані: `{% set d = gmb_reviews_data() %}` → `d.business.rating`, `d.reviews[]`.

## Поведінка

- Кеш 10 хв; якщо GMB Manager недоступний, показується остання вдала відповідь (до 7 діб), потім блок зникає.
- Форма шле відгук напряму в GMB Manager (JSON, CORS відкритий); далі модерація в GMB Manager.
- Стилі з префіксом `gmbr-`, CSS друкується один раз на сторінку (`css: false` — щоб не друкувати).

## Загальна оцінка в інших блоках (з 1.1.0)

Та сама оцінка, що в шапці віджета (вибір «Яку оцінку показувати» в GMB Manager), — для блоків відгуків на головній, сторінках послуг тощо:

```twig
{% set r = gmb_rating() %}{# {value: 5.0, count: 102, source: 'combined'|'google', includes_site, name, maps_url} або null #}
{% if r %}<span>{{ r.value|number_format(1) }} ★ · {{ r.count }}</span>{% endif %}

{{ gmb_rating_badge() }}                         {# готовий рядок «5.0 ★★★★★ · 102 відгуки» #}
{{ gmb_rating_badge(null, {schema: true}) }}     {# + JSON-LD — лише якщо на сторінці нема віджета й своєї розмітки рейтингу #}
```

Сервер GMB Manager недоступний — значення з кешу (до 7 діб, повторна спроба раз на хвилину), інакше `null` / порожній рядок.

## Ліцензія

Apache License 2.0 — як і рушій service-engine, див. [`LICENSE`](../../LICENSE) і [`NOTICE`](../../NOTICE) у корені репозиторію.
Пакет можна вільно використовувати і змінювати, зокрема в комерційних проєктах; файл NOTICE при поширенні зберігається.
Сервер GMB Manager, який віддає дані віджета, цією ліцензією не охоплюється.
