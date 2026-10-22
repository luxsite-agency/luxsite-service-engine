<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\AndBinary;
use Twig\Node\Expression\Binary\HasEveryBinary;
use Twig\Node\Expression\Binary\HasSomeBinary;
use Twig\Node\Expression\Binary\ObjectDestructuringSetBinary;
use Twig\Node\Expression\Binary\OrBinary;
use Twig\Node\Expression\Binary\SequenceDestructuringSetBinary;
use Twig\Node\Expression\Binary\SetBinary;
use Twig\Node\Expression\BlockReferenceExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Unary\NotUnary;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\IfNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Source;
use Twig\Template;

/**
 * Перевірка живого шаблону ДО запису (порт стража shop-engine PR #108, 22.10.2026). Раніше тут був лише parse() синтаксису,
 * і живий шаблон міг вивести {{ app.request.server.get('DATABASE_URL') }} (секрети оточення на публічній сторінці) чи
 * викликати render(controller('App\\…::метод')) — sub-request оминає firewall, бо той працює лише для main request.
 * Тепер — прохід по AST, у якому дозволене лише те, що перелічено в LiveThemePolicy:
 *   - теги, фільтри, функції, тести — зі списків; невідоме → відмова (render/controller/include_only/attribute/source/dump/
 *     invoke/constant довільних класів сюди не потрапляють);
 *   - глобальна app — лише точним ланцюжком зі списку (app.request.locale, app.request.query.get(…), app.user, app.flashes…);
 *     app, app.request, app.session, app.request.server у будь-якому іншому місці (set, аргумент, with) — відмова; саме
 *     app.request — лише там, де значення зводиться до bool ({% if app.request %}, not, and/or, ?:, is defined/null/empty);
 *     _context — відмова;
 *   - властивості й методи (x.name, x.name()) — лише імена зі списку; attribute() заборонено, тож імена завжди літерали;
 *   - extends / include / embed / import / use / block(name, template) — лише літерал services/**, partials/** або
 *     @engine/services/**, @engine/partials/** (*.twig) — не адмінка, не листи, не шаблони бандлів;
 *   - constant — лише літерал JSON_*; filter / map / reduce / sort / find — лише зі стрілочною функцією (рядок-callable
 *     'system' Twig 3 ще приймає); те саме для операторів has some / has every;
 *   - присвоєння у виразі (x = …, {key: v} = obj, [a, b] = …, Twig 3.27+) — відмова: деструктуризація об'єкта компілюється в
 *     getAttribute(obj, key, ANY_CALL) без GetAttrExpression, тож ключ оминав би список властивостей (рев'ю PR #8:
 *     {% do {request: r} = loop['parent']['app'] %}{% do {server: s} = r %}{{ s.get('APP_SECRET') }});
 *   - loop — лише loop.<ключ зі списку>; loop.parent / loop['parent'] і _parent (увесь контекст разом з app) — відмова.
 * Імена, які шаблон має право ПЕРЕКРИВАТИ, перевіряє ThemeService::normalize; тут — що він може вживати і на що посилатися.
 */
final class ThemeTemplateGuard
{
    private const array ARROW_FILTERS = ['filter', 'map', 'reduce', 'sort', 'find'];
    private const string CONSTANT_RE = '/^JSON_[A-Z_]+$/';

    public function __construct(private readonly Environment $twig)
    {
    }

    /** @return list<string> проблеми (порожньо — шаблон можна писати) */
    public function check(string $name, string $content): array
    {
        try {
            $module = $this->twig->parse($this->twig->tokenize(new Source($content, $name)));
        } catch (SyntaxError $e) {
            return ['Twig: ' . $e->getMessage()];
        }
        $problems = [];
        $this->walk($module, $problems);

        return array_values(array_unique($problems));
    }

    /** Посилання на шаблон, дозволене живій темі: services/**, partials/** (*.twig), також з префіксом @engine/. */
    public static function isAllowedReference(string $ref): bool
    {
        $rel = str_starts_with($ref, '@engine/') ? substr($ref, 8) : $ref;

        return preg_match(ThemeService::TEMPLATE_RE, $rel) === 1 && !str_contains($rel, '..') && !str_contains($rel, '//');
    }

    /** @param list<string> $problems */
    private function walk(Node $node, array &$problems): void
    {
        $tag = $node->getNodeTag();
        if ($tag !== null && !\in_array($tag, LiveThemePolicy::TAGS, true)) {
            $problems[] = sprintf('{%% %s %%} (рядок %d): тег у живій темі не дозволено', $tag, $node->getTemplateLine());
        }
        if ($node instanceof ModuleNode) {
            if ($node->hasNode('parent')) {
                $this->reference($node->getNode('parent'), 'extends', $problems);
            }
            foreach ($node->getNode('traits') as $trait) {
                $this->reference($trait->getNode('template'), 'use', $problems);
            }
            foreach ($node->getAttribute('embedded_templates') as $embedded) {
                $this->walk($embedded, $problems);
            }
        } elseif ($node instanceof IncludeNode && !$node instanceof EmbedNode) {
            // embed: шаблон-батько — у вкладеному ModuleNode (embedded_templates), його expr тут службовий
            $this->reference($node->getNode('expr'), 'include', $problems);
        } elseif ($node instanceof ImportNode) {
            $expr = $node->getNode('expr');
            if ($expr instanceof ContextVariable && $expr->getAttribute('name') === '_self') {
                $this->walk($node->getNode('var'), $problems); // {% import _self as m %} — єдине законне місце для _self

                return;
            }
            $this->reference($expr, 'import', $problems);
        } elseif ($node instanceof SetBinary || $node instanceof ObjectDestructuringSetBinary || $node instanceof SequenceDestructuringSetBinary) {
            $problems[] = sprintf('присвоєння у виразі (рядок %d): у живій темі лише {%% set x = … %%}, без = і деструктуризації {key: v} = …', $node->getTemplateLine());
        } elseif (($node instanceof HasSomeBinary || $node instanceof HasEveryBinary) && !$node->getNode('right') instanceof ArrowFunctionExpression) {
            // x has some('system') — CoreExtension::arraySome викликає будь-який callable (рев'ю PR #8)
            $problems[] = sprintf('has some / has every (рядок %d): лише стрілочна функція (v => …), не рядок-callable', $node->getTemplateLine());
        } elseif ($node instanceof BlockReferenceExpression && $node->hasNode('template')) {
            $this->reference($node->getNode('template'), 'block()', $problems);
        } elseif ($node instanceof GetAttrExpression) {
            if ($this->attribute($node, $problems)) {
                return; // дозволений ланцюжок app.…: усередину не йдемо (там сама app), аргументи вже перевірено
            }
        } elseif ($node instanceof ContextVariable) {
            $this->variable($node, $problems);
        } elseif ($node instanceof FunctionExpression) {
            $this->function($node, $problems);
        } elseif ($node instanceof FilterExpression) {
            $this->filter($node, $problems);
        } elseif ($node instanceof TestExpression) {
            $name = (string) $node->getAttribute('name');
            if (!\in_array($name, LiveThemePolicy::TESTS, true)) {
                $problems[] = sprintf('is %s (рядок %d): тест у живій темі не дозволено', $name, $node->getTemplateLine());
            }
        }
        $this->children($node, $problems);
    }

    /**
     * Діти вузла; саме app.request (без ланки далі) пропускається лише там, де воно зводиться до bool: умова if, операнд
     * not/and/or, умова ?:, операнд is defined|null|empty|true (сторінка 404 рушія так перевіряє, чи є запит). У a ?: b
     * expr1 і expr2 — один вузол, тож як значення (expr2) воно все одно перевіриться і відмовиться.
     *
     * @param list<string> $problems
     */
    private function children(Node $node, array &$problems): void
    {
        if ($node instanceof IfNode) {
            foreach ($node->getNode('tests') as $i => $child) {
                if ((int) $i % 2 === 0 && self::isBareAppRequest($child)) {
                    continue;
                }
                $this->walk($child, $problems);
            }
            if ($node->hasNode('else')) {
                $this->walk($node->getNode('else'), $problems);
            }

            return;
        }
        $boolean = match (true) {
            $node instanceof AndBinary, $node instanceof OrBinary => ['left', 'right'],
            $node instanceof NotUnary => ['node'],
            $node instanceof ConditionalExpression => ['expr1'],
            $node instanceof TestExpression => \in_array($node->getAttribute('name'), ['true', 'defined', 'null', 'none', 'empty'], true) ? ['node'] : [],
            default => [],
        };
        foreach ($node as $key => $child) {
            if (\in_array((string) $key, $boolean, true) && self::isBareAppRequest($child)) {
                continue;
            }
            if ($node instanceof GetAttrExpression && (string) $key === 'node' && self::isVariable($child, 'loop')) {
                continue; // loop як корінь loop.<ключ> — ключ уже перевірив attribute()
            }
            $this->walk($child, $problems);
        }
    }

    private static function isVariable(Node $node, string $name): bool
    {
        return $node instanceof ContextVariable && $node->getAttribute('name') === $name;
    }

    private static function isBareAppRequest(Node $node): bool
    {
        if (!$node instanceof GetAttrExpression || $node->getAttribute('type') === Template::ARRAY_CALL) {
            return false;
        }
        $attr = $node->getNode('attribute');
        $root = $node->getNode('node');

        return $attr instanceof ConstantExpression && $attr->getAttribute('value') === 'request' && $root instanceof ContextVariable && $root->getAttribute('name') === 'app';
    }

    /**
     * x.name / x.name(): ім'я — літерал зі списку; ланцюжок від app — точний зі списку (аргументи його викликів перевіряються
     * тут, сам ланцюжок далі не обходиться). x['name'] (масив) імені не перевіряє: на об'єкті без ArrayAccess Twig метод не
     * викличе. Імена інших ланок ланцюжка (не app) перевіряє рекурсія walk — кожна ланка сама є GetAttrExpression.
     *
     * @param list<string> $problems
     *
     * @return bool true — ланцюжок app оброблено цілком, у walk далі не йти
     */
    private function attribute(GetAttrExpression $node, array &$problems): bool
    {
        $line = $node->getTemplateLine();
        $chain = [];
        $arguments = [];
        $dynamic = false;
        $root = $node;
        while ($root instanceof GetAttrExpression) {
            $attr = $root->getNode('attribute');
            $name = $attr instanceof ConstantExpression ? $attr->getAttribute('value') : null;
            if (!\is_string($name)) {
                $dynamic = true;
                $name = '?';
            }
            array_unshift($chain, [$name, (string) $root->getAttribute('type')]);
            if ($root->hasNode('arguments')) {
                $arguments[] = $root->getNode('arguments');
            }
            $root = $root->getNode('node');
        }
        [$own, $ownType] = $chain[\count($chain) - 1];
        $isApp = $root instanceof ContextVariable && $root->getAttribute('name') === 'app';
        if ($node->getNode('node') === $root && self::isVariable($root, 'loop') && !\in_array($own, LiveThemePolicy::LOOP_KEYS, true)) {
            // найближча до кореня ланка: loop.parent / loop['parent'] / loop[вираз] — увесь контекст разом з app
            $problems[] = sprintf('loop.%s (рядок %d): з loop у живій темі лише %s', $own, $line, implode(', ', LiveThemePolicy::LOOP_KEYS));

            return false;
        }
        if (!$isApp) {
            if ($ownType === Template::ARRAY_CALL) {
                return false; // x[key] — масив будь-яким ключем, і динамічним теж: метод чи властивість так не дістати
            }
            if ($own === '?') {
                $problems[] = sprintf('attribute() або динамічне ім\'я властивості (рядок %d): у живій темі лише x.name літералом', $line);
            } elseif (!\in_array($own, LiveThemePolicy::ATTRIBUTES, true)) {
                $problems[] = sprintf('.%s (рядок %d): властивість чи метод у живій темі не дозволено (для масиву — x[\'%s\'])', $own, $line, $own);
            }

            return false;
        }
        if ($dynamic) {
            $problems[] = sprintf('attribute() на app (рядок %d): у живій темі лише app.name літералом', $line);
        }
        // app.…: лише точний ланцюжок зі списку (request, query… у ATTRIBUTES нема навмисно) або app.user.<властивість зі списку>
        $names = array_column($chain, 0);
        $path = implode('.', $names);
        if (\in_array($path, LiveThemePolicy::APP_CHAINS, true)) {
            // ок
        } elseif ($names[0] === 'user') {
            foreach (\array_slice($names, 1) as $name) {
                if (!\in_array($name, LiveThemePolicy::ATTRIBUTES, true)) {
                    $problems[] = sprintf('app.%s (рядок %d): властивість користувача у живій темі не дозволено', $path, $line);
                }
            }
        } else {
            $problems[] = sprintf('app.%s (рядок %d): з app у живій темі дозволено лише %s', $path, $line, implode(', ', array_map(static fn (string $c): string => 'app.' . $c, LiveThemePolicy::APP_CHAINS)));
        }
        foreach ($arguments as $args) {
            $this->walk($args, $problems);
        }

        return true;
    }

    /** @param list<string> $problems */
    private function variable(ContextVariable $node, array &$problems): void
    {
        $name = (string) $node->getAttribute('name');
        if ($name === 'app') {
            $problems[] = sprintf('app (рядок %d): лише як app.request.locale, app.request.query.get(…), app.user, app.flashes… — не сама змінна (set, аргумент, with)', $node->getTemplateLine());
        } elseif ($name === 'loop') {
            $problems[] = sprintf('loop (рядок %d): лише loop.index, loop.first… — не сама змінна (set, аргумент, with)', $node->getTemplateLine());
        } elseif (\in_array($name, LiveThemePolicy::DENIED_VARIABLES, true)) {
            $problems[] = sprintf('%s (рядок %d): змінна у живій темі не дозволена', $name, $node->getTemplateLine());
        }
    }

    /** @param list<string> $problems */
    private function reference(Node $expr, string $where, array &$problems): void
    {
        $value = $expr instanceof ConstantExpression ? $expr->getAttribute('value') : null;
        if (!\is_string($value)) {
            $problems[] = sprintf('%s (рядок %d): шаблон лише літералом services/…, partials/… або @engine/…, не виразом', $where, $expr->getTemplateLine());

            return;
        }
        if (!self::isAllowedReference($value)) {
            $problems[] = sprintf('%s (рядок %d): «%s» — дозволені лише services/**, partials/** (*.twig) і те саме з @engine/', $where, $expr->getTemplateLine(), $value);
        }
    }

    /** @param list<string> $problems */
    private function function(FunctionExpression $node, array &$problems): void
    {
        $name = (string) $node->getAttribute('name');
        $line = $node->getTemplateLine();
        if (!\in_array($name, LiveThemePolicy::FUNCTIONS, true)) {
            $problems[] = sprintf('%s() (рядок %d): функцію у живій темі не дозволено', $name, $line);

            return;
        }
        $arguments = $node->getNode('arguments');
        if ($name === 'include') {
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : ($arguments->hasNode('template') ? $arguments->getNode('template') : null);
            if ($first === null) {
                $problems[] = sprintf('include() (рядок %d): без шаблону', $line);
            } else {
                $this->reference($first, 'include()', $problems);
            }

            return;
        }
        if ($name === 'constant') {
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : null;
            $value = $first instanceof ConstantExpression ? $first->getAttribute('value') : null;
            if (\count(iterator_to_array($arguments)) !== 1 || !\is_string($value) || preg_match(self::CONSTANT_RE, $value) !== 1) {
                $problems[] = sprintf('constant() (рядок %d): лише літерал JSON_* (прапорці json_encode), не сталі довільних класів', $line);
            }
        }
    }

    /** @param list<string> $problems */
    private function filter(FilterExpression $node, array &$problems): void
    {
        $name = (string) $node->getAttribute('name');
        if (!\in_array($name, LiveThemePolicy::FILTERS, true)) {
            $problems[] = sprintf('|%s (рядок %d): фільтр у живій темі не дозволено', $name, $node->getTemplateLine());

            return;
        }
        if (!\in_array($name, self::ARROW_FILTERS, true)) {
            return;
        }
        $arguments = $node->getNode('arguments');
        $callable = $arguments->hasNode('0') ? $arguments->getNode('0') : ($arguments->hasNode('arrow') ? $arguments->getNode('arrow') : null);
        if ($callable === null && $name === 'sort') {
            return;
        }
        if (!$callable instanceof ArrowFunctionExpression) {
            $problems[] = sprintf('|%s (рядок %d): лише стрілочна функція ((v, k) => …), не рядок-callable', $name, $node->getTemplateLine());
        }
    }
}
