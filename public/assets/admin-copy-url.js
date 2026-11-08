/* Кнопка «скопіювати адресу» біля домену чи хоста в адмінці (App\Admin\CopyUrlField).
 *
 * Список і перегляд: кнопка .sv-copy-url з data-copy (готова адреса) — рендерить шаблон поля.
 * Форма: на input[data-copy-url] кнопку додаємо тут, адреса — з поточного значення поля (його щойно могли змінити).
 * Клік не відкриває рядок списку (stopPropagation), підказка «Скопійовано» — 1,5 с. Clipboard API потребує https або
 * localhost; запасний варіант — execCommand('copy') через тимчасове поле.
 */
(function () {
    'use strict';

    var TITLE = 'Скопіювати адресу сайту';
    var DONE = 'Скопійовано';

    function toUrl(value) {
        var host = String(value || '').trim().replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').split('/')[0].replace(/\.+$/, '').toLowerCase();
        return host ? 'https://' + host + '/' : '';
    }

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(area);
        return ok ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    }

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                return fallbackCopy(text);
            });
        }
        return fallbackCopy(text);
    }

    // «Скопійовано»: тултіп Bootstrap біля іконки (не зсуває рядок списку), іконка на 1,5 с — галочка;
    // без Bootstrap — бейдж поруч. role=status — для екранних читачів.
    function flash(button, text) {
        var icon = button.querySelector('i');
        var live = button.querySelector('.visually-hidden');
        if (!live) {
            live = document.createElement('span');
            live.className = 'visually-hidden';
            live.setAttribute('role', 'status');
            button.appendChild(live);
        }
        live.textContent = text;
        if (icon) {
            icon.className = 'fa fa-check';
        }
        var tip = null;
        if (window.bootstrap && window.bootstrap.Tooltip) {
            tip = window.bootstrap.Tooltip.getOrCreateInstance(button, {title: text, trigger: 'manual', placement: 'top'});
            tip.setContent({'.tooltip-inner': text});
            tip.show();
        } else {
            var badge = button.nextElementSibling;
            if (!badge || !badge.classList.contains('sv-copy-done')) {
                badge = document.createElement('span');
                badge.className = 'sv-copy-done badge bg-success ms-1';
                button.insertAdjacentElement('afterend', badge);
            }
            badge.textContent = text;
            badge.hidden = false;
            tip = {hide: function () { badge.hidden = true; }};
        }
        clearTimeout(button._svTimer);
        button._svTimer = setTimeout(function () {
            tip.hide();
            live.textContent = '';
            if (icon) {
                icon.className = 'fa fa-copy';
            }
        }, 1500);
    }

    function button(title) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-secondary sv-copy-url';
        b.title = title;
        b.setAttribute('aria-label', title);
        b.innerHTML = '<i class="fa fa-copy" aria-hidden="true"></i>';
        return b;
    }

    document.addEventListener('click', function (event) {
        var b = event.target.closest('.sv-copy-url');
        if (!b) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        var url = b.getAttribute('data-copy');
        if (!url) {
            var input = b._svInput;
            url = input ? toUrl(input.value) : '';
        }
        if (!url) {
            return;
        }
        copy(url).then(function () {
            flash(b, b.getAttribute('data-copied') || DONE);
        }, function () {
            window.prompt(b.title || TITLE, url);
        });
    }, true);

    function init() {
        document.querySelectorAll('input[data-copy-url]').forEach(function (input) {
            if (input._svCopy) {
                return;
            }
            input._svCopy = true;
            var b = button(input.getAttribute('data-copy-title') || TITLE);
            b._svInput = input;
            var group = document.createElement('div');
            group.className = 'input-group';
            input.parentNode.insertBefore(group, input);
            group.appendChild(input);
            group.appendChild(b);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
