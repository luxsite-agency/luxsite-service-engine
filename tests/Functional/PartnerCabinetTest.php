<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Controller\Partner\PartnerLeadCrudController;
use App\Entity\AdminApiToken;
use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\GoogleAuthenticator;
use App\Security\GoogleOAuthClientFactory;
use App\Security\GoogleOAuthCredentials;
use App\Settings\SiteSettings;
use App\Tests\Support\PartnersKernel;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PartnerCabinetTest extends SeoTestCase
{
    private int $partnerA;
    private int $partnerB;
    private string $emailA;
    private string $emailB;
    private array $ids = [];

    protected static function getKernelClass(): string
    {
        return PartnersKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $a = (new ServicePartner())->setCity($this->city($this->apexCityId))->setName('Cabinet A');
        $b = (new ServicePartner())->setCity($this->city($this->apexCityId))->setName('Private B')->setNotifyEmails('private-b@example.test')
            ->setNotes('PRIVATE_INTERNAL_NOTE')->setLicenseNotes('PRIVATE_LICENSE');
        $this->em()->persist($a);
        $this->em()->persist($b);
        $this->emailA = $this->slug . '-partner-a@example.test';
        $this->emailB = $this->slug . '-partner-b@example.test';
        foreach ([$this->emailA => $a, $this->emailB => $b] as $email => $partner) {
            $this->createUser($email, ['ROLE_PARTNER'], $partner);
        }
        foreach (['shared', 'onlyA', 'junkA', 'onlyB'] as $key) {
            $lead = (new ServiceLead())->setCity($a->getCity())->setPhone($key === 'onlyB' ? '+12025550999' : '+12025550123')
                ->setName($key === 'onlyB' ? 'PRIVATE_B_CUSTOMER' : 'Customer A')->setZipFrom('11968')->setZipTo('10001')
                ->setMessage('Move furniture')->setDetails(['move_size' => 'Two rooms'])->setConsentText('Consent to sharing')
                ->setConsentAt(new \DateTimeImmutable('2026-10-12 12:00:00'))->setConsentIp('198.51.100.201')
                ->setUtmSource('PRIVATE_UTM')->setClickId('PRIVATE_CLICK')->setReferrer('https://private-ref.example/')
                ->setLandingUrl('https://private-landing.example/')->setPageUrl('https://private-page.example/?private=query')
                ->setCrmExternalId('PRIVATE_CRM');
            $this->em()->persist($lead);
            foreach ($key === 'shared' ? ['A' => $a, 'B' => $b] : ($key === 'onlyB' ? ['B' => $b] : ['A' => $a]) as $label => $partner) {
                $assignment = (new ServiceLeadAssignment())->setLead($lead)->setPartner($partner)
                    ->setAssignedAt(new \DateTimeImmutable('2026-10-15 12:00:00', new \DateTimeZone('America/New_York')))
                    ->setPrice($label === 'B' ? 99991 : match ($key) { 'onlyA' => 2000, 'junkA' => 3000, default => 1000 })
                    ->setStatus(match ($key) { 'onlyA' => 'accepted', 'junkA' => 'junk', default => 'new' });
                if ($key === 'junkA') {
                    $assignment->setStatusNote('Invalid request');
                }
                $this->em()->persist($assignment);
                $this->ids[$key === 'shared' ? $key . $label : $key] = $assignment;
            }
        }
        $this->em()->flush();
        $this->partnerA = $a->getId();
        $this->partnerB = $b->getId();
        $this->ids = array_map(static fn (ServiceLeadAssignment $a): int => $a->getId(), $this->ids);
        $this->em()->clear();
    }

    protected function tearDown(): void
    {
        $this->em()->clear();
        $this->db()->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [$this->slug . '-partner-%']);
        $this->db()->executeStatement('DELETE FROM service_lead_assignment WHERE partner_id IN (?, ?)', [$this->partnerA, $this->partnerB]);
        $this->db()->executeStatement('DELETE FROM service_partner WHERE id IN (?, ?)', [$this->partnerA, $this->partnerB]);
        parent::tearDown();
    }

    public function testIndexAndDetailExposeOnlyOwnAssignmentsAndSafeFields(): void
    {
        $this->signInA();
        $response = $this->get('/partner/partner-lead');
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertSame(3, preg_match_all('/<tr\b[^>]*data-id="[0-9]+"/', $html));
        self::assertStringNotContainsString('+12025550999', $html);
        self::assertStringNotContainsString('PRIVATE_B_CUSTOMER', $html);
        self::assertStringContainsString('+12025550123', $html);
        self::assertStringContainsString('lang="en"', $html);
        $detail = $this->get('/partner/partner-lead/' . $this->ids['sharedA']);
        self::assertSame(200, $detail->getStatusCode());
        $html = (string) $detail->getContent();
        foreach (['198.51.100.201', 'PRIVATE_UTM', 'PRIVATE_CLICK', 'private-ref', 'private-landing', 'private-page', 'PRIVATE_CRM',
            'Private B', 'private-b@example.test', 'PRIVATE_INTERNAL_NOTE', 'PRIVATE_LICENSE', '999.91', '99991'] as $secret) {
            self::assertStringNotContainsString($secret, $html, $secret);
        }
        foreach (['tel:+12025550123', 'Customer A', '11968', '10001', 'Consent to sharing', 'move_size', 'Two rooms'] as $visible) {
            self::assertStringContainsString($visible, $html);
        }
        $saved = $this->assignment('sharedA');
        self::assertSame('new', $saved->getStatus());
        self::assertNull($saved->getStatusAt());
        $home = $this->get('/partner');
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('In progress', (string) $home->getContent());
    }

    public function testForeignDetailEditAndForgedPostAre404AndDoNotMutate(): void
    {
        $this->signInA();
        foreach (['sharedB', 'onlyB'] as $key) {
            $path = '/partner/partner-lead/' . $this->ids[$key];
            self::assertSame(404, $this->get($path)->getStatusCode());
            self::assertSame(404, $this->get($path . '/edit')->getStatusCode());
            $fields = $this->editFields('sharedA');
            $fields['ServiceLeadAssignment[status]'] = 'accepted';
            self::assertSame(404, $this->http('POST', $this->url($path . '/edit'), self::nest($fields))->getStatusCode());
            self::assertSame('new', $this->assignment($key)->getStatus());
            self::assertNull($this->assignment($key)->getStatusAt());
        }
    }

    public function testSearchFiltersAndSecondCompanyKeepIsolation(): void
    {
        $this->signInA();
        $search = $this->get('/partner/partner-lead?query=PRIVATE_B_CUSTOMER');
        self::assertSame(200, $search->getStatusCode());
        self::assertSame(0, preg_match_all('/<tr\b[^>]*data-id="[0-9]+"/', (string) $search->getContent()));
        $filter = http_build_query(['filters' => ['status' => ['comparison' => '=', 'value' => 'accepted']]]);
        $page = $this->get('/partner/partner-lead?' . $filter);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame(1, preg_match_all('/<tr\b[^>]*data-id="[0-9]+"/', (string) $page->getContent()));
        $this->get('/logout');
        $response = $this->signIn($this->emailB);
        self::assertSame('/partner', $response->headers->get('Location'));
        $page = $this->get('/partner/partner-lead');
        self::assertSame(200, $page->getStatusCode());
        self::assertSame(2, preg_match_all('/<tr\b[^>]*data-id="[0-9]+"/', (string) $page->getContent()));
        self::assertSame(404, $this->get('/partner/partner-lead/' . $this->ids['onlyA'])->getStatusCode());
    }

    public function testOwnStatusEditValidationAndTimestamp(): void
    {
        $this->signInA();
        self::assertTrue($this->saveStatus('sharedA', 'accepted')->isRedirect());
        self::assertSame('accepted', $this->assignment('sharedA')->getStatus());
        self::assertNotNull($this->assignment('sharedA')->getStatusAt());
        $stamp = $this->assignment('sharedA')->getStatusAt();
        self::assertTrue($this->saveStatus('sharedA', 'accepted', 'Called')->isRedirect());
        self::assertEquals($stamp, $this->assignment('sharedA')->getStatusAt());
        $invalid = $this->saveStatus('sharedA', 'junk', '   ');
        self::assertContains($invalid->getStatusCode(), [200, 422]);
        self::assertStringContainsString('Explain why', (string) $invalid->getContent());
        self::assertSame('accepted', $this->assignment('sharedA')->getStatus());
        self::assertTrue($this->saveStatus('sharedA', 'junk', 'Wrong number')->isRedirect());
        self::assertSame('junk', $this->assignment('sharedA')->getStatus());
        self::assertSame('Wrong number', $this->assignment('sharedA')->getStatusNote());
        self::assertFalse($this->saveStatus('sharedA', 'new')->isRedirect());
        self::assertSame('junk', $this->assignment('sharedA')->getStatus());
        self::assertFalse($this->saveStatus('sharedA', 'arbitrary')->isRedirect());
        self::assertSame('junk', $this->assignment('sharedA')->getStatus());
    }

    public function testCsrfAndExtraFieldsCannotChangeData(): void
    {
        $this->signInA();
        $fields = $this->editFields('sharedA');
        $fields['ServiceLeadAssignment[status]'] = 'accepted';
        $fields['ServiceLeadAssignment[_token]'] = 'bad';
        self::assertFalse($this->http('POST', $this->url('/partner/partner-lead/' . $this->ids['sharedA'] . '/edit'), self::nest($fields))->isRedirect());
        self::assertSame('new', $this->assignment('sharedA')->getStatus());
        $leadId = $this->assignment('sharedA')->getLead()->getId();
        $fields = $this->editFields('sharedA');
        foreach (['price' => '1', 'partner' => (string) $this->partnerB, 'lead' => '1'] as $field => $value) {
            $fields['ServiceLeadAssignment[' . $field . ']'] = $value;
        }
        $fields['ServiceLeadAssignment[status]'] = 'won';
        // EasyAdmin ігнорує поля, яких нема у формі: статус зберігається, а ціна, компанія й заявка — ні
        $this->http('POST', $this->url('/partner/partner-lead/' . $this->ids['sharedA'] . '/edit'), self::nest($fields));
        self::assertSame(1000, $this->assignment('sharedA')->getPrice());
        self::assertSame($this->partnerA, $this->assignment('sharedA')->getPartner()->getId());
        self::assertSame($leadId, $this->assignment('sharedA')->getLead()->getId());
    }

    public function testPartnerCannotUseAdminApiOrMcpEvenWithValidToken(): void
    {
        $this->signInA();
        self::assertSame(403, $this->get('/admin')->getStatusCode());
        $user = $this->user($this->emailA);
        self::assertSame([], static::getContainer()->get(AdminApiTokens::class)->allowedScopes($user));
        // Токен створено напряму: перевірка автентифікатора не залежить від заборони видачі.
        $plain = 'svc_' . str_repeat('a', 43);
        $token = new AdminApiToken($user, 'Legacy partner token', [ApiScope::ContentRead], AdminApiTokens::hash($plain), substr($plain, 0, 12), new \DateTimeImmutable('+1 day'));
        $this->em()->persist($token);
        $this->em()->flush();
        foreach (['/api/admin/v1/me', '/mcp'] as $path) {
            foreach ([$plain, 'invalid'] as $bearer) {
                $response = $this->http('GET', $this->url($path), [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer]);
                self::assertContains($response->getStatusCode(), [401, 403]);
            }
        }
    }

    public function testModeratorCannotEnterCabinetAndLoginStillTargetsAdmin(): void
    {
        $this->db()->executeStatement('UPDATE service_lead_assignment SET assigned_at = ? WHERE partner_id = ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $this->partnerA]);
        $response = $this->signIn($this->moderatorEmail);
        self::assertSame('/admin', parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        foreach (['/partner', '/partner/billing', '/partner/partner-lead'] as $path) {
            self::assertSame(403, $this->get($path)->getStatusCode());
        }
        $detail = $this->get('/admin/service-partner/' . $this->partnerA);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString($this->emailA, (string) $detail->getContent());
        self::assertStringContainsString('33.3%', (string) $detail->getContent());
    }

    public function testCompanyAndUserAccessBoundaries(): void
    {
        $this->em()->find(ServicePartner::class, $this->partnerA)->setStatus('left');
        $this->em()->flush();
        $this->signInA();
        foreach (['/partner', '/partner/partner-lead', '/partner/billing?format=csv'] as $path) {
            self::assertSame(403, $this->get($path)->getStatusCode());
        }
    }

    public function testMissingCompanyAndMixedRolesAreDeniedEvenIfStoredOutsideForm(): void
    {
        $this->user($this->emailA)->setPartner(null);
        $this->em()->flush();
        $this->signInA();
        self::assertSame(403, $this->get('/partner')->getStatusCode());
        $this->user($this->emailA)->setPartner($this->em()->find(ServicePartner::class, $this->partnerA))->setRoles(['ROLE_PARTNER', 'ROLE_MODERATOR']);
        $this->em()->flush();
        // ролі в БД змінились — Symfony розлогінює сесію (302 на вхід); після нового входу — 403: кабінет не для персоналу
        $response = $this->get('/partner');
        self::assertTrue($response->getStatusCode() === 403 || str_contains((string) $response->headers->get('Location'), '/login'));
        $this->signInA();
        self::assertSame(403, $this->get('/partner')->getStatusCode());
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $this->user($this->emailA)->setActive(false);
        $this->em()->flush();
        $response = $this->signIn($this->emailA);
        self::assertStringContainsString('/login', (string) $response->headers->get('Location'));
        self::assertSame(302, $this->get('/partner')->getStatusCode());
    }

    public function testSiteHostHasNoCabinet(): void
    {
        foreach (['/partner', '/partner/partner-lead', '/partner/billing?format=csv'] as $path) {
            self::assertSame(404, $this->http('GET', 'https://' . $this->apex . $path)->getStatusCode());
        }
    }

    public function testBillingAndCsvAreScopedAndExcludeJunkFromTotalOnly(): void
    {
        $this->signInA();
        $response = $this->get('/partner/billing?month=2026-10');
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-billing-count>3<', $html);
        self::assertStringContainsString('data-billing-total="3000"', $html);
        self::assertSame(3, substr_count($html, 'data-assignment-id='));
        $csv = $this->get('/partner/billing?month=2026-10&format=csv');
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim((string) $csv->getContent())));
        self::assertSame(['assignment_id', 'assigned_at', 'zip_from', 'zip_to', 'status', 'price_cents', 'billed'], array_shift($lines));
        self::assertCount(3, $lines);
        $indexed = array_column($lines, null, 0);
        foreach (['sharedA', 'onlyA', 'junkA'] as $key) {
            self::assertArrayHasKey($this->ids[$key], $indexed);
            self::assertSame(['11968', '10001'], array_slice($indexed[$this->ids[$key]], 2, 2));
        }
        self::assertSame(['junk', '3000', 'no'], array_slice($indexed[$this->ids['junkA']], 4));
        self::assertSame(['accepted', '2000', 'yes'], array_slice($indexed[$this->ids['onlyA']], 4));
        self::assertArrayNotHasKey($this->ids['sharedB'], $indexed);
        self::assertArrayNotHasKey($this->ids['onlyB'], $indexed);
        foreach (['Customer', '+1202555', 'Private B'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $csv->getContent());
        }
        $current = (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m');
        self::assertStringContainsString('value="' . $current . '"', (string) $this->get('/partner/billing?month=garbage')->getContent());
    }

    public function testBillingUsesLocalMonthBoundaries(): void
    {
        $partner = $this->em()->find(ServicePartner::class, $this->partnerA);
        $storage = new \DateTimeZone(date_default_timezone_get());
        $boundaryIds = [];
        foreach (['2026-11-01 00:00:00' => true, '2026-12-01 00:00:00' => false,
            '2026-10-31 23:59:59' => false, '2026-11-30 23:59:59' => true] as $at => $included) {
            $lead = (new ServiceLead())->setCity($partner->getCity())->setPhone('+12025550111');
            $assignment = (new ServiceLeadAssignment())->setPartner($partner)->setLead($lead)->setPrice(100)
                ->setAssignedAt((new \DateTimeImmutable($at, new \DateTimeZone('America/New_York')))->setTimezone($storage));
            $this->em()->persist($lead);
            $this->em()->persist($assignment);
            $this->em()->flush();
            $boundaryIds[$assignment->getId()] = $included;
        }
        $this->signInA();
        $csv = (string) $this->get('/partner/billing?month=2026-11&format=csv')->getContent();
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
        array_shift($rows);
        $ids = array_map('intval', array_column($rows, 0));
        self::assertCount(2, $ids);
        foreach ($boundaryIds as $id => $included) {
            self::assertSame($included, \in_array($id, $ids, true), (string) $id);
        }
    }

    public function testAdminUserFormRejectsMissingCompanyAndMixedRoles(): void
    {
        $userId = $this->user($this->emailA)->getId();
        $this->signIn($this->adminEmail);
        $path = '/admin/user/' . $userId . '/edit';
        foreach ([[['ROLE_PARTNER'], '', 'обов’язково'], [['ROLE_PARTNER', 'ROLE_MODERATOR'], (string) $this->partnerA, 'несумісна']] as [$roles, $partner, $error]) {
            $page = $this->get($path);
            self::assertSame(200, $page->getStatusCode());
            self::assertStringContainsString('Компанія-партнер (кабінет)', (string) $page->getContent());
            $params = self::nest(self::formFields((string) $page->getContent(), 'User'));
            $params['User']['roles'] = $roles;
            $params['User']['partner'] = $partner;
            $response = $this->http('POST', $this->url($path), $params + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
            self::assertContains($response->getStatusCode(), [200, 422]);
            self::assertStringContainsString($error, (string) $response->getContent());
            $this->em()->clear();
            self::assertSame($this->partnerA, $this->user($this->emailA)->getPartner()->getId());
            self::assertNotContains('ROLE_MODERATOR', $this->user($this->emailA)->getRoles());
        }
        self::assertSame(404, $this->get('/admin/partner-lead')->getStatusCode());
        self::assertSame(404, $this->get('/admin/billing')->getStatusCode());
    }

    public function testAllGeneratedPartnerCrudRoutesBelongOnlyToCabinet(): void
    {
        $routes = static::getContainer()->get('router')->getRouteCollection();
        $count = 0;
        foreach ($routes as $route) {
            $controller = (string) $route->getDefault('_controller');
            if (str_starts_with($route->getPath(), '/partner')) {
                self::assertStringNotContainsString('App\\Controller\\Admin\\', $controller);
                ++$count;
            }
            if (str_starts_with($route->getPath(), '/admin')) {
                self::assertStringNotContainsString(PartnerLeadCrudController::class, $controller);
            }
        }
        self::assertGreaterThan(4, $count);
        $this->signInA();
        foreach (['/partner/service-lead', '/partner/service-partner', '/partner/user'] as $path) {
            self::assertSame(404, $this->get($path)->getStatusCode());
        }
        self::assertSame(404, $this->get('/partner/partner-lead/autocomplete')->getStatusCode());
        foreach (['/partner/partner-lead/new', '/partner/partner-lead/' . $this->ids['sharedA'] . '/delete'] as $path) {
            $r = $this->http(str_ends_with($path, '/delete') ? 'POST' : 'GET', $this->url($path));
            self::assertContains($r->getStatusCode(), [403, 404]);
        }
    }

    public function testGoogleCreatedUserReceivesNoCabinetAccess(): void
    {
        $email = $this->slug . '-partner-google@example.test';
        $client = $this->createStub(OAuth2ClientInterface::class);
        $client->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'test-access-token']));
        $client->method('fetchUserFromToken')->willReturn(new GoogleUser(['sub' => 'google-' . $this->s, 'email' => $email, 'email_verified' => true, 'name' => 'Google User']));
        $registry = $this->createStub(ClientRegistry::class);
        $registry->method('getClient')->willReturn($client);
        $factory = new GoogleOAuthClientFactory(new GoogleOAuthCredentials(static::getContainer()->get(SiteSettings::class), 'dummy-id', 'dummy-secret'),
            $registry, new RequestStack(), static::getContainer()->get(UrlGeneratorInterface::class));
        $auth = new GoogleAuthenticator($factory, $this->em(), static::getContainer()->get(UserRepository::class), new NullLogger());
        $created = $auth->authenticate(Request::create($this->url('/connect/google/check')))->getUser();
        self::assertInstanceOf(User::class, $created);
        self::assertSame(['ROLE_USER'], array_values($created->getRoles()));
        self::assertNull($created->getPartner());
        // Локальний пароль лише встановлює сесію в HTTP-хелпері; користувача створив справжній GoogleAuthenticator.
        $created->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($created, $this->password));
        $this->em()->flush();
        $this->signIn($email);
        self::assertSame(403, $this->get('/partner')->getStatusCode());
    }

    private function createUser(string $email, array $roles, ?ServicePartner $partner = null): User
    {
        $user = (new User())->setEmail($email)->setRoles($roles)->setPartner($partner);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $this->password));
        $this->em()->persist($user);

        return $user;
    }

    private function user(string $email): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function signIn(string $email): Response
    {
        $page = (string) $this->get('/login')->getContent();
        self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m));

        return $this->http('POST', $this->url('/login'), ['_username' => $email, '_password' => $this->password, '_csrf_token' => html_entity_decode($m[1])]);
    }

    private function signInA(): void
    {
        $response = $this->signIn($this->emailA);
        // перший вхід — '/partner'; повторний після відмови — збережена адреса кабінету повністю
        self::assertMatchesRegularExpression('~^(https://[^/]+)?/partner$~', (string) $response->headers->get('Location'));
    }

    private function url(string $path): string
    {
        return 'https://' . $this->adminHost . $path;
    }

    private function get(string $path): Response
    {
        return $this->http('GET', $this->url($path));
    }

    private function assignment(string $key): ServiceLeadAssignment
    {
        $this->em()->clear();

        return $this->em()->find(ServiceLeadAssignment::class, $this->ids[$key]);
    }

    private function editFields(string $key): array
    {
        $page = $this->get('/partner/partner-lead/' . $this->ids[$key] . '/edit');
        self::assertSame(200, $page->getStatusCode());

        return self::formFields((string) $page->getContent(), 'ServiceLeadAssignment');
    }

    private function saveStatus(string $key, string $status, string $note = ''): Response
    {
        $fields = $this->editFields($key);
        $fields['ServiceLeadAssignment[status]'] = $status;
        $fields['ServiceLeadAssignment[statusNote]'] = $note;

        return $this->http('POST', $this->url('/partner/partner-lead/' . $this->ids[$key] . '/edit'), self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
    }
}
