<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCategory;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServicePostTag;
use App\Entity\ServiceSite;
use App\ServiceSite\LocalizedRoutes;
use App\ServiceSite\SiteLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Мова de (08.11.2026): поля *De читаються за локаллю de; порожнє німецьке поле НЕ підміняється українським
 * (як en), тоді як фолбек ru→uk і поведінка при типовому [uk, ru] лишаються такими, як були.
 */
final class DeLocaleFieldsTest extends TestCase
{
    public function testUkAndDeValuesAreReadByLocale(): void
    {
        $cat = (new ServiceCategory())->setTitleUk('Прибирання')->setTitleDe('Reinigung')->setSlugUk('prybyrannia')->setSlugDe('reinigung')
            ->setLeadUk('Лід uk')->setLeadDe('Lead de')->setMetaTitleUk('Meta uk')->setMetaTitleDe('Meta de')
            ->setFaqUk([['q' => 'Питання', 'a' => 'Відповідь']])->setFaqDe([['q' => 'Frage', 'a' => 'Antwort']]);

        self::assertSame('Прибирання', $cat->loc('title', 'uk'));
        self::assertSame('Reinigung', $cat->loc('title', 'de'));
        self::assertSame('prybyrannia', $cat->slug('uk'));
        self::assertSame('reinigung', $cat->slug('de'));
        self::assertSame('Lead de', $cat->loc('lead', 'de'));
        self::assertSame('Meta de', $cat->loc('metaTitle', 'de'));
        self::assertSame([['q' => 'Frage', 'a' => 'Antwort']], $cat->loc('faq', 'de'));
        self::assertSame('Reinigung', $cat->locRaw('title', 'de'), 'locRaw читає ту саму колонку');
    }

    public function testEmptyDeIsNotReplacedByUk(): void
    {
        $item = (new ServiceItem())->setTitleUk('Хімчистка')->setSlugUk('khimchystka')->setLeadUk('Лід')->setDescUk('Опис')
            ->setMetaTitleUk('Meta')->setFaqUk([['q' => 'q', 'a' => 'a']]);

        foreach (['title', 'lead', 'desc', 'metaTitle', 'faq'] as $property) {
            self::assertNull($item->loc($property, 'de'), "$property: порожнє de = null, не uk");
        }
        self::assertSame('', $item->slug('de'), 'слаг de без значення — порожній, адреси de нема');
        self::assertSame('', (new ServiceItem())->setTitleUk('Х')->setTitleDe('')->loc('title', 'de'), 'порожній рядок de теж не підміняється українським');

        // пост/рубрика/тег існують німецькою лише зі своїм слагом і заголовком
        $post = (new ServicePost())->setSlugUk('stattia')->setTitleUk('Стаття')->setSlugDe('artikel');
        self::assertFalse($post->existsIn('de'), 'слаг без заголовка — статті de нема');
        self::assertTrue($post->setTitleDe('Artikel')->existsIn('de'));
        self::assertTrue($post->existsIn('uk'));
        self::assertNull($post->imageAlt('de'), 'alt обкладинки de без фолбеку');
        self::assertSame('Alt', $post->setImageAltDe('Alt')->imageAlt('de'));

        self::assertFalse((new ServicePostCategory())->setSlugUk('r')->setTitleUk('Р')->existsIn('de'));
        self::assertTrue((new ServicePostCategory())->setSlugDe('r')->setTitleDe('R')->existsIn('de'), 'рубрика лише німецькою — валідна мова');
        self::assertTrue((new ServicePostTag())->setSlugDe('t')->setTitleDe('T')->existsIn('de'));
    }

    public function testRuFallbackAndUkRuBehaviourUnchanged(): void
    {
        $cat = (new ServiceCategory())->setTitleUk('Прибирання')->setSlugUk('prybyrannia')->setMetaTitleUk('Meta uk')->setLeadUk('Лід');
        self::assertSame('Прибирання', $cat->loc('title', 'ru'), 'ru без свого значення → uk, як було');
        self::assertSame('prybyrannia', $cat->slug('ru'));
        self::assertNull($cat->loc('metaTitle', 'ru'), 'SEO-поля ru без фолбеку, як було');
        self::assertSame('Уборка', $cat->setTitleRu('Уборка')->loc('title', 'ru'));
        self::assertTrue($cat->locFallsBack('title'));
        self::assertFalse($cat->locFallsBack('metaDesc'));
        self::assertSame('Прибирання', $cat->loc('title', 'xx'), 'невідома мова — як uk, як було');

        $site = (new ServiceSite())->setBrandNameUk('Бренд')->setBrandNameRu('Брэнд');
        self::assertSame('Бренд', $site->brandName('uk'));
        self::assertSame('Брэнд', $site->brandName('ru'));
        self::assertSame('Бренд', $site->brandName('en'), 'en без свого — uk, як було');
        self::assertSame('Бренд', $site->brandName('de'), 'коротка назва бренду: порожнє de → uk (назва бренду не контент)');
        self::assertSame('Marke', $site->setBrandNameDe('Marke')->brandName('de'));
        self::assertSame('Брэнд', $site->brandName('ru'));

        // мова слага для каталогу/редиректів: uk/ru/en дають те саме, що колишнє `=== 'ru' ? 'ru' : 'uk'`
        foreach (['uk' => 'uk', 'ru' => 'ru', 'en' => 'uk', null => 'uk', 'xx' => 'uk'] as $in => $want) {
            self::assertSame($want, SiteLocales::slugLocale($in));
        }
        self::assertSame('de', SiteLocales::slugLocale('de'));
    }

    public function testPageSlugDeTrimmedLikeRu(): void
    {
        $page = (new ServicePage())->setSlugUk('kontakty')->setSlugRu(' kontakty-ru ')->setSlugDe(' kontakt ');
        self::assertSame('kontakty-ru', $page->getSlugRu());
        self::assertSame('kontakt', $page->getSlugDe());
        self::assertNull($page->setSlugDe('  ')->getSlugDe(), 'лише пробіли = null, як у ru');
        self::assertSame('kontakty', $page->path('uk'));
        self::assertSame('', $page->path('de'), 'без slugDe шляху de нема');
    }

    public function testRoutesUkRuUnchangedAndUkDePrefixed(): void
    {
        $c = new RouteCollection();
        foreach (['sv_home' => '/', 'sv_blog_post' => '/blog/{slug}/'] as $name => $path) {
            foreach (['uk' => $path, 'ru' => '/ru' . $path] as $locale => $p) {
                $c->add($name . '.' . $locale, new Route($p, ['_locale' => $locale, '_canonical_route' => $name], ['_locale' => $locale]), 10);
            }
        }
        self::assertSame($c, LocalizedRoutes::apply($c, ['uk', 'ru']), '[uk, ru] — та сама колекція, маршрути сайтів не змінюються');

        $de = LocalizedRoutes::apply($c, ['uk', 'de']);
        self::assertSame(['sv_home.uk', 'sv_home.de', 'sv_blog_post.uk', 'sv_blog_post.de'], array_keys($de->all()));
        self::assertSame('/', $de->get('sv_home.uk')?->getPath());
        self::assertSame('/de/', $de->get('sv_home.de')?->getPath());
        self::assertSame('/de/blog/{slug}/', $de->get('sv_blog_post.de')?->getPath());
        self::assertSame('de', $de->get('sv_blog_post.de')?->getDefault('_locale'));
        self::assertNull($de->get('sv_home.ru'));

        $locales = new SiteLocales(new RequestStack(), ['uk', 'de'], ['de' => ['hreflang' => 'de', 'og' => 'de_DE', 'schema' => 'de', 'label' => 'DE']]);
        self::assertSame(['uk', 'de'], $locales->enabled());
        self::assertSame('uk', $locales->default());
        self::assertSame('de', $locales->ofPath('/de/kontakt/'));
        self::assertSame('uk', $locales->ofPath('/kontakt/'));
        self::assertSame('de_DE', $locales->code('de', 'og'));
        self::assertSame('DE', $locales->code('de', 'label'));
    }
}
