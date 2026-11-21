# Зміни

Релізи — теги `vX.Y.Z` (нотатки GitHub генеруються з PR). Тут — зміни, які потребують дій від розгортань.

## Невипущене

- **Файли підтвердження Google Search Console — на кожен бренд з адмінки.** «Налаштування сайту» → SEO →
  «Google Search Console — файли підтвердження» (`seo.google_verification_files`, до 5 імен `google<hex>.html`) і
  «Bing Webmaster — BingSiteAuth.xml» (`seo.bing_site_auth`). Файл віддається лише на хостах свого бренду, інакше 404.
  Як підключити — `docs/site-ops.md`, «Підтвердження сайту в Google Search Console файлом».
- **Прибрано `public/google3cc17ecbb1f93a59.html`** (з v0.9.31 віддавався на всіх сайтах усіх розгортань і зв'язував мережу).
  Дія: якщо сайт підтверджено в GSC цим файлом — до оновлення внести `google3cc17ecbb1f93a59.html` у налаштування
  потрібного бренду (лише того, чий це акаунт GSC), інакше GSC з часом зніме підтвердження.
- Слаг `BingSiteAuth.xml` зарезервовано (`CatalogUrls::RESERVED_SEGMENTS`, `bin/site.d/_validate.py`).
