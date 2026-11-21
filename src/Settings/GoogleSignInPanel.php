<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use App\Security\GoogleKeySource;
use App\Security\GoogleOAuthClientFactory;
use App\Security\GoogleOAuthCredentials;
use App\ServiceSite\SiteLocales;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Розділ «Вхід через Google» під списком налаштувань (модель shop-engine GoogleSignInPanel): стан (звідки ключі, чому
 * вимкнено), готові значення для Google Cloud Console — Authorized JavaScript origin і redirect URI для КОЖНОГО активного
 * міста сайту (у сайту кілька доменів: апекс, піддомени, окремі домени міст — кожен окремим рядком у Cloud Console;
 * адмінка живе на своєму хості, тому не хост запиту), і «Перевірити налаштування»: формат Client ID, стан секрету,
 * перемикач, discovery/JWKS Google. Повний вхід адміністратор перевіряє сам кнопкою на сайті. Тексти — домен admin.
 */
final class GoogleSignInPanel
{
    public const string CSRF = 'settings-google-check';
    public const string DISCOVERY_URL = 'https://accounts.google.com/.well-known/openid-configuration';

    public function __construct(
        private readonly GoogleOAuthCredentials $credentials,
        private readonly SiteSettings $settings,
        private readonly SecretBox $box,
        private readonly ServiceCityRepository $cities,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'app.google_probe_http_client')] private readonly HttpClientInterface $http,
        private readonly ?SiteLocales $locales = null,
    ) {
    }

    /**
     * @return array{source: string, problem: string|null, hosts: list<array{city: string, host: string, origin: string, redirectUri: string, active: bool}>, tryUrl: string|null, csrf: string, keyProblem: string|null}
     */
    public function view(ServiceSite $site): array
    {
        $resolved = $this->credentials->resolve($site);
        $callback = $this->path(GoogleOAuthClientFactory::CHECK_ROUTE, [], '/connect/google/check');
        $reviews = $this->path('sv_reviews', ['_locale' => $this->locales?->default() ?? 'uk'], '/reviews/');
        $hosts = [];
        $try = null;
        foreach ($this->cities->findBy(['site' => $site], ['isApex' => 'DESC', 'id' => 'ASC']) as $city) {
            if (!$city->isActive()) {
                continue;
            }
            $origin = 'https://' . $city->getHost();
            $hosts[] = ['city' => $city->name('uk'), 'host' => $city->getHost(), 'origin' => $origin, 'redirectUri' => $origin . $callback, 'active' => true];
            $try ??= $origin . $reviews;
        }

        return [
            'source' => $resolved['source']->value,
            'problem' => $resolved['problem'],
            'hosts' => $hosts,
            'tryUrl' => $try,
            'csrf' => self::CSRF,
            'keyProblem' => $this->box->keyProblem(),
        ];
    }

    /**
     * Перевірки для flash: кожна — ok/fail і готовий текст (без значень ключів).
     *
     * @return list<array{ok: bool, text: string}>
     */
    public function check(ServiceSite $site): array
    {
        $t = fn (string $key, array $params = []): string => $this->translator->trans('settings.google.check_result.' . $key, $params, 'admin');
        $rows = [];

        $raw = trim((string) $this->settings->get($site, SiteSettingKeys::GOOGLE_CLIENT_ID));
        $idOk = null !== SiteSettingKeys::validValue(SiteSettingKeys::GOOGLE_CLIENT_ID, $raw);
        $rows[] = ['ok' => $idOk, 'text' => $t($idOk ? 'client_id_ok' : ('' === $raw ? 'client_id_missing' : 'client_id_invalid'))];

        $state = $this->settings->secretState($site, SiteSettingKeys::GOOGLE_CLIENT_SECRET);
        $rows[] = ['ok' => $state->isReady(), 'text' => $t('secret_' . $state->value, ['%problem%' => (string) $this->box->keyProblem()])];

        $enabled = $this->settings->getBool($site, SiteSettingKeys::GOOGLE_SIGN_IN_ENABLED);
        $source = $this->credentials->resolve($site)['source'];
        $rows[] = match (true) {
            GoogleKeySource::Settings === $source => ['ok' => true, 'text' => $t('enabled_settings')],
            GoogleKeySource::Env === $source => ['ok' => false, 'text' => $t('enabled_env')],
            $enabled => ['ok' => false, 'text' => $t('enabled_incomplete')],
            default => ['ok' => false, 'text' => $t('enabled_off')],
        };

        $hosts = \count($this->view($site)['hosts']);
        $rows[] = ['ok' => $hosts > 0, 'text' => $t($hosts > 0 ? 'hosts' : 'hosts_none', ['%count%' => (string) $hosts])];

        try {
            $probe = $this->probe();
            $rows[] = ['ok' => true, 'text' => $t('google_ok', ['%issuer%' => $probe['issuer'], '%keys%' => (string) $probe['keys']])];
            if (!$probe['endpointsMatch']) {
                $rows[] = ['ok' => false, 'text' => $t('google_endpoints_changed')];
            }
        } catch (\RuntimeException | ExceptionInterface $e) {
            $rows[] = ['ok' => false, 'text' => $t('google_failed', ['%reason%' => mb_strimwidth($e->getMessage(), 0, 160, '…')])];
        }

        return $rows;
    }

    /**
     * Discovery-документ Google і JWKS: сервер бачить Google, кінцеві точки ті, що в league/oauth2-google.
     *
     * @return array{issuer: string, keys: int, endpointsMatch: bool}
     */
    public function probe(): array
    {
        $doc = $this->http->request('GET', self::DISCOVERY_URL, ['timeout' => 5])->toArray();
        $issuer = (string) ($doc['issuer'] ?? '');
        $jwksUri = (string) ($doc['jwks_uri'] ?? '');
        if ('' === $issuer || !str_starts_with($jwksUri, 'https://')) {
            throw new \RuntimeException('discovery без issuer/jwks_uri');
        }
        $jwks = $this->http->request('GET', $jwksUri, ['timeout' => 5])->toArray();
        $keys = \is_array($jwks['keys'] ?? null) ? \count($jwks['keys']) : 0;
        if (0 === $keys) {
            throw new \RuntimeException('JWKS без ключів');
        }
        $provider = new Google();

        return [
            'issuer' => $issuer,
            'keys' => $keys,
            'endpointsMatch' => ($doc['authorization_endpoint'] ?? null) === $provider->getBaseAuthorizationUrl()
                && ($doc['token_endpoint'] ?? null) === $provider->getBaseAccessTokenUrl([]),
        ];
    }

    /** @param array<string, string> $params */
    private function path(string $route, array $params, string $fallback): string
    {
        try {
            return $this->urls->generate($route, $params);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
