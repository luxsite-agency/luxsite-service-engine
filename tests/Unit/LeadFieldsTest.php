<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\ServiceSite\LeadFields;
use App\ServiceSite\LeadOptionSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadFieldsTest extends TestCase
{
    private const TODAY = '2026-10-20';

    private static function source(string $name, array $options): LeadOptionSource
    {
        return new class($name, $options) implements LeadOptionSource {
            public int $calls = 0;

            public function __construct(private readonly string $n, private readonly array $o)
            {
            }

            public function name(): string
            {
                return $this->n;
            }

            public function options(ServiceCity $city): array
            {
                ++$this->calls;

                return $this->o;
            }
        };
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    /** Запис на прийом: послуга міста, лікар, свій список, дата, email, вага з одиницею. */
    private function clinic(array $extra = [], ?array $sources = null): LeadFields
    {
        return new LeadFields(array_merge([
            ['key' => 'service', 'label' => ['uk' => 'Послуга', 'ru' => 'Услуга'], 'type' => 'select', 'source' => 'services', 'required' => true, 'group' => ['uk' => 'Запис', 'ru' => 'Запись']],
            ['key' => 'doctor', 'label' => ['uk' => 'Лікар', 'ru' => 'Врач'], 'type' => 'select', 'source' => 'team', 'placeholder' => ['uk' => 'Будь-який']],
            ['key' => 'time', 'label' => ['uk' => 'Час'], 'type' => 'select', 'options' => ['Ранок', ['value' => 'evening', 'label' => ['uk' => 'Вечір', 'ru' => 'Вечер']]]],
            ['key' => 'date', 'label' => ['uk' => 'Бажана дата', 'ru' => 'Желаемая дата'], 'type' => 'date', 'required' => true, 'min_days' => 1, 'max_days' => 30],
            ['key' => 'email', 'label' => ['uk' => 'E-mail'], 'type' => 'email'],
            ['key' => 'weight', 'label' => ['uk' => 'Вага', 'ru' => 'Вес'], 'type' => 'number', 'unit' => ['uk' => 'кг']],
        ], $extra), $sources ?? [
            self::source('services', [
                ['value' => 'uzi', 'label' => ['uk' => 'УЗД', 'ru' => 'УЗИ']],
                ['value' => 'gynecology', 'label' => ['uk' => 'Консультація гінеколога', 'ru' => 'Консультация гинеколога']],
            ]),
            self::source('team', [['value' => '7', 'label' => ['uk' => 'Іваненко Олена (гінеколог)', 'ru' => 'Иваненко Елена (гинеколог)']]]),
        ]);
    }

    public function testEmptyConfigKeepsFormAsIs(): void
    {
        $f = new LeadFields();
        self::assertFalse($f->enabled());
        self::assertSame([], $f->forForm('uk', new ServiceCity()));
        self::assertSame([[], []], $f->validate(['x' => 'y'], new ServiceCity()));
        self::assertSame('', $f->summary(['x' => 'y']));
        self::assertNull($f->emailKey());
        self::assertNull($f->serviceKey());
    }

    /** @return iterable<string, array{0: list<array<string, mixed>>}> */
    public static function badConfigs(): iterable
    {
        yield 'ключ з великої' => [[['key' => 'Bad']]];
        yield 'ключ форми' => [[['key' => 'phone']]];
        yield 'ключ згоди' => [[['key' => 'consent']]];
        yield 'дубль' => [[['key' => 'a'], ['key' => 'a']]];
        yield 'тип' => [[['key' => 'a', 'type' => 'textarea']]];
        yield 'select без джерела' => [[['key' => 'a', 'type' => 'select']]];
        yield 'list без варіантів' => [[['key' => 'a', 'type' => 'select', 'source' => 'list']]];
        yield 'дубль варіанта' => [[['key' => 'a', 'type' => 'select', 'options' => ['X', 'X']]]];
        yield 'дата min > max' => [[['key' => 'a', 'type' => 'date', 'min_days' => 5, 'max_days' => 1]]];
    }

    #[DataProvider('badConfigs')]
    public function testBadConfigFailsLoudly(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LeadFields($config);
    }

    public function testFormFieldsForLocaleCityAndPreset(): void
    {
        $form = $this->clinic()->forForm('ru', new ServiceCity(), ['services' => 'gynecology'], self::today());
        self::assertSame(['service', 'doctor', 'time', 'date', 'email', 'weight'], array_column($form, 'key'));

        [$service, $doctor, $time, $date, , $weight] = $form;
        self::assertSame('details[service]', $service['name']);
        self::assertSame('Услуга', $service['label']);
        self::assertSame('Запись', $service['group']);
        self::assertSame([false, true], array_column($service['options'], 'selected'));
        self::assertSame('Консультация гинеколога', $service['options'][1]['label']);
        self::assertSame('Иваненко Елена (гинеколог)', $doctor['options'][0]['label']);
        self::assertSame('Будь-який', $doctor['placeholder'], 'ru порожнє — береться uk');
        self::assertSame(['Ранок', 'evening'], array_column($time['options'], 'value'));
        self::assertSame(['2026-10-21', '2026-11-19'], [$date['min'], $date['max']]);
        self::assertSame('Вес, кг', $weight['label_full']);
        self::assertNull($weight['min']);
    }

    public function testUnknownOrEmptySourceHidesFieldInsteadOfBreakingForm(): void
    {
        $f = $this->clinic(
            [['key' => 'branch', 'label' => ['uk' => 'Філія'], 'type' => 'select', 'source' => 'branches', 'required' => true]],
            [self::source('services', []), self::source('team', [['value' => '7', 'label' => ['uk' => 'Іваненко', 'ru' => '']]])],
        );
        $form = $f->forForm('uk', new ServiceCity(), [], self::today());
        // services порожній у місті, branches рушій не знає — полів нема, обов'язковість не діє
        self::assertSame(['doctor', 'time', 'date', 'email', 'weight'], array_column($form, 'key'));
        [$clean, $errors] = $f->validate(['date' => '2026-10-22'], new ServiceCity(), self::today());
        self::assertSame([], $errors);
        self::assertSame(['date' => '2026-10-22'], $clean);

        // без міста запиту варіантів джерела нема (свій список лишається)
        self::assertSame(['time', 'date', 'email', 'weight'], array_column($f->forForm('uk', null, [], self::today()), 'key'));
    }

    public function testOptionsAreReadOncePerCityUntilReset(): void
    {
        $team = self::source('team', [['value' => '7', 'label' => ['uk' => 'Іваненко', 'ru' => '']]]);
        $f = $this->clinic([], [self::source('services', []), $team]);
        $city = new ServiceCity();
        $f->forForm('uk', $city);
        $f->forForm('ru', $city);
        $f->validate(['doctor' => '7'], $city);
        self::assertSame(1, $team->calls);
        $f->reset();
        $f->forForm('uk', $city);
        self::assertSame(2, $team->calls);
    }

    public function testValidateStoresReadableValues(): void
    {
        [$clean, $errors] = $this->clinic()->validate([
            'service' => 'gynecology',
            'doctor' => '7',
            'time' => 'evening',
            'date' => '2026-10-25',
            'email' => '  patient@example.com ',
            'weight' => '12,5',
            'unknown' => 'ignored',
        ], new ServiceCity(), self::today());

        self::assertSame([], $errors);
        self::assertSame([
            'service' => 'Консультація гінеколога',
            'doctor' => 'Іваненко Олена (гінеколог)',
            'time' => 'Вечір',
            'date' => '2026-10-25',
            'email' => 'patient@example.com',
            'weight' => '12.5',
        ], $clean);
    }

    public function testValidateRejectsWhatFormCouldNotSend(): void
    {
        [$clean, $errors] = $this->clinic()->validate([
            'service' => 'not-in-this-city',
            'doctor' => ['7'],
            'time' => 'Ніч',
            'date' => '2026-02-30',
            'email' => 'not-an-email',
            'weight' => '12 кг',
        ], new ServiceCity(), self::today());
        self::assertSame(['service', 'time', 'date', 'email', 'weight'], $errors);
        self::assertSame([], $clean, 'масив замість рядка = порожнє необов\'язкове поле');

        [, $errors] = $this->clinic()->validate([], new ServiceCity(), self::today());
        self::assertSame(['service', 'date'], $errors, 'обов\'язкові');

        [, $errors] = $this->clinic()->validate(['service' => 'uzi', 'weight' => str_repeat('1', 300)], new ServiceCity(), self::today());
        self::assertSame(['date', 'weight'], $errors);
    }

    public function testDateRangeAllowsOneDayForTimezones(): void
    {
        $f = new LeadFields([['key' => 'date', 'type' => 'date', 'min_days' => 0, 'max_days' => 10]]);
        $check = static fn (string $d): array => $f->validate(['date' => $d], null, self::today())[1];
        self::assertSame([], $check('2026-10-19'), 'учора за поясом браузера');
        self::assertSame(['date'], $check('2026-10-18'));
        self::assertSame([], $check('2026-10-31'));
        self::assertSame(['date'], $check('2026-11-01'));
        self::assertSame(['date'], $check('20.10.2026'));
    }

    public function testSummaryForManager(): void
    {
        $summary = $this->clinic()->summary([
            'weight' => '12.5',
            'date' => '2026-10-25',
            'service' => 'УЗД',
            'email' => '',
            'gone' => 'поле прибрали з паку',
        ]);
        self::assertSame("Послуга: УЗД\nБажана дата: 25.10.2026\nВага: 12.5 кг", $summary);
    }

    public function testCrmKeys(): void
    {
        self::assertSame('email', $this->clinic()->emailKey());
        self::assertSame('service', $this->clinic()->serviceKey());
    }
}
