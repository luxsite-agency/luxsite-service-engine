<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\ServiceSite\LegacyUrls;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Відповідь на правило редиректу бренду (301/302): ціль — абсолютна адреса або шлях на цьому хості; query запиту їде далі за
 * правилами паку (LegacyUrls::queryFor). Без кешу браузера: правила змінні (адмінка). Спільне для правил до роутингу
 * (LegacyUrlSubscriber — адреса без слеша) і на 404 (NotFoundRedirectSubscriber).
 */
final class RedirectResponder
{
    public function __construct(private readonly LegacyUrls $legacy)
    {
    }

    public function redirect(Request $request, string $target, RedirectCode $code): RedirectResponse
    {
        if (RedirectPath::isAbsoluteUrl($target)) {
            $location = $target;
        } else {
            $location = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . RedirectPath::encode($target);
        }
        if (!str_contains($location, '?')) {
            $location .= $this->legacy->queryFor($request, (string) parse_url($location, \PHP_URL_PATH));
        }
        $response = new RedirectResponse($location, $code->value);
        // правила змінні (адмінка): 301 без заголовків кешу браузер тримав би назавжди (див. LegacyUrlSubscriber)
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
