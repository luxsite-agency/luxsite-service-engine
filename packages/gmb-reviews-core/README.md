# luxsite/gmb-reviews-core — ядро блоку відгуків Google (PHP ≥ 8.1, без фреймворку)

Читає JSON віджета з GMB Manager (контракт `/w/v1/{token}.json`), кешує його (PSR-16 за бажанням, запасний варіант —
остання вдала відповідь), рендерить HTML + CSS + JSON-LD і форму «написати відгук». Підходить для WordPress, OpenCart,
Laravel чи будь-якого PHP-сайту; для Symfony є обгортка `luxsite/gmb-reviews-bundle` з Twig-функціями.

```php
use Luxsite\GmbReviews\{Client, Renderer};

$client = new Client(cache: $psr16Cache /* або null — файловий кеш у sys_get_temp_dir() */);
$data = $client->fetch($token);                      // null, якщо GMB Manager недоступний і кеш порожній
echo $data ? Renderer::html($data, ['layout' => 'carousel']) : '';
echo $data ? Renderer::jsonLdScript($data, $pageUrl) : '';
```

## Ліцензія

Apache License 2.0 — як і рушій service-engine, див. [`LICENSE`](../../LICENSE) і [`NOTICE`](../../NOTICE) у корені репозиторію.
Пакет можна вільно використовувати і змінювати, зокрема в комерційних проєктах; файл NOTICE при поширенні зберігається.
Сервер GMB Manager, який віддає дані віджета, цією ліцензією не охоплюється.
