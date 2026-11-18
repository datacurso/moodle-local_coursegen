// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The language picker of the template mode input bar.
 *
 * A styled chip that opens the same searchable list as the free mode, instead of the browser's own select. The
 * first entry leaves the language to the AI; every entry explains itself in a tooltip. The hidden select next to
 * it stays the single source of truth, so the rest of the page keeps reading it.
 *
 * @module     local_coursegen/local/courseai/template/language_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const CHECK = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

/**
 * The tooltip of one entry: what choosing it does.
 *
 * @param {HTMLElement} wrap The picker's wrapper, which carries the two texts.
 * @param {{code: string, name: string}} entry
 * @returns {string}
 */
const describe = (wrap, entry) => {
    if (entry.code === '') {
        return wrap.dataset.autoDesc || '';
    }
    return (wrap.dataset.forceDesc || '').replace('__LANG__', entry.name);
};

/**
 * Turn the hidden select into the chip and its searchable list.
 *
 * @param {HTMLSelectElement} select The hidden select that holds the value.
 */
export const wireLanguagePicker = (select) => {
    const wrap = document.getElementById('tplLangWrap');
    const button = document.getElementById('tplLangBtn');
    const panel = document.getElementById('tplLangPopover');
    const search = document.getElementById('tplLangSearch');
    const list = document.getElementById('tplLangList');
    const value = document.getElementById('tplLangValue');
    const closeButton = document.getElementById('tplLangClose');
    if (!select || !wrap || !button || !panel || !list || !value) {
        return;
    }

    const entries = () => Array.prototype.map.call(select.options, (option) => ({
        code: String(option.value),
        name: String(option.textContent),
    }));

    const refresh = () => {
        const current = entries().find((entry) => entry.code === select.value);
        value.textContent = current ? current.name : '';
        button.title = current ? describe(wrap, current) : button.title;
    };

    const render = (query) => {
        const needle = String(query || '').trim().toLowerCase();
        const matches = entries().filter((entry) => entry.code === '' && !needle
            || entry.name.toLowerCase().includes(needle) || (entry.code && entry.code.includes(needle)));
        list.textContent = '';
        if (matches.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'pop-empty';
            empty.textContent = '—';
            list.appendChild(empty);
            return;
        }
        matches.forEach((entry) => {
            const item = document.createElement('li');
            item.className = 'pop-item pop-item--lang' + (entry.code === select.value ? ' selected' : '');
            const choose = document.createElement('button');
            choose.className = 'pop-select-btn';
            choose.type = 'button';
            choose.dataset.lang = entry.code;
            choose.title = describe(wrap, entry);
            const check = document.createElement('span');
            check.className = 'pop-lang-check';
            check.innerHTML = CHECK;
            const name = document.createElement('span');
            name.className = 'pop-item-name';
            name.textContent = entry.name;
            choose.append(check, name);
            item.appendChild(choose);
            list.appendChild(item);
        });
    };

    const close = () => {
        panel.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
    };

    const open = () => {
        if (search) {
            search.value = '';
        }
        render('');
        panel.classList.add('open');
        button.setAttribute('aria-expanded', 'true');
        if (search) {
            search.focus();
        }
    };

    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (panel.classList.contains('open')) {
            close();
            return;
        }
        open();
    });
    if (search) {
        search.addEventListener('input', () => render(search.value));
    }
    list.addEventListener('click', (event) => {
        event.stopPropagation();
        const choice = event.target.closest('[data-lang]');
        if (!choice) {
            return;
        }
        select.value = choice.dataset.lang;
        select.dispatchEvent(new Event('change', {bubbles: false}));
        refresh();
        close();
    });
    if (closeButton) {
        closeButton.addEventListener('click', (event) => {
            event.stopPropagation();
            close();
        });
    }
    panel.addEventListener('click', (event) => event.stopPropagation());
    document.addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });
    select.addEventListener('change', refresh);
    refresh();
};
