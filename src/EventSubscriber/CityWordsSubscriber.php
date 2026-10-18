<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceCity;
use App\Repository\ServiceCityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Підміна назви міста в спільному контенті бренду.
 *
 * Тексти послуг, FAQ і юридичні сторінки лежать на рівні сайту - одні на всі
 * міста, і написані вони під Київ («прибирання у Києві»). Міські піддомени
 * отримують ту саму сторінку, тому на виході підміняємо відмінки назви міста
 * на відмінки поточного міста, які вже лежать у service_city.
 *
 * Робиться на відповіді, а не у два десятки шаблонів: так жодне поле не
 * загубиться (desc, desc2, faq, meta, JSON-LD), а місто з назвою «Київ»
 * не зачіпається взагалі.
 */
final class CityWordsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ServiceCityRepository $cities)
    {
    }

    /** Прийменники, які українською перед голосною міняються: «у Одесі» → «в Одесі». */
    private const EUPHONY = ['у ' => 'в ', 'У ' => 'В '];

    private const VOWELS = 'АЕЄИІЇОУЮЯ';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $city = $event->getRequest()->attributes->get('service_city');
        if (!$city instanceof ServiceCity) {
            return;
        }

        $response = $event->getResponse();
        $type = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($type, 'text/html') && !str_contains($type, 'application/xml')) {
            return;
        }

        $map = $this->map($city);
        if ($map === []) {
            return;
        }

        $content = (string) $response->getContent();
        $replaced = $this->replaceOutsideRaw($content, $map);
        if ($replaced !== $content) {
            $response->setContent($replaced);
        }
    }


    /**
     * Підміна всюди, крім ділянок у маркерах <!--city-raw--> … <!--/city-raw-->.
     * Так перемикач міст лишається зі справжніми назвами: інакше на піддомені
     * Дніпра пункт «Київ» теж перетворився б на «Дніпро».
     *
     * @param array<string, string> $map
     */
    private function replaceOutsideRaw(string $content, array $map): string
    {
        $parts = preg_split(
            '/<!--city-raw-->(.*?)<!--\/city-raw-->/su',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if ($parts === false) {
            return strtr($content, $map);
        }

        $out = '';
        foreach ($parts as $i => $part) {
            // непарні елементи - це вміст маркерів, його лишаємо як є
            $out .= $i % 2 === 0 ? $this->replaceWords($part, $map) : $part;
        }

        return $out;
    }


    /**
     * Заміна лише цілих слів: в Одесі є Київський район, і підміняти «Київ»
     * усередині «Київського» не можна. Довші ключі йдуть першими, щоб
     * «у Києві» спрацював раніше за «Києві».
     *
     * @param array<string, string> $map
     */
    private function replaceWords(string $text, array $map): string
    {
        $keys = array_keys($map);
        usort($keys, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $patterns = [];
        $values = [];
        foreach ($keys as $key) {
            $patterns[] = '/(?<![\p{L}])' . preg_quote($key, '/') . '(?![\p{L}])/u';
            $values[] = str_replace('$', '\$', $map[$key]);
        }

        return (string) preg_replace($patterns, $values, $text);
    }

    /**
     * Відмінок → відмінок. Ключі з прийменником довші, тому strtr бере їх
     * першими - так «у Києві» стає «в Одесі», а не «у Одесі».
     * Порожня мапа = місто збігається з джерелом, підміняти нічого.
     *
     * @return array<string, string>
     */
    private function map(ServiceCity $city): array
    {
        $map = [];
        foreach ($this->source($city) as $locale => $forms) {
            $target = [
                'nom' => $city->loc('nameNom', $locale),
                'gen' => $city->loc('nameGen', $locale),
                'dat' => $city->loc('nameDat', $locale),
                'loc' => $city->loc('nameLoc', $locale),
            ];
            // без повного набору відмінків підміна дала б покруч - краще не чіпати
            if (\in_array(null, $target, true) || $target['nom'] === $forms['nom']) {
                continue;
            }

            foreach ($forms as $case => $word) {
                $word = (string) $word;
                $to = (string) $target[$case];
                if ($locale === 'uk' && $this->startsWithVowel($to)) {
                    foreach (self::EUPHONY as $from => $fixed) {
                        $map[$from . $word] = $fixed . $to;
                    }
                }
                $map[$word] = $to;
                $map[mb_strtolower($word)] = mb_strtolower($to);
            }
        }

        return $map;
    }

    /**
     * Місто, під яке написаний спільний контент бренду, - апекс-місто сайту з БД
     * (у перших сайтів це був Київ, зашитий у код). Відмінки беруться
     * з service_city апекса; якщо апекса нема або поточне місто і є апекс - підміни нема.
     *
     * @return array<string, array<string, string|null>> locale => [nom, gen, dat, loc]
     */
    private function source(ServiceCity $city): array
    {
        $site = $city->getSite();
        $apex = $site !== null ? $this->cities->findActiveApex($site) : null;
        if ($apex === null || $apex->getId() === $city->getId()) {
            return [];
        }

        $forms = [];
        foreach (['uk', 'ru'] as $locale) {
            $set = [
                'nom' => $apex->loc('nameNom', $locale),
                'gen' => $apex->loc('nameGen', $locale),
                'dat' => $apex->loc('nameDat', $locale),
                'loc' => $apex->loc('nameLoc', $locale),
            ];
            if (!\in_array(null, $set, true) && !\in_array('', $set, true)) {
                $forms[$locale] = $set;
            }
        }

        return $forms;
    }

    private function startsWithVowel(string $word): bool
    {
        return mb_strpos(self::VOWELS, mb_substr($word, 0, 1)) !== false;
    }
}
