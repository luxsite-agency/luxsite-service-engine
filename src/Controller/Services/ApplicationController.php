<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Controller\Api\Admin\ImageApiController;
use App\Entity\ServiceApplication;
use App\ServiceSite\ApplicationNotifier;
use App\ServiceSite\Applications;
use App\ServiceSite\LeadFiles;
use App\ServiceSite\LeadRateLimit;
use App\ServiceSite\ServiceCityAware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Анкета (app.applications.<форма>): POST /application/<форма>, multipart. Захист — як у /lead: CSRF sv_application,
 * пастка website, той самий ліміт частоти за IP. Приймаються лише описані у формі поля; файли — з лімітами форми.
 * Не CRM і не конверсія: успіх — /thank-you/?src=app-<форма> (подія application_submit, текст thanks.text.<форма>).
 */
final class ApplicationController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Applications $applications,
        private readonly ApplicationNotifier $notifier,
        private readonly LeadFiles $files,
        private readonly LeadRateLimit $rateLimit,
    ) {
    }

    #[Route(
        path: '/application/{form}',
        name: 'sv_application',
        requirements: ['form' => '[a-z0-9_-]{1,32}'],
        methods: ['POST'],
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function submit(Request $request, string $form): Response
    {
        if (!$this->applications->has($form)) {
            throw $this->createNotFoundException('Такої форми нема.');
        }
        $city = $this->serviceCity($request);
        $back = $this->backUrl($request);
        $locale = str_starts_with((string) parse_url($back, \PHP_URL_PATH), '/ru/') ? 'ru' : 'uk';
        $ru = $locale === 'ru';

        $max = ImageApiController::iniBytes((string) ini_get('post_max_size'));
        if ($request->request->count() === 0 && $max > 0 && (int) $request->server->get('CONTENT_LENGTH', 0) > $max) {
            return $this->fail($back, $ru ? 'Файлы слишком большие, уменьшите их или отправьте без них.' : 'Файли завеликі, зменште їх або надішліть без них.');
        }
        if (!$this->isCsrfTokenValid('sv_application', (string) $request->request->get('_token'))) {
            return $this->fail($back, $ru ? 'Сессия устарела, попробуйте ещё раз.' : 'Сесія застаріла, спробуйте ще раз.');
        }
        if ((string) $request->request->get('website', '') !== '') {
            return $this->redirect($back);
        }

        $input = $this->applications->read($form, $request, $locale);
        if ($input['error'] !== null) {
            return $this->fail($back, $input['error']);
        }
        $limits = $this->applications->files($form);
        $uploads = $limits !== null ? LeadFiles::fromRequest($request->files->get('files')) : [];
        $store = $limits !== null ? $this->files->withConfig($limits) : $this->files;
        if (($fileError = $store->validate($uploads, $locale)) !== null) {
            return $this->fail($back, $fileError);
        }
        if (!$this->rateLimit->isAccepted($request)) {
            return $this->fail($back, $ru ? 'Слишком много отправок подряд — попробуйте позже.' : 'Забагато відправок поспіль — спробуйте пізніше.');
        }

        $application = (new ServiceApplication())
            ->setCity($city)
            ->setForm($form)
            ->setName($input['name'])
            ->setPhone($input['phone'])
            ->setEmail($input['email'])
            ->setData($input['data'])
            ->setConsent($input['consent'])
            ->setPageUrl(mb_substr($back, 0, 512))
            ->setLocale($locale);
        try {
            $stored = $store->store($uploads);
        } catch (\Throwable) {
            return $this->fail($back, $ru ? 'Не удалось сохранить файлы, попробуйте ещё раз.' : 'Не вдалося зберегти файли, спробуйте ще раз.');
        }
        foreach ($stored as $file) {
            $application->addFile($file);
        }
        $this->em->persist($application);
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $store->discard($stored);
            throw $e;
        }
        $this->notifier->notify($application, $request->getHost());

        return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => 'app-' . $form]);
    }

    private function fail(string $back, string $message): Response
    {
        $this->addFlash('application_error', $message);

        return $this->redirect($back);
    }

    /** Як у LeadController: лише path+query з referer того самого хоста, інакше головна */
    private function backUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $host = parse_url($referer, \PHP_URL_HOST);
        if ($host !== null && strtolower((string) $host) === strtolower($request->getHost())) {
            $path = (string) (parse_url($referer, \PHP_URL_PATH) ?: '/');
            $query = parse_url($referer, \PHP_URL_QUERY);

            return $path . ($query ? '?' . $query : '');
        }

        return $this->generateUrl('sv_home', ['_locale' => 'uk']);
    }
}
