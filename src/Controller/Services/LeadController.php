<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\ServiceSite\LeadAttribution;
use App\Entity\ServiceLead;
use App\Repository\ServiceLeadRepository;
use App\ServiceSite\LeadNotifier;
use App\ServiceSite\Crm\LeadCrmConnector;
use App\ServiceSite\LeadRateLimit;
use App\ServiceSite\ServiceCityAware;
use App\ServiceSite\SiteLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Заявка з форми (телефон + ім'я + повідомлення). Виправляє типовий борг
 * Joomla-сайтів: персистенція в БД, маршрутизація на email МІСТА (не хардкод),
 * rate limit + honeypot + CSRF. Мейл шле LeadNotifier (best effort: заявка
 * вже в БД, падіння SMTP її не втрачає).
 */
final class LeadController extends AbstractController
{
    use ServiceCityAware;

    /** Вікно, у якому заявка з тим самим номером у місті вважається дублем, с. */
    public const DUPLICATE_WINDOW = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceLeadRepository $leads,
        private readonly LeadNotifier $notifier,
        private readonly LeadAttribution $attribution,
        private readonly LeadCrmConnector $crm,
        private readonly LeadRateLimit $rateLimit,
    ) {
    }

    #[Route(
        path: '/lead',
        name: 'sv_lead',
        methods: ['POST'],
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function submit(Request $request): Response
    {
        $city = $this->serviceCity($request);
        $back = $this->backUrl($request);
        // /lead нелокалізований, тож локаль беремо зі сторінки-джерела, не з request
        $locale = str_starts_with((string) parse_url($back, PHP_URL_PATH), '/ru/') ? 'ru' : 'uk';

        if (!$this->isCsrfTokenValid('sv_lead', (string) $request->request->get('_token'))) {
            $this->addFlash('lead_error', 'Сесія застаріла, спробуйте ще раз.');

            return $this->redirect($back);
        }

        // honeypot: приховане поле, яке заповнюють лише боти
        if ((string) $request->request->get('website', '') !== '') {
            return $this->redirect($back);
        }

        $phone = trim((string) $request->request->get('phone', ''));
        $name = trim((string) $request->request->get('name', ''));
        $message = trim((string) $request->request->get('message', ''));

        $phoneDigits = preg_replace('/\D/', '', $phone) ?? '';
        if (\strlen($phoneDigits) < 9 || \strlen($phoneDigits) > 15
            || mb_strlen($name) > 128 || mb_strlen($message) > 3000) {
            $this->addFlash('lead_error', 'Перевірте номер телефону.');

            return $this->redirect($back);
        }

        // Подвійне натискання / повторна відправка: та сама людина вже лишила заявку
        // в цьому місті щойно. Другу не створюємо, лист і CRM не дублюємо, людину ведемо
        // на подяку (src=dup - без повторної конверсії в GA4). Перевірка ДО rate limit,
        // щоб дубль не з'їдав ліміт (перенесено з першого сайту на рушії).
        if ($this->leads->findRecentDuplicate($city, $phone, self::DUPLICATE_WINDOW) !== null) {
            return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => 'dup']);
        }

        if (!$this->rateLimit->isAccepted($request)) {
            $this->addFlash('lead_error', 'Забагато заявок поспіль - спробуйте пізніше або подзвоніть нам.');

            return $this->redirect($back);
        }

        $lead = (new ServiceLead())
            ->setCity($city)
            ->setPhone(mb_substr($phone, 0, 32))
            ->setName($name !== '' ? $name : null)
            ->setMessage($message !== '' ? $message : null)
            ->setPageUrl(mb_substr($back, 0, 512))
            ->setLocale($locale);
        $this->attribution->apply($request, $lead);
        $this->em->persist($lead);
        $this->em->flush();

        $this->notifier->notify($lead, 'Заявка', $request->getHost());
        // CRM сайту (app.crm): best effort, помилку конектор пише в заявку, добере app:crm:sync
        $this->crm->push($lead);

        // успіх веде на сенкю-сторінку (ціль конверсії), помилки лишаються флешами на формі;
        // src = тип форми (form | magnet — лід-магніт головної) → lead_type у dataLayer, щоб GA4 бачив, звідки заявка
        $type = (string) $request->request->get('lead_type', 'form');

        return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => \in_array($type, ['form', 'magnet'], true) ? $type : 'form']);
    }

    /**
     * Повертаємо на сторінку форми. Від referer беремо ТІЛЬКИ path+query і лише
     * при збігу хоста (open redirect / підміна схеми виключені). Без referer
     * (Referrer-Policy: no-referrer) — на головну; sv_home локалізований, тож
     * _locale обов'язковий, інакше RouteNotFoundException.
     */
    private function backUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $host = parse_url($referer, PHP_URL_HOST);

        if ($host !== null && strtolower((string) $host) === strtolower($request->getHost())) {
            $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
            $query = parse_url($referer, PHP_URL_QUERY);

            return $path . ($query ? '?' . $query : '');
        }

        return $this->generateUrl('sv_home', ['_locale' => $this->container->get(SiteLocales::class)->default()]);
    }
}
