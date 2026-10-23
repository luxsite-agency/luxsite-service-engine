<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запити через ядро Symfony без веб-сервера й без symfony/browser-kit (як tests/reviews-*.php): cookie — окремо на хост,
 * сесія тестового середовища — mock_file. Потрібна БД після міграцій (CI: крок «Міграції → схема»).
 */
abstract class HttpKernelTestCase extends KernelTestCase
{
    /** @var array<string, array<string, string>> хост → cookie */
    private array $jars = [];

    protected function tearDown(): void
    {
        $this->jars = [];
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $server
     * @param array<string, mixed>  $files  завантажені файли (UploadedFile у тестовому режимі), як $_FILES
     */
    protected function http(string $method, string $url, array $params = [], array $server = [], array $files = []): Response
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = Request::create($url, $method, $params, $this->jars[$host] ?? [], $files, $server);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jars[$host][$cookie->getName()]);
            } else {
                $this->jars[$host][$cookie->getName()] = (string) $cookie->getValue();
            }
        }
        $kernel->terminate($request, $response);

        return $response;
    }

    /** Вхід у адмінку формою /login (CSRF зі сторінки). */
    protected function login(string $adminHost, string $email, string $password): void
    {
        $page = (string) $this->http('GET', 'https://' . $adminHost . '/login')->getContent();
        self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m), 'на сторінці входу є CSRF');
        $response = $this->http('POST', 'https://' . $adminHost . '/login', ['_username' => $email, '_password' => $password, '_csrf_token' => html_entity_decode($m[1])]);
        self::assertTrue($response->isRedirect(), 'вхід → редирект');
        self::assertStringNotContainsString('/login', (string) $response->headers->get('Location'), 'вхід успішний');
    }

    /** Поля форми (input/textarea) першої форми з name="<form>[…]" — для POST як із браузера. @return array<string, string> */
    protected static function formFields(string $html, string $formName): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $fields = [];
        foreach ((new \DOMXPath($doc))->query('//input[@name] | //textarea[@name]') ?: [] as $node) {
            \assert($node instanceof \DOMElement);
            $name = $node->getAttribute('name');
            if (!str_starts_with($name, $formName . '[')) {
                continue;
            }
            $type = strtolower($node->getAttribute('type'));
            if (\in_array($type, ['checkbox', 'radio'], true) && !$node->hasAttribute('checked')) {
                continue;
            }
            $fields[$name] = 'textarea' === $node->nodeName ? $node->textContent : $node->getAttribute('value');
        }

        return $fields;
    }

    /**
     * Плоскі імена form[a][b] → вкладений масив для Request::create.
     *
     * @param array<string, string> $flat
     *
     * @return array<string, mixed>
     */
    protected static function nest(array $flat): array
    {
        $out = [];
        foreach ($flat as $name => $value) {
            parse_str(rawurlencode($name) . '=' . rawurlencode($value), $one);
            $out = array_replace_recursive($out, $one);
        }

        return $out;
    }
}
