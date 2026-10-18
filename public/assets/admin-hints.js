/* Кругла підказка біля назв колонок у списках адмінки.
 *
 * EasyAdmin 5.5 не вміє показувати help у шапці таблиці, а класти HTML у саму
 * мітку не можна: той самий рядок іде в data-label і на телефоні виводиться як
 * текст. Тому текст підказки приїздить у data-hint на комірках, а сюди -
 * робота перенести його в <th> і повісити тултіп.
 */
(function () {
    'use strict';

    function init() {
        var table = document.querySelector('table.datagrid');
        if (!table || typeof window.bootstrap === 'undefined') {
            return;
        }

        table.querySelectorAll('thead th[data-column]').forEach(function (th) {
            if (th.querySelector('.ea-column-hint')) {
                return;
            }

            var column = th.getAttribute('data-column');
            // беремо будь-яку комірку цієї колонки: підказка на всіх однакова
            var cell = table.querySelector('tbody td[data-column="' + column + '"][data-hint]');
            if (!cell) {
                return;
            }

            // назва лежить усередині <a> (сортовна колонка) або <span>. Чіпляти
            // іконку поруч не можна - вона зʼїжджає під назву окремим рядком,
            // бо заголовок викладений колонкою. Тому кладемо всередину.
            var holder = th.querySelector('a, span') || th;

            // саме span, а не посилання: вкладене <a> у сортовній колонці
            // ламало б клік по сортуванню
            var icon = document.createElement('span');
            icon.className = 'ea-column-hint';
            icon.setAttribute('tabindex', '0');
            icon.setAttribute('role', 'button');
            icon.setAttribute('data-bs-toggle', 'tooltip');
            icon.setAttribute('data-bs-placement', 'top');
            icon.setAttribute('title', cell.getAttribute('data-hint'));
            icon.innerHTML = '<i class="fa fa-circle-info"></i>';
            // EasyAdmin робить span усередині заголовка блоковим, і без цього
            // іконка падає окремим рядком під назву колонки
            icon.style.setProperty('display', 'inline-block', 'important');
            icon.style.verticalAlign = 'middle';
            icon.style.marginInlineStart = '.25rem';
            icon.style.marginInlineEnd = '.1rem';
            icon.style.opacity = '.55';
            icon.style.cursor = 'help';

            // впритул до назви, а не в кінець заголовка: між ними інакше
            // стоять стрілки сортування і підказка виглядає відірваною
            var sortIcon = holder.querySelector('svg, .icon, i');
            if (sortIcon) {
                holder.insertBefore(icon, sortIcon);
            } else {
                holder.appendChild(icon);
            }

            new window.bootstrap.Tooltip(icon);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
