<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\ServiceSite\LeadAttribution;
use App\Entity\ServiceCalcFactor;
use App\Entity\ServiceCalcOption;
use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use App\Entity\ServiceSite as ServiceSiteEntity;
use App\Repository\ServiceCalcFactorRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceCalcOptionRepository;
use App\Repository\ServicePriceRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServiceLeadRepository;
use App\Repository\ServiceCalcTypeRepository;
use App\ServiceSite\CalcOption;
use App\ServiceSite\CalcPricer;
use App\ServiceSite\CalculatorAvailability;
use App\ServiceSite\CalculatorRoutes;
use App\ServiceSite\PricelistCalc;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\LeadNotifier;
use App\ServiceSite\Crm\LeadCrmConnector;
use App\ServiceSite\LeadRateLimit;
use App\ServiceSite\ServiceCityAware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Калькулятор вартості: сторінка з підрахунком наживо (JS інлайном у шаблоні) і прийом заявки з розрахунком.
 * Два режими (CalculatorAvailability): «за площею» (клінінг: типи прибирання × площа × коефіцієнти + опції, формула
 * CalcPricer) і «за прайсом» (категорія → послуга → позиції прайсу міста з обсягом, PricelistCalc). Маршрути
 * sv_calculator / sv_calc_lead збирає Kernel::configureRoutes через CalculatorRoutes (адреса з паку або типова за режимом).
 * Вартість для заявки рахується ЗАВЖДИ на сервері з БД - ціни з форми не приймаються (захист від підміни в DevTools).
 */
final class CalculatorController extends AbstractController
{
    use ServiceCityAware;

    /** Межі площі: і в HTML-атрибутах, і в бекенд-валідації */
    public const AREA_MIN = 10;
    public const AREA_MAX = 5000;

    public function __construct(
        private readonly ServiceCalcTypeRepository $types,
        private readonly ServiceCalcFactorRepository $factors,
        private readonly ServiceCalcOptionRepository $options,
        private readonly ServicePriceRepository $prices,
        private readonly ServiceItemRepository $items,
        private readonly ServiceDistrictRepository $districts,
        private readonly CalcPricer $pricer,
        private readonly EntityManagerInterface $em,
        private readonly LeadNotifier $notifier,
        private readonly LeadAttribution $attribution,
        private readonly LeadCrmConnector $crm,
        private readonly TranslatorInterface $translator,
        private readonly LeadRateLimit $rateLimit,
        private readonly CatalogUrls $catalogUrls,
        private readonly ServiceLeadRepository $leads,
        private readonly CalculatorAvailability $availability,
        private readonly PricelistCalc $pricelist,
    ) {
    }

    /**
     * Додаткові послуги для міста. Джерело - прайс; поки він не наповнений,
     * лишається стара таблиця опцій, інакше калькулятор втратив би половину
     * позицій у момент деплою.
     *
     * @return CalcOption[]
     */
    private function optionsFor(ServiceSiteEntity $site, ServiceCity $city): array
    {
        $fromPrices = $this->prices->findCalculatorOptions($city);

        return $fromPrices !== [] ? $fromPrices : $this->options->findActiveBySite($site);
    }

    public function index(Request $request): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $mode = $this->availability->modeFor($site, $city);
        if ($mode === null) {
            throw $this->createNotFoundException('Калькулятор ще не наповнено.');
        }
        // типова адреса іншого режиму (/calculator-cleaning/ ↔ /calculator/) → 301 на дійсну, query лишається
        if ($this->isForeignDefaultPath($request, $mode)) {
            $query = $request->getQueryString();

            return $this->redirect($this->generateUrl('sv_calculator', ['_locale' => $request->getLocale()]) . ($query !== null ? '?' . $query : ''), 301);
        }

        if ($mode === CalculatorAvailability::MODE_PRICELIST) {
            return $this->render('services/calculator_pricelist.html.twig', [
                'site' => $site,
                'city' => $city,
                'groups' => $this->pricelist->groups($this->prices->findPricelistCalculator($city)),
                'altUrls' => $this->altUrls('sv_calculator'),
            ]);
        }

        $types = $this->types->findActiveForCity($site, $city);

        return $this->render('services/calculator.html.twig', [
            'site' => $site,
            'city' => $city,
            'types' => $types,
            'factorGroups' => $this->factors->findActiveBySiteGrouped($site),
            'options' => $this->optionsFor($site, $city),
            // передвибір із міні-калькулятора головної (?type=ID&area=N)
            'presetType' => $request->query->getInt('type'),
            'presetArea' => $request->query->getInt('area'),
            'areaMin' => self::AREA_MIN,
            'areaMax' => self::AREA_MAX,
            'altUrls' => $this->altUrls('sv_calculator'),
        ]);
    }

    /**
     * Без app.calculator.path маршрут приймає оба типові сегменти ({_calc}); дійсний для режиму підставляє
     * CalculatorRouteSubscriber у RequestContext — звіряємо з ним сегмент запиту.
     */
    private function isForeignDefaultPath(Request $request, string $mode): bool
    {
        $segment = $request->attributes->get(CalculatorRoutes::PLACEHOLDER);
        if (!\is_string($segment)) {
            return false;
        }

        return $segment !== ($mode === CalculatorAvailability::MODE_PRICELIST ? CalculatorRoutes::SEGMENT_PRICELIST : CalculatorRoutes::SEGMENT_AREA);
    }

    /**
     * Заявка з калькулятора (обох режимів). Падає в загальну чергу ServiceLead, у message -
     * перерахований НА СЕРВЕРІ розрахунок (площа: тип, площа, коефіцієнти, опції, сума; прайс: позиції × обсяг, сума).
     */
    public function submit(Request $request): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $mode = $this->availability->modeFor($site, $city);
        if ($mode === null) {
            throw $this->createNotFoundException('Калькулятор ще не наповнено.');
        }
        $locale = $request->request->get('loc') === 'ru' ? 'ru' : 'uk';
        $fromHome = $request->request->get('from') === 'home';
        // калькулятор стоїть і на сторінках послуг: повертаємо людину туди, звідки
        // вона надіслала форму. Слаг звіряємо з БД, щоб не зробити відкритий редирект
        $svc = trim((string) $request->request->get('svc', ''));
        $item = $svc !== '' ? $this->items->findOneActiveBySiteAndSlug($site, $svc, $locale) : null;
        // те саме для району: слаг звіряємо з БД міста, тому підставити чужу адресу не вийде
        $dst = trim((string) $request->request->get('dst', ''));
        $district = $dst !== '' ? $this->districts->findOneActiveBySlug($city, $dst, $locale) : null;

        if ($item !== null) {
            $sourceUrl = $this->generateUrl('sv_service', $this->catalogUrls->itemParams($item, $locale) + ['_locale' => $locale]);
        } elseif ($district !== null) {
            $sourceUrl = $this->generateUrl('sv_district', ['slug' => $dst, '_locale' => $locale]);
        } else {
            $sourceUrl = $this->generateUrl($fromHome ? 'sv_home' : 'sv_calculator', ['_locale' => $locale]);
        }

        $back = $sourceUrl . ($item !== null || $district !== null || $fromHome ? '#calc-mini' : '');

        if (!$this->isCsrfTokenValid('sv_calc_lead', (string) $request->request->get('_token'))) {
            $this->addFlash('lead_error', 'Сесія застаріла, спробуйте ще раз.');

            return $this->redirect($back);
        }

        // honeypot: приховане поле, яке заповнюють лише боти
        if ((string) $request->request->get('website', '') !== '') {
            return $this->redirect($back);
        }

        if (!$this->rateLimit->isAccepted($request)) {
            $this->addFlash('lead_error', 'Забагато заявок поспіль - спробуйте пізніше або подзвоніть нам.');

            return $this->redirect($back);
        }

        $phone = trim((string) $request->request->get('phone', ''));
        $name = trim((string) $request->request->get('name', ''));
        $phoneDigits = preg_replace('/\D/', '', $phone) ?? '';
        if (\strlen($phoneDigits) < 9 || \strlen($phoneDigits) > 15 || mb_strlen($name) > 128) {
            $this->addFlash('lead_error', 'Перевірте номер телефону.');

            return $this->redirect($back);
        }

        // подвійне натискання: заявка з цим номером у місті вже є - другу не створюємо
        // (та сама логіка, що в LeadController; src=dup не рахує конверсію вдруге)
        if ($this->leads->findRecentDuplicate($city, $phone, LeadController::DUPLICATE_WINDOW) !== null) {
            return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => 'dup']);
        }

        if ($mode === CalculatorAvailability::MODE_PRICELIST) {
            // «за прайсом»: з форми лише id позиції → обсяг; позиції й ціни — з прайсу міста
            $quote = $this->pricelist->quote($this->prices->findPricelistCalculator($city), (array) $request->request->all('qty'));
            if ($quote['rows'] === []) {
                $this->addFlash('lead_error', $this->translator->trans('calc.pricelist.empty', [], 'servicesite', $locale));

                return $this->redirect($back);
            }
            $message = $this->pricelist->buildMessage($quote, $locale);

            return $this->storeLead($request, $city, $phone, $name, $message, $sourceUrl, $locale);
        }

        // Валідація розрахунку: всі позиції звіряються з БД по сайту, а ціна
        // типу береться саме міська - інакше заявка порахується за чужим прайсом
        $type = $this->types->findOneActiveForCity($site, $city, $request->request->getInt('type'));
        $area = $request->request->getInt('area');
        if ($type === null || $area < self::AREA_MIN || $area > self::AREA_MAX) {
            $this->addFlash('lead_error', 'Перевірте тип прибирання і площу.');

            return $this->redirect($back);
        }

        // коефіцієнти: беруться лише активні цього сайту, максимум один на групу
        $factorIds = array_map('intval', (array) $request->request->all('factors'));
        $chosenFactors = [];
        foreach ($this->factors->findActiveBySiteGrouped($site) as $group => $rows) {
            foreach ($rows as $row) {
                if (\in_array($row->getId(), $factorIds, true)) {
                    $chosenFactors[$group] = $row;
                    break;
                }
            }
        }

        // опції: qty з форми, обрізається в межі 0..maxQty
        $qtyByOption = (array) $request->request->all('opt');
        $chosenOptions = [];
        foreach ($this->optionsFor($site, $city) as $option) {
            $qty = (int) ($qtyByOption[$option->getId()] ?? 0);
            $qty = max(0, min($qty, $option->isPerUnit() ? $option->getMaxQty() : 1));
            if ($qty > 0) {
                $chosenOptions[] = ['option' => $option, 'qty' => $qty];
            }
        }

        $quote = $this->pricer->quote($type, $area, array_values($chosenFactors), $chosenOptions);
        $message = $this->buildMessage($locale, $type->loc('name', $locale), $area, $chosenFactors, $chosenOptions, $quote);
        if ($item !== null) {
            // менеджеру важливо бачити, з якої саме послуги прийшов розрахунок
            $message = $this->translator->trans('calc.service_label', [], 'servicesite', $locale)
                . ': ' . $item->loc('title', $locale) . "\n" . $message;
        }

        return $this->storeLead($request, $city, $phone, $name, $message, $sourceUrl, $locale);
    }

    /** Запис заявки, сповіщення, CRM і редирект на «дякуємо» — спільне для обох режимів. */
    private function storeLead(Request $request, ServiceCity $city, string $phone, string $name, string $message, string $sourceUrl, string $locale): Response
    {
        $lead = (new ServiceLead())
            ->setCity($city)
            ->setPhone(mb_substr($phone, 0, 32))
            ->setName($name !== '' ? $name : null)
            ->setMessage($message)
            ->setPageUrl(mb_substr($sourceUrl, 0, 512))
            ->setLocale($locale);
        $this->attribution->apply($request, $lead);
        $this->em->persist($lead);
        $this->em->flush();

        $this->notifier->notify($lead, 'Заявка з калькулятора', $request->getHost());
        // CRM сайту (app.crm): best effort, помилку конектор пише в заявку, добере app:crm:sync
        $this->crm->push($lead);

        return $this->redirectToRoute('sv_thanks', ['_locale' => $locale, 'src' => 'calc']);
    }

    /**
     * Текст розрахунку для message заявки (видно в адмінці й у мейлі).
     *
     * @param array<string, ServiceCalcFactor> $factors обрані коефіцієнти по групах
     * @param array<array{option: CalcOption, qty: int}> $options
     * @param array{base: int, optionsSum: int, total: int, minApplied: bool} $quote
     */
    /** «1.50» -> «1.5», «1.00» -> «1», але «10» лишається «10». */
    private static function trimMultiplier(string $multiplier): string
    {
        return str_contains($multiplier, '.')
            ? rtrim(rtrim($multiplier, '0'), '.')
            : $multiplier;
    }

    private function buildMessage(string $locale, string $typeName, int $area, array $factors, array $options, array $quote): string
    {
        $t = fn (string $key): string => $this->translator->trans($key, [], 'servicesite', $locale);

        $lines = [
            sprintf('%s: %s', $t('calc.type_label'), $typeName),
            sprintf('%s: %d %s', $t('calc.area_label'), $area, $t('calc.m2')),
        ];
        foreach ($factors as $group => $factor) {
            $lines[] = sprintf('%s: %s (x%s)', $t('calc.group.' . $group), $factor->loc('name', $locale), self::trimMultiplier($factor->getMultiplier()));
        }
        if ($options !== []) {
            $lines[] = $t('calc.options_title') . ':';
            foreach ($options as $row) {
                $option = $row['option'];
                $price = (int) ceil((float) $option->getPrice() * ($option->isPerUnit() ? $row['qty'] : 1));
                $qtyText = $option->isPerUnit()
                    ? sprintf(' x %d %s', $row['qty'], $option->loc('unit', $locale) ?? '')
                    : '';
                $lines[] = sprintf('- %s%s: %d %s', $option->loc('name', $locale), rtrim($qtyText), $price, $t('catalog.uah'));
            }
        }
        $lines[] = sprintf('%s: %s %d %s', $t('calc.total_label'), $t('prices.from'), $quote['total'], $t('catalog.uah'));

        return implode("\n", $lines);
    }
}
