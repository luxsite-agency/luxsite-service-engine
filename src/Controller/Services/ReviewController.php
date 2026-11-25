<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Entity\ServiceReview;
use App\Entity\User;
use App\Repository\ServiceReviewRepository;
use App\ServiceSite\ServiceCityAware;
use App\ServiceSite\SiteLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Прийом відгуків від Google-юзерів (патерн city-sites): CSRF + rate limit,
 * один відгук на місто від юзера, ПРЕМОДЕРАЦІЯ (active=false до схвалення
 * в адмінці «Відгуки клієнтів»). Незалогінених entry point веде на Google.
 */
final class ReviewController extends AbstractController
{
    use ServiceCityAware;

    private const MIN_TEXT_LENGTH = 20;
    private const MAX_TEXT_LENGTH = 2000;

    public function __construct(
        private readonly ServiceReviewRepository $reviews,
        private readonly EntityManagerInterface $em,
        #[Target('service_review_ip')]
        private readonly RateLimiterFactoryInterface $reviewIpLimiter,
    ) {
    }

    #[Route(
        path: '/reviews/submit',
        name: 'sv_review_submit',
        methods: ['POST'],
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    #[IsGranted('ROLE_USER')]
    public function submit(Request $request): Response
    {
        $city = $this->serviceCity($request);
        $back = $this->backUrlRaw($request);

        if (!$this->isCsrfTokenValid('sv_review', (string) $request->request->get('_token'))) {
            $this->addFlash('review_error', 'Сесія застаріла, спробуйте ще раз.');

            return $this->redirect($back);
        }

        if (!$this->reviewIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->addFlash('review_error', 'Забагато запитів. Спробуйте пізніше.');

            return $this->redirect($back);
        }

        /** @var User $user */
        $user = $this->getUser();

        $stars = $request->request->getInt('stars');
        $text = trim((string) $request->request->get('text', ''));
        if ($stars < 1 || $stars > 5) {
            $this->addFlash('review_error', 'Оцінка має бути від 1 до 5.');

            return $this->redirect($back);
        }
        if (mb_strlen($text) < self::MIN_TEXT_LENGTH || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $this->addFlash('review_error', sprintf('Текст відгуку - від %d до %d символів.', self::MIN_TEXT_LENGTH, self::MAX_TEXT_LENGTH));

            return $this->redirect($back);
        }

        if ($this->reviews->findOneBy(['user' => $user, 'city' => $city]) !== null) {
            $this->addFlash('review_error', 'Ви вже залишали відгук у цьому місті.');

            return $this->redirect($back);
        }

        $review = (new ServiceReview())
            ->setCity($city)
            ->setUser($user)
            ->setAuthorUk($user->getDisplayName() ?? 'Клієнт')
            ->setStars($stars)
            ->setReviewedAt(new \DateTimeImmutable())
            ->setAvatarUrl($user->getAvatarUrl())
            ->setActive(false); // премодерація
        // текст зберігаємо мовою автора в textUk (основне поле показу);
        // ru-сторінки віддадуть його через фолбек loc, переклад — за модератором
        $review->setTextUk($text);
        $this->em->persist($review);
        $this->em->flush();

        $this->addFlash('review_success', 'Дякуємо за відгук!');

        return $this->redirect($back);
    }

    /** Повернення на сторінку відгуків поточної локалі (path+query того ж хоста) */
    private function backUrlRaw(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $host = parse_url($referer, PHP_URL_HOST);
        if ($host !== null && strtolower((string) $host) === strtolower($request->getHost())) {
            $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
            $query = parse_url($referer, PHP_URL_QUERY);

            return $path . ($query ? '?' . $query : '');
        }

        return $this->generateUrl('sv_reviews', ['_locale' => $this->container->get(SiteLocales::class)->default()]);
    }
}
