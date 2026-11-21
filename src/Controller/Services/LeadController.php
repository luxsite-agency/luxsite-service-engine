<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Controller\Api\Admin\ImageApiController;
use App\ServiceSite\LeadAttribution;
use App\ServiceSite\LeadFiles;
use App\Entity\ServiceLead;
use App\Repository\ServiceLeadRepository;
use App\ServiceSite\LeadDispatcher;
use App\ServiceSite\LeadRateLimit;
use App\ServiceSite\LeadSpamGuard;
use App\ServiceSite\LeadSpamRecorder;
use App\ServiceSite\ServiceCityAware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Заявка з форми (телефон + ім'я + повідомлення; необов'язково — послуга service, контекст context і файли files[] з
 * app.lead_files, форма multipart/form-data). Виправляє типовий борг
 * Joomla-сайтів: персистенція в БД, маршрутизація на email МІСТА (не хардкод),
 * rate limit + CSRF + LeadSpamGuard (honeypot, мітка часу, сміття в полях; правила — app.lead_spam). Спам зберігається
 * зі spam_reason (LeadSpamRecorder) і на подяку веде без конверсії; звичайна заявка йде листом і в CRM (LeadDispatcher,
 * best effort: заявка вже в БД, падіння SMTP її не втрачає).
 */
final class LeadController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceLeadRepository $leads,
        private readonly LeadDispatcher $dispatcher,
        private readonly LeadAttribution $attribution,
        private readonly LeadRateLimit $rateLimit,
        private readonly LeadFiles $files,
        private readonly LeadSpamGuard $spamGuard,
        private readonly LeadSpamRecorder $spam,
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

        // тіло понад post_max_size PHP мовчки викидає разом із $_POST (і токеном) — кажемо про розмір, а не «сесія застаріла»
        $max = ImageApiController::iniBytes((string) ini_get('post_max_size'));
        if ($request->request->count() === 0 && $max > 0 && (int) $request->server->get('CONTENT_LENGTH', 0) > $max) {
            $this->addFlash('lead_error', $locale === 'ru' ? 'Файлы слишком большие, уменьшите их или отправьте без них.' : 'Файли завеликі, зменште їх або надішліть без них.');

            return $this->redirect($back);
        }

        if (!$this->isCsrfTokenValid('sv_lead', (string) $request->request->get('_token'))) {
            $this->addFlash('lead_error', 'Сесія застаріла, спробуйте ще раз.');

            return $this->redirect($back);
        }

        // honeypot, мітка часу показу форми, сміття в полях (SQL-проби сканерів), телефон, Turnstile - див. LeadSpamGuard.
        // Запис зберігається зі spam_reason (без пошти й CRM), людина бачить подяку без події конверсії
        if (null !== $reason = $this->spamGuard->blockReason($request, $locale)) {
            $this->spam->record($request, $city, $reason, 'lead', $back, $locale);

            return $this->spamResponse($reason, $back, $locale);
        }

        $phone = trim((string) $request->request->get('phone', ''));
        $name = trim((string) $request->request->get('name', ''));
        $message = trim((string) $request->request->get('message', ''));
        // послуга з кнопки «Замовити» і контекст форми — короткі рядки без розмітки
        $service = self::line($request->request->get('service', ''));
        $context = self::line($request->request->get('context', ''));

        // мінімум цифр у номері перевіряє LeadSpamGuard (app.lead_spam.phone_min_digits)
        if (\strlen(preg_replace('/\D/', '', $phone) ?? '') > 15 || mb_strlen($name) > 128 || mb_strlen($message) > 3000) {
            $this->addFlash('lead_error', 'Перевірте номер телефону.');

            return $this->redirect($back);
        }

        // файли: лише з app.lead_files.enabled; без нього поле ігнорується, як до файлів
        $uploads = $this->files->enabled() ? LeadFiles::fromRequest($request->files->get('files')) : [];
        if (($fileError = $this->files->validate($uploads, $locale)) !== null) {
            $this->addFlash('lead_error', $fileError);

            return $this->redirect($back);
        }

        // Подвійне натискання / повторна відправка: та сама людина вже лишила заявку
        // в цьому місті щойно. Другу не створюємо, лист і CRM не дублюємо, людину ведемо
        // на подяку (src=dup - без повторної конверсії в GA4). Перевірка ДО rate limit,
        // щоб дубль не з'їдав ліміт (перенесено з першого сайту на рушії). Вікно — app.lead_spam.dedup_seconds.
        if ($this->leads->findRecentDuplicate($city, $phone, $this->spamGuard->dedupSeconds()) !== null) {
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
            ->setService($service)
            ->setContext($context)
            ->setPageUrl(mb_substr($back, 0, 512))
            ->setLocale($locale);
        $this->attribution->apply($request, $lead);
        try {
            $stored = $this->files->store($uploads);
        } catch (\Throwable $e) {
            $this->addFlash('lead_error', $locale === 'ru' ? 'Не удалось сохранить файлы, попробуйте ещё раз или отправьте без них.' : 'Не вдалося зберегти файли, спробуйте ще раз або надішліть без них.');

            return $this->redirect($back);
        }
        foreach ($stored as $file) {
            $lead->addFile($file);
        }
        $this->em->persist($lead);
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->files->discard($stored);
            throw $e;
        }

        // лист менеджерам міста і CRM сайту (app.crm): best effort, помилку конектор пише в заявку, добере app:crm:sync
        $this->dispatcher->dispatch($lead, 'Заявка', $request->getHost());

        // успіх веде на сенкю-сторінку (ціль конверсії), помилки лишаються флешами на формі;
        // src = тип форми (form | magnet — лід-магніт головної) → lead_type у dataLayer, щоб GA4 бачив, звідки заявка
        $type = (string) $request->request->get('lead_type', 'form');
        // послуга й контекст — у dataLayer сторінки подяки (без телефону й імені: персональні дані туди не йдуть)
        if ($service !== null || $context !== null) {
            $this->addFlash('lead_meta', ['service' => $service, 'context' => $context]);
        }

        return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => \in_array($type, ['form', 'magnet'], true) ? $type : 'form']);
    }

    /**
     * Відповідь на спрацювання захисту. Людина з автозаповненням чи застарілою вкладкою (too-fast, time-old) або з коротким
     * номером (phone:short) бачить помилку й повторює - повтор проходить. Решта (бот) - звичайна подяка без події конверсії
     * (src=ok: /thank-you/ не шле app.lead_events), щоб не підбирали обхід.
     */
    private function spamResponse(string $reason, string $back, string $locale): Response
    {
        if (LeadSpamGuard::isRetryReason($reason)) {
            $this->addFlash('lead_error', str_starts_with($reason, 'phone') ? 'Перевірте номер телефону.' : 'Не вдалося надіслати, спробуйте ще раз.');

            return $this->redirect($back);
        }

        return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => 'ok']);
    }

    /** Короткий рядок з форми: без тегів і переносів, до 255; порожній — null */
    private static function line(mixed $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags(\is_scalar($value) ? (string) $value : '')));

        return $value !== '' ? mb_substr($value, 0, 255) : null;
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

        return $this->generateUrl('sv_home', ['_locale' => 'uk']);
    }
}
