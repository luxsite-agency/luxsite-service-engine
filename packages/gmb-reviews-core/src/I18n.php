<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace Luxsite\GmbReviews;

/** Тексти блоку й форми (ті самі, що в JS-ембеді resources/widget/v1.js, віддається через /w/v1.js). */
final class I18n
{
    private const STRINGS = [
        'uk' => ['thanksPending' => 'Дякуємо! Ваш відгук отримано. Він з’явиться на сайті після перевірки.', 'googleAsk' => 'Вам сподобалось? Поділіться враженнями ще й у Google — це дуже допомагає бізнесу.', 'err' => ['widget.form_disabled' => 'Форма відгуків зараз вимкнена.', 'widget.form_spam' => 'Не вдалося надіслати. Спробуйте ще раз за хвилину.', 'widget.form_rating' => 'Поставте оцінку від 1 до 5.', 'widget.form_name' => 'Вкажіть ваше ім’я.', 'widget.form_text_long' => 'Текст задовгий: до 2000 символів.', 'widget.form_text_short' => 'Розкажіть, будь ласка, кількома словами, що пішло не так — це допоможе бізнесу виправитись.', 'widget.form_too_many' => 'З цього пристрою вже надіслано кілька відгуків. Спробуйте пізніше.'], 'title' => 'Відгуки Google', 'titleMixed' => 'Всі відгуки', 'loadMore' => 'Показати ще', 'reviews' => ['відгук', 'відгуки', 'відгуків'], 'read' => 'Усі відгуки в Google', 'write' => 'Написати відгук', 'ownerReply' => 'Відповідь власника', 'fromSite' => 'з сайту', 'more' => 'Читати далі', 'rating' => 'Ваша оцінка', 'name' => 'Ваше ім’я', 'text' => 'Розкажіть про ваш досвід', 'contact' => 'Телефон або email (необов’язково, лише для зв’язку)', 'consent' => 'Надсилаючи, ви погоджуєтесь на обробку введених даних.', 'send' => 'Надіслати', 'sending' => 'Надсилаємо…', 'close' => 'Закрити', 'thanks' => 'Дякуємо! Ваш відгук отримано.', 'google' => 'Поділитися відгуком у Google', 'error' => 'Не вдалося надіслати. Спробуйте ще раз.', 'noReviews' => 'Відгуків ще немає.'],
        'en' => ['thanksPending' => 'Thank you! Your review has been received. It will appear on the website after a check.', 'googleAsk' => 'Enjoyed it? Share your experience on Google as well — it really helps the business.', 'err' => ['widget.form_disabled' => 'The review form is currently disabled.', 'widget.form_spam' => 'Could not send. Please try again in a minute.', 'widget.form_rating' => 'Please give a rating from 1 to 5.', 'widget.form_name' => 'Please enter your name.', 'widget.form_text_long' => 'The text is too long: up to 2000 characters.', 'widget.form_text_short' => 'Please tell us in a few words what went wrong — it helps the business improve.', 'widget.form_too_many' => 'Several reviews have already been sent from this device. Please try again later.'], 'title' => 'Google reviews', 'titleMixed' => 'All reviews', 'loadMore' => 'Show more', 'reviews' => ['review', 'reviews', 'reviews'], 'read' => 'All reviews on Google', 'write' => 'Write a review', 'ownerReply' => 'Owner’s reply', 'fromSite' => 'from the website', 'more' => 'Read more', 'rating' => 'Your rating', 'name' => 'Your name', 'text' => 'Tell us about your experience', 'contact' => 'Phone or email (optional, only to get back to you)', 'consent' => 'By sending you agree to the processing of the data you entered.', 'send' => 'Send', 'sending' => 'Sending…', 'close' => 'Close', 'thanks' => 'Thank you! Your review has been received.', 'google' => 'Share your review on Google', 'error' => 'Could not send. Please try again.', 'noReviews' => 'No reviews yet.'],
        'ru' => ['thanksPending' => 'Спасибо! Ваш отзыв получен. Он появится на сайте после проверки.', 'googleAsk' => 'Понравилось? Поделитесь впечатлениями и в Google — это очень помогает бизнесу.', 'err' => ['widget.form_disabled' => 'Форма отзывов сейчас отключена.', 'widget.form_spam' => 'Не удалось отправить. Попробуйте ещё раз через минуту.', 'widget.form_rating' => 'Поставьте оценку от 1 до 5.', 'widget.form_name' => 'Укажите ваше имя.', 'widget.form_text_long' => 'Текст слишком длинный: до 2000 символов.', 'widget.form_text_short' => 'Расскажите, пожалуйста, в нескольких словах, что пошло не так — это поможет бизнесу исправиться.', 'widget.form_too_many' => 'С этого устройства уже отправлено несколько отзывов. Попробуйте позже.'], 'title' => 'Отзывы Google', 'titleMixed' => 'Все отзывы', 'loadMore' => 'Показать ещё', 'reviews' => ['отзыв', 'отзыва', 'отзывов'], 'read' => 'Все отзывы в Google', 'write' => 'Написать отзыв', 'ownerReply' => 'Ответ владельца', 'fromSite' => 'с сайта', 'more' => 'Читать дальше', 'rating' => 'Ваша оценка', 'name' => 'Ваше имя', 'text' => 'Расскажите о вашем опыте', 'contact' => 'Телефон или email (необязательно, только для связи)', 'consent' => 'Отправляя, вы соглашаетесь на обработку введённых данных.', 'send' => 'Отправить', 'sending' => 'Отправляем…', 'close' => 'Закрыть', 'thanks' => 'Спасибо! Ваш отзыв получен.', 'google' => 'Поделиться отзывом в Google', 'error' => 'Не удалось отправить. Попробуйте ещё раз.', 'noReviews' => 'Отзывов пока нет.'],
        'pl' => ['thanksPending' => 'Dziękujemy! Twoja opinia została przyjęta. Pojawi się na stronie po sprawdzeniu.', 'googleAsk' => 'Podobało się? Podziel się wrażeniami także w Google — to bardzo pomaga firmie.', 'err' => ['widget.form_disabled' => 'Formularz opinii jest obecnie wyłączony.', 'widget.form_spam' => 'Nie udało się wysłać. Spróbuj ponownie za minutę.', 'widget.form_rating' => 'Wystaw ocenę od 1 do 5.', 'widget.form_name' => 'Podaj swoje imię.', 'widget.form_text_long' => 'Tekst jest za długi: do 2000 znaków.', 'widget.form_text_short' => 'Napisz, proszę, kilka słów, co poszło nie tak — to pomoże firmie się poprawić.', 'widget.form_too_many' => 'Z tego urządzenia wysłano już kilka opinii. Spróbuj później.'], 'title' => 'Opinie Google', 'titleMixed' => 'Wszystkie opinie', 'loadMore' => 'Pokaż więcej', 'reviews' => ['opinia', 'opinie', 'opinii'], 'read' => 'Wszystkie opinie w Google', 'write' => 'Napisz opinię', 'ownerReply' => 'Odpowiedź właściciela', 'fromSite' => 'ze strony', 'more' => 'Czytaj dalej', 'rating' => 'Twoja ocena', 'name' => 'Twoje imię', 'text' => 'Opowiedz o swoim doświadczeniu', 'contact' => 'Telefon lub email (opcjonalnie, tylko do kontaktu)', 'consent' => 'Wysyłając, zgadzasz się na przetwarzanie wprowadzonych danych.', 'send' => 'Wyślij', 'sending' => 'Wysyłanie…', 'close' => 'Zamknij', 'thanks' => 'Dziękujemy! Twoja opinia została przyjęta.', 'google' => 'Podziel się opinią w Google', 'error' => 'Nie udało się wysłać. Spróbuj ponownie.', 'noReviews' => 'Brak opinii.'],
    ];

    /** @return array<string, mixed> */
    public static function for(string $lang): array
    {
        return self::STRINGS[$lang] ?? self::STRINGS['en'];
    }

    /**
     * Мова блоку: явна з налаштувань вставки → фіксована з GMB Manager → «Авто» (auto): мова сторінки,
     * яку передає інтеграція (WordPress get_locale, Laravel app()->getLocale(), Symfony request locale,
     * OpenCart config_language) → en. «uk_UA», «uk-UA», «ua» → uk.
     */
    public static function resolve(?string $explicit, ?string $configured, ?string $pageLang): string
    {
        $want = $explicit ?: (null !== $configured && 'auto' !== $configured ? $configured : null) ?: $pageLang ?: 'en';
        $want = strtolower(substr((string) $want, 0, 2));
        if ('ua' === $want) {
            $want = 'uk';
        }

        return isset(self::STRINGS[$want]) ? $want : 'en';
    }

    public static function plural(int $n, array $forms, string $lang): string
    {
        if ('en' === $lang) {
            return 1 === $n ? $forms[0] : $forms[1];
        }
        $m10 = $n % 10;
        $m100 = $n % 100;
        if (1 === $m10 && 11 !== $m100) {
            return $forms[0];
        }
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) {
            return $forms[1];
        }

        return $forms[2];
    }

    public static function date(?string $iso, string $lang): string
    {
        if (!$iso) {
            return '';
        }
        $ts = strtotime($iso);
        if (false === $ts) {
            return $iso;
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $locale = ['uk' => 'uk_UA', 'ru' => 'ru_RU', 'pl' => 'pl_PL'][$lang] ?? 'en_GB';
            $f = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
            $out = $f->format($ts);
            if (\is_string($out)) {
                return $out;
            }
        }

        return date('d.m.Y', $ts);
    }
}
