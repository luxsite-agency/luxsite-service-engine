<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace Luxsite\GmbReviews;

/**
 * Серверний рендер блоку (HTML + CSS + JSON-LD) з JSON віджета — для WordPress, OpenCart, Symfony, Laravel.
 * Вигляд і класи повторюють JS-ембед (resources/widget/v1.js, віддається через /w/v1.js), але без shadow DOM: усі класи з префіксом gmbr-,
 * CSS обмежений контейнером .gmbr, тож зі стилями сайту майже не конфліктує.
 *
 * Форма «написати відгук» — та сама, що в JS: невеликий inline-скрипт шле JSON на GMB Manager; без JS форма
 * просто не показується (кнопка веде на сторінку форми на нашому домені).
 */
final class Renderer
{
    /**
     * @param array{layout?: ?string, theme?: ?string, lang?: ?string, page_lang?: ?string, form?: bool, css?: bool, page_url?: ?string, schema?: bool, id?: string} $opts
     *        lang — примусова мова; page_lang — мова поточної сторінки сайту (для режиму «Авто» на мультимовних сайтах)
     */
    public static function html(array $data, array $opts = []): string
    {
        $s = $data['settings'] ?? [];
        $b = $data['business'] ?? [];
        $lang = I18n::resolve($opts['lang'] ?? null, $s['lang'] ?? null, $opts['page_lang'] ?? null);
        $t = I18n::for($lang);
        $layout = $opts['layout'] ?? ($s['layout'] ?? 'grid');
        $theme = $opts['theme'] ?? ($s['theme'] ?? 'auto');
        $accent = self::color($s['accent'] ?? '#0d6e6e');
        $id = $opts['id'] ?? 'gmbr-'.substr(md5((string) json_encode($b)), 0, 8);
        // Загальна оцінка: summary з сервера (з 2026-10-18), для старих відповідей — офіційні числа Google.
        $sm = $data['summary'] ?? ['show' => true, 'rating' => $b['rating'] ?? null, 'count' => $b['count'] ?? 0, 'includes_site' => false];
        $rating = isset($sm['rating']) ? (float) $sm['rating'] : null;
        $count = (int) ($sm['count'] ?? 0);
        $showSummary = false !== ($sm['show'] ?? true);
        $formOn = ($opts['form'] ?? true) && !empty($data['form']['enabled']);

        $out = ($opts['css'] ?? true) ? '<style>'.self::css().'</style>' : '';
        $out .= '<div class="gmbr gmbr-theme-'.self::e($theme).'" id="'.self::e($id).'" style="--gmbr-accent:'.$accent.'" lang="'.self::e($lang).'">';

        if ('rating' === $layout) {
            return self::ratingHtml($data, $opts + ['lang' => $lang]);
        }
        if ('badge' === $layout) {
            $out .= '<a class="gmbr-badge" href="'.self::e($b['maps_url'] ?? '#').'" target="_blank" rel="noopener nofollow"><b>'.(null !== $rating ? number_format($rating, 1) : '—').'</b>'.self::stars((int) round($rating ?? 0), 18)
                .'<span class="gmbr-cnt">'.$count.' '.I18n::plural($count, $t['reviews'], $lang).(empty($sm['includes_site']) ? ' · Google' : '').'</span></a></div>';

            return $out.self::ld($data, $opts, $lang);
        }

        $out .= '<div class="gmbr-head"><h3>'.self::e(self::title($data, $t)).'</h3>'
            .($showSummary ? '<div class="gmbr-agg"><b>'.(null !== $rating ? number_format($rating, 1) : '—').'</b>'.self::stars((int) round($rating ?? 0), 18)
                .'<span class="gmbr-cnt">'.$count.' '.I18n::plural($count, $t['reviews'], $lang).'</span></div>' : '')
            .'<div class="gmbr-actions">'
            .($formOn ? '<button type="button" class="gmbr-btn" data-gmbr-write>'.$t['write'].'</button>' : '')
            .(!empty($b['maps_url']) ? '<a class="gmbr-btn gmbr-sec" href="'.self::e($b['maps_url']).'" target="_blank" rel="noopener nofollow">'.$t['read'].'</a>' : '')
            .'</div></div>';
        if ($formOn) {
            $out .= self::form($data, $t, $lang);
        }

        $cls = 'list' === $layout ? 'gmbr-list' : ('carousel' === $layout ? 'gmbr-car' : 'gmbr-grid');
        $out .= '<div class="'.$cls.'">';
        if (empty($data['reviews'])) {
            $out .= '<div class="gmbr-small">'.$t['noReviews'].'</div>';
        }
        foreach ($data['reviews'] as $r) {
            // Переклад відгуку мовою сторінки, якщо сайт його дав (імпорт із мультимовного сайту).
            $tr = $r['i18n'][$lang] ?? [];
            $author = (string) (($tr['author'] ?? '') ?: ($r['author'] ?? ''));
            $text = (string) (($tr['text'] ?? '') ?: ($r['text'] ?? ''));
            $initial = mb_strtoupper(mb_substr(trim('' !== $author ? $author : '?'), 0, 1));
            $out .= '<article class="gmbr-card"><div class="gmbr-who"><span class="gmbr-ava">'.self::e($initial).'</span><div><div class="gmbr-n">'.self::e($author).'</div>'
                .'<div class="gmbr-d">'.self::e(I18n::date($r['date'] ?? null, $lang)).(!empty($r['city']) ? ' · '.self::e($r['city']) : '').(!empty($r['pinned']) ? ' · <span class="gmbr-pin">★</span>' : '').'</div></div>'
                .'<span class="gmbr-tag">'.('site' === ($r['source'] ?? '') ? $t['fromSite'] : 'Google').'</span></div>'
                .self::stars((int) ($r['rating'] ?? 0), 15)
                .('' !== $text ? '<div class="gmbr-txt">'.nl2br(self::e($text)).'</div>' : '')
                .(!empty($r['reply']['text']) ? '<div class="gmbr-reply"><b>'.$t['ownerReply'].'</b><br>'.nl2br(self::e($r['reply']['text'])).'</div>' : '')
                .'</article>';
        }
        $out .= '</div>';
        // Решта відгуків — порціями з GMB Manager кнопкою «Показати ще», як у JS-ембеді (власник 2026-10-18).
        if (!empty($data['pagination']['next_url'])) {
            $out .= self::loadMore((string) $data['pagination']['next_url'], $t, $lang, $id);
        }
        $out .= '</div>';

        return $out.self::ld($data, $opts, $lang);
    }

    /**
     * JSON-LD (LocalBusiness + AggregateRating + Review) або null, якщо вимкнено/нема даних. Числа — рівно ті,
     * що видно в шапці блоку (summary); оцінку не показуємо → розмітки нема. review[] — показані відгуки, що
     * входять у цю оцінку (при «разом» — і з сайту), тією мовою, якою показані. Та сама логіка, що
     * ReviewWidgetFeed::jsonLd() на сервері й jsonLd() у JS-ембеді.
     */
    public static function jsonLd(array $data, ?string $pageUrl = null, ?string $lang = null): ?array
    {
        $b = $data['business'] ?? [];
        $sm = $data['summary'] ?? ['show' => true, 'rating' => $b['rating'] ?? null, 'count' => $b['count'] ?? 0, 'includes_site' => false];
        if (empty($data['settings']['schema_markup']) || false === ($sm['show'] ?? true) || !isset($sm['rating']) || empty($sm['count'])) {
            return null;
        }
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $b['name'] ?? '',
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => (string) $sm['rating'], 'reviewCount' => (string) $sm['count'], 'bestRating' => '5', 'worstRating' => '1'],
        ];
        if ($pageUrl) {
            $ld['@id'] = $pageUrl.'#business';
            $ld['url'] = $pageUrl;
        }
        if (!empty($b['address'])) {
            $ld['address'] = $b['address'];
        }
        if (!empty($b['phone'])) {
            $ld['telephone'] = $b['phone'];
        }
        if (!empty($b['maps_url'])) {
            $ld['sameAs'] = [$b['maps_url']];
        }
        $withSite = !empty($sm['includes_site']);
        $reviews = [];
        foreach ($data['reviews'] ?? [] as $r) {
            if (!$withSite && 'google' !== ($r['source'] ?? '')) {
                continue;
            }
            $tr = null !== $lang ? ($r['i18n'][$lang] ?? []) : [];
            $text = trim((string) (($tr['text'] ?? '') ?: ($r['text'] ?? '')));
            if ('' === $text) {
                continue;
            }
            $reviews[] = ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => ($tr['author'] ?? '') ?: ($r['author'] ?? '')], 'datePublished' => $r['date'] ?? null, 'reviewBody' => $text,
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (string) ($r['rating'] ?? ''), 'bestRating' => '5', 'worstRating' => '1']];
            if (\count($reviews) >= 10) {
                break;
            }
        }
        if ($reviews) {
            $ld['review'] = $reviews;
        }

        return $ld;
    }

    /**
     * Загальна оцінка з JSON віджета — рівно та, що в шапці блоку (summary): null, якщо її не показуємо.
     *
     * @return array{value: float, count: int, source: string, includes_site: bool, name: string, maps_url: ?string}|null
     */
    public static function rating(array $data): ?array
    {
        $b = $data['business'] ?? [];
        $sm = $data['summary'] ?? ['show' => true, 'rating' => $b['rating'] ?? null, 'count' => $b['count'] ?? 0, 'includes_site' => false];
        if (false === ($sm['show'] ?? true) || !isset($sm['rating']) || empty($sm['count'])) {
            return null;
        }

        return [
            'value' => round((float) $sm['rating'], 1),
            'count' => (int) $sm['count'],
            'source' => (string) ($sm['source'] ?? (!empty($sm['includes_site']) ? 'combined' : 'google')),
            'includes_site' => !empty($sm['includes_site']),
            'name' => (string) ($b['name'] ?? ''),
            'maps_url' => $b['maps_url'] ?? null,
        ];
    }

    /**
     * Рядок «5.0 ★★★★★ · 102 відгуки» для будь-якого блоку сайту (власник 2026-10-18: «загальна цифра в кожному
     * блоці відгуків»). Без оцінки — ''. $opts: lang, page_lang, css (типово true — кілька рядків стилю, кольори
     * успадковує від теми), schema (типово false: розмітку дає або віджет, або рушій сайту — не дублювати),
     * page_url, link (true — число веде на картку в Google, якщо оцінка лише Google).
     */
    public static function ratingHtml(array $data, array $opts = []): string
    {
        $r = self::rating($data);
        if (null === $r) {
            return '';
        }
        $lang = I18n::resolve($opts['lang'] ?? null, $data['settings']['lang'] ?? null, $opts['page_lang'] ?? null);
        $t = I18n::for($lang);
        $inner = '<b class="gmbr-rating-value">'.number_format($r['value'], 1).'</b> '.self::stars((int) round($r['value']), 16)
            .' <span class="gmbr-rating-count">· '.$r['count'].' '.I18n::plural($r['count'], $t['reviews'], $lang).'</span>';
        if (!empty($opts['link']) && 'google' === $r['source'] && !empty($r['maps_url'])) {
            $inner = '<a href="'.self::e($r['maps_url']).'" target="_blank" rel="noopener nofollow">'.$inner.'</a>';
        }
        $css = ($opts['css'] ?? true) ? '<style>.gmbr-rating{display:inline-flex;align-items:center;gap:.35em;flex-wrap:wrap;line-height:1.2}.gmbr-rating a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:.35em}.gmbr-rating .gmbr-rating-value{font-size:1.15em}.gmbr-rating .gmbr-stars i{font-style:normal;color:#d9dce1}.gmbr-rating .gmbr-stars i.on{color:#f5b301}.gmbr-rating .gmbr-rating-count{opacity:.75}</style>' : '';
        $html = $css.'<span class="gmbr-rating" lang="'.self::e($lang).'">'.$inner.'</span>';
        if (!empty($opts['schema'])) {
            $html .= self::jsonLdScript(['reviews' => []] + $data, $opts['page_url'] ?? null, $lang);
        }

        return $html;
    }

    /** «</script>» у тексті відгуку (форма з сайту) не закриває тег: JSON_HEX_TAG/AMP. */
    public static function jsonLdScript(array $data, ?string $pageUrl = null, ?string $lang = null): string
    {
        $ld = self::jsonLd($data, $pageUrl, $lang);

        return null === $ld ? '' : '<script type="application/ld+json">'.json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP).'</script>';
    }

    public static function css(): string
    {
        return '.gmbr{--gmbr-fg:#1f2328;--gmbr-muted:#6b7280;--gmbr-bg:transparent;--gmbr-card:#fff;--gmbr-line:#e5e7eb;--gmbr-star:#f5b301;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--gmbr-fg);line-height:1.45;font-size:15px}'
            .'.gmbr *{box-sizing:border-box}.gmbr.gmbr-theme-dark{--gmbr-fg:#e6e6e6;--gmbr-muted:#a0a4ab;--gmbr-card:#1a1f24;--gmbr-line:#2a3138}'
            .'@media (prefers-color-scheme:dark){.gmbr.gmbr-theme-auto{--gmbr-fg:#e6e6e6;--gmbr-muted:#a0a4ab;--gmbr-card:#1a1f24;--gmbr-line:#2a3138}}'
            .'.gmbr-head{display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;margin-bottom:14px}.gmbr-head h3{margin:0;font-size:20px;font-weight:700;color:var(--gmbr-fg)}'
            .'.gmbr-agg{display:flex;align-items:center;gap:8px}.gmbr-agg b{font-size:28px;line-height:1}.gmbr-cnt{color:var(--gmbr-muted);font-size:14px}'
            .'.gmbr-stars i{font-style:normal;color:var(--gmbr-line)}.gmbr-stars i.on{color:var(--gmbr-star)}'
            .'.gmbr-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}.gmbr-btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 14px;border-radius:8px;border:1px solid var(--gmbr-accent);background:var(--gmbr-accent);color:#fff;font:inherit;font-size:14px;cursor:pointer;text-decoration:none}.gmbr-btn.gmbr-sec{background:transparent;color:var(--gmbr-accent)}'
            .'.gmbr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}.gmbr-list{display:grid;gap:14px}'
            .'.gmbr-car{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px;scrollbar-width:thin}.gmbr-car .gmbr-card{flex:0 0 300px;scroll-snap-align:start}'
            .'.gmbr-card{background:var(--gmbr-card);border:1px solid var(--gmbr-line);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:8px}'
            .'.gmbr-who{display:flex;align-items:center;gap:10px}.gmbr-ava{width:38px;height:38px;border-radius:50%;background:var(--gmbr-accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 38px}.gmbr-n{font-weight:600}.gmbr-d{color:var(--gmbr-muted);font-size:12px}'
            .'.gmbr-txt{white-space:normal}.gmbr-reply{border-left:3px solid var(--gmbr-line);padding:6px 10px;color:var(--gmbr-muted);font-size:13px}.gmbr-reply b{color:var(--gmbr-fg)}'
            .'.gmbr-badge{display:inline-flex;align-items:center;gap:10px;border:1px solid var(--gmbr-line);border-radius:12px;padding:10px 14px;background:var(--gmbr-card);text-decoration:none;color:var(--gmbr-fg)}.gmbr-badge b{font-size:22px}'
            .'.gmbr-tag{font-size:11px;color:var(--gmbr-muted);border:1px solid var(--gmbr-line);border-radius:10px;padding:1px 7px;margin-left:auto}.gmbr-pin{font-size:11px;color:var(--gmbr-accent)}.gmbr-morewrap{display:flex;justify-content:center;margin-top:14px}'
            .'.gmbr-form{background:var(--gmbr-card);border:1px solid var(--gmbr-line);border-radius:12px;padding:16px;margin-bottom:14px;display:grid;gap:10px;max-width:560px}.gmbr-form[hidden]{display:none}.gmbr-form label{font-size:13px;color:var(--gmbr-muted)}'
            .'.gmbr-form input,.gmbr-form textarea{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--gmbr-line);border-radius:8px;background:transparent;color:var(--gmbr-fg)}.gmbr-form textarea{min-height:96px;resize:vertical}'
            .'.gmbr-rate{display:flex;gap:4px;direction:rtl;justify-content:flex-end}.gmbr-rate input{display:none}.gmbr-rate label{cursor:pointer;color:var(--gmbr-line);font-size:30px}.gmbr-rate input:checked~label,.gmbr-rate label:hover,.gmbr-rate label:hover~label{color:var(--gmbr-star)}'
            .'.gmbr-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}.gmbr-msg{padding:10px 12px;border-radius:8px;background:#e6f4ea;color:#1e7e34}.gmbr-msg.gmbr-err{background:#fdecea;color:#b00020}.gmbr-small{font-size:12px;color:var(--gmbr-muted)}';
    }

    private static function form(array $data, array $t, string $lang): string
    {
        $post = self::e($data['form']['post_url'] ?? '');
        $ts = time();
        $rate = '';
        foreach ([5, 4, 3, 2, 1] as $v) {
            $rate .= '<input type="radio" name="rating" id="gmbr-r'.$v.'-'.$ts.'" value="'.$v.'"><label for="gmbr-r'.$v.'-'.$ts.'">★</label>';
        }
        $js = "(function(){var f=document.currentScript.previousElementSibling,w=f.parentNode.querySelector('[data-gmbr-write]');if(w){w.addEventListener('click',function(){f.hidden=!f.hidden;if(!f.hidden){f.scrollIntoView({behavior:'smooth',block:'nearest'})}})}"
            ."f.querySelector('[data-gmbr-close]').addEventListener('click',function(){f.hidden=true});"
            ."var E=".json_encode($t['err'], JSON_UNESCAPED_UNICODE).";"
            ."f.addEventListener('submit',function(e){e.preventDefault();var b={lang:".json_encode($lang)."};new FormData(f).forEach(function(v,k){b[k]=v});var m=f.querySelector('[data-gmbr-msg]'),s=f.querySelector('[type=submit]');s.disabled=true;s.textContent=".json_encode($t['sending']).";"
            ."fetch(f.action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(function(r){return r.json()}).then(function(j){if(j&&j.ok){var o='<div class=\"gmbr-msg\">'+".json_encode($t['thanksPending'], JSON_UNESCAPED_UNICODE)."+'</div>';if(j.google_invite&&j.google_review_url){o+='<p class=\"gmbr-small\">'+".json_encode($t['googleAsk'], JSON_UNESCAPED_UNICODE)."+'</p><a class=\"gmbr-btn\" href=\"'+j.google_review_url+'\" target=\"_blank\" rel=\"noopener\">".addslashes($t['google'])."</a>'}f.innerHTML=o}else{m.innerHTML='<div class=\"gmbr-msg gmbr-err\">'+((j&&E[j.error])||".json_encode($t['error'], JSON_UNESCAPED_UNICODE).")+'</div>';s.disabled=false;s.textContent=".json_encode($t['send'])."}}).catch(function(){m.innerHTML='<div class=\"gmbr-msg gmbr-err\">".addslashes($t['error'])."</div>';s.disabled=false;s.textContent=".json_encode($t['send'])."})})})();";

        return '<form class="gmbr-form" hidden action="'.$post.'" method="post" novalidate>'
            .'<div><label>'.$t['rating'].'</label><div class="gmbr-rate">'.$rate.'</div></div>'
            .'<div><label>'.$t['name'].'</label><input name="name" maxlength="120" required></div>'
            .'<div><label>'.$t['text'].'</label><textarea name="text" maxlength="2000"></textarea></div>'
            .'<div><label>'.$t['contact'].'</label><input name="contact" maxlength="190"></div>'
            .'<div class="gmbr-hp"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>'
            .'<input type="hidden" name="ts" value="'.$ts.'"><input type="hidden" name="page" value="">'
            .'<div class="gmbr-small">'.$t['consent'].'</div>'
            .'<div style="display:flex;gap:8px"><button class="gmbr-btn" type="submit">'.$t['send'].'</button><button class="gmbr-btn gmbr-sec" type="button" data-gmbr-close>'.$t['close'].'</button></div>'
            .'<div data-gmbr-msg></div></form><script>'.$js.'</script>';
    }

    /** Кнопка «Показати ще» + маленький скрипт: бере наступну порцію JSON і домальовує картки тими ж класами. */
    private static function loadMore(string $next, array $t, string $lang, string $id): string
    {
        $i18n = json_encode(['fromSite' => $t['fromSite'], 'ownerReply' => $t['ownerReply'], 'lang' => $lang, 'locale' => ['uk' => 'uk-UA', 'ru' => 'ru-RU', 'pl' => 'pl-PL'][$lang] ?? 'en-GB'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $js = "(function(){var w=document.getElementById(".json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)."),b=w&&w.querySelector('[data-next]');if(!b)return;var box=w.querySelector('.gmbr-grid,.gmbr-list,.gmbr-car'),T=".$i18n.";"
            ."function e(s){return String(s==null?'':s).replace(/[&<>\"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]})}"
            ."function st(n){var o='<span class=\"gmbr-stars\" aria-label=\"'+n+'/5\" style=\"font-size:15px\">';for(var i=1;i<=5;i++){o+='<i class=\"'+(i<=n?'on':'off')+'\">★</i>'}return o+'</span>'}"
            ."function d(s){if(!s)return'';try{return new Date(s+'T00:00:00').toLocaleDateString(T.locale,{year:'numeric',month:'long',day:'numeric'})}catch(x){return s}}"
            ."function card(r){var tr=(r.i18n&&r.i18n[T.lang])||{},a=tr.author||r.author||'',x=tr.text||r.text||'';"
            ."return '<article class=\"gmbr-card\"><div class=\"gmbr-who\"><span class=\"gmbr-ava\">'+e((a.trim()||'?').charAt(0).toUpperCase())+'</span><div><div class=\"gmbr-n\">'+e(a)+'</div><div class=\"gmbr-d\">'+e(d(r.date))+(r.city?' · '+e(r.city):'')+(r.pinned?' · <span class=\"gmbr-pin\">★</span>':'')+'</div></div>'"
            ."+'<span class=\"gmbr-tag\">'+(r.source==='site'?e(T.fromSite):'Google')+'</span></div>'+st(r.rating|0)+(x?'<div class=\"gmbr-txt\">'+e(x).replace(/\\n/g,'<br>')+'</div>':'')"
            ."+(r.reply&&r.reply.text?'<div class=\"gmbr-reply\"><b>'+e(T.ownerReply)+'</b><br>'+e(r.reply.text).replace(/\\n/g,'<br>')+'</div>':'')+'</article>'}"
            ."b.addEventListener('click',function(){var u=b.getAttribute('data-next');if(!u||b.disabled)return;b.disabled=true;"
            ."fetch(u,{credentials:'omit'}).then(function(r){if(!r.ok)throw r.status;return r.json()}).then(function(p){box.insertAdjacentHTML('beforeend',p.reviews.map(card).join(''));"
            ."var n=p.pagination&&p.pagination.next_url;if(n){b.setAttribute('data-next',n);b.disabled=false}else{b.parentNode.removeChild(b)}}).catch(function(){b.disabled=false})})})();";

        return '<div class="gmbr-morewrap"><button type="button" class="gmbr-btn gmbr-sec" data-next="'.self::e($next).'">'.self::e($t['loadMore']).'</button></div><script>'.$js.'</script>';
    }

    private static function ld(array $data, array $opts, ?string $lang = null): string
    {
        return ($opts['schema'] ?? true) ? self::jsonLdScript($data, $opts['page_url'] ?? null, $lang) : '';
    }

    /**
     * Заголовок блоку: свій із GMB Manager → «Всі відгуки», якщо серед показаних є відгуки з сайту →
     * «Відгуки Google» (власник 2026-10-18: 102 відгуки під «Відгуки Google» — неправда).
     */
    private static function title(array $data, array $t): string
    {
        $custom = trim((string) ($data['settings']['title'] ?? ''));
        if ('' !== $custom) {
            return $custom;
        }
        $site = $data['sources']['site'] ?? \count(array_filter($data['reviews'] ?? [], static fn ($r) => 'site' === ($r['source'] ?? '')));

        return $site > 0 ? $t['titleMixed'] : $t['title'];
    }

    private static function stars(int $n, int $size): string
    {
        $out = '<span class="gmbr-stars" aria-label="'.$n.'/5" style="font-size:'.$size.'px">';
        for ($i = 1; $i <= 5; ++$i) {
            $out .= '<i class="'.($i <= $n ? 'on' : 'off').'">★</i>';
        }

        return $out.'</span>';
    }

    private static function color(string $c): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#0d6e6e';
    }

    private static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
