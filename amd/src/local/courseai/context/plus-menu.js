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
 * "+" options menu for the Course AI toolbars (ChatGPT-style).
 *
 * Pure presentation layer: the real controls keep their ids and listeners
 * (hidden checkboxes for the toggles, the hidden language select as the
 * single source of truth, the syllabus/guidelines buttons re-styled as menu
 * items). This module only handles opening/closing the panel, the language
 * flyout submenu, and closing the menu when an action item is used.
 *
 * @module     local_coursegen/local/courseai/context/plus-menu
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wire one "+" options menu instance.
 *
 * @param {Object} options
 * @param {HTMLElement|null} options.button      - The "+" trigger button.
 * @param {HTMLElement|null} options.panel       - The dropdown panel.
 * @param {HTMLElement|null} options.langItem    - The language menu item (flyout trigger).
 * @param {HTMLElement|null} options.langValue   - Span showing the current language code.
 * @param {HTMLElement|null} options.langSubmenu - The <ul> flyout with language options.
 * @param {HTMLSelectElement|null} options.langSelect - The hidden select (source of truth).
 * @param {Array} options.languages - [{code}] available languages.
 * @param {Function} [options.onOpen] - Called right before the panel opens
 *     (e.g. to close the guidelines popover so both never overlap).
 * @returns {{close: Function}|null} Menu API, or null when the toolbar is absent.
 */
export const wirePlusMenu = ({button, panel, langItem, langValue, langSubmenu, langSelect, languages, onOpen}) => {
    if (!button || !panel) {
        return null;
    }

    const closeSubmenu = () => {
        if (langSubmenu) {
            langSubmenu.classList.remove('open');
        }
        if (langItem) {
            langItem.setAttribute('aria-expanded', 'false');
        }
    };

    const close = () => {
        panel.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
        closeSubmenu();
    };

    const open = () => {
        if (typeof onOpen === 'function') {
            onOpen();
        }
        panel.classList.add('open');
        button.setAttribute('aria-expanded', 'true');
    };

    const isOpen = () => panel.classList.contains('open');

    const refreshLangUi = () => {
        const current = langSelect ? String(langSelect.value || '').toLowerCase() : '';
        if (langValue) {
            langValue.textContent = current.toUpperCase();
        }
        if (langSubmenu) {
            langSubmenu.querySelectorAll('.plus-menu-sub__item').forEach((item) => {
                item.classList.toggle('is-selected', item.dataset.lang === current);
            });
        }
    };

    const renderLangSubmenu = () => {
        if (!langSubmenu || !langSelect) {
            return;
        }
        // Languages come from the site config; fall back to whatever options
        // the hidden select carries (es/en defaults) when the list is empty.
        const codes = (languages && languages.length > 0)
            ? languages.map((lang) => String(lang.code || '').toLowerCase()).filter(Boolean)
            : Array.prototype.map.call(langSelect.options, (opt) => String(opt.value).toLowerCase());

        langSubmenu.innerHTML = '';
        codes.forEach((code) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'none');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'plus-menu-sub__item';
            btn.setAttribute('role', 'menuitemradio');
            btn.dataset.lang = code;
            btn.innerHTML = '<svg class="plus-menu-sub__check" width="12" height="12" viewBox="0 0 24 24" '
                + 'fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" '
                + 'stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>'
                + '<span>🌐 ' + code.toUpperCase() + '</span>';
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                langSelect.value = code;
                // Existing listeners propagate the change into the shared
                // state and the mirrored toolbar.
                langSelect.dispatchEvent(new Event('change', {bubbles: false}));
                refreshLangUi();
                closeSubmenu();
            });
            item.appendChild(btn);
            langSubmenu.appendChild(item);
        });
        refreshLangUi();
    };

    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (isOpen()) {
            close();
            return;
        }
        // The submenu is rebuilt on every open: the language list is static
        // but the selection mark must reflect the CURRENT value (it can be
        // changed from the mirrored toolbar or a session resume).
        renderLangSubmenu();
        open();
    });

    if (langItem) {
        langItem.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const expanded = langSubmenu && langSubmenu.classList.contains('open');
            if (expanded) {
                closeSubmenu();
                return;
            }
            refreshLangUi();
            if (langSubmenu) {
                langSubmenu.classList.add('open');
            }
            langItem.setAttribute('aria-expanded', 'true');
        });
    }

    // Action items (syllabus / guidelines) close the menu before their own
    // listeners run their action; toggle rows keep the menu open so both
    // switches can be flipped in one visit.
    panel.querySelectorAll('.plus-menu-item').forEach((item) => {
        if (item === langItem || item.classList.contains('plus-menu-item--toggle')) {
            return;
        }
        item.addEventListener('click', () => {
            close();
        });
    });

    // Clicks inside the panel must not reach the document-level closers
    // (ours below, and the guidelines popover's own outside-click closer).
    panel.addEventListener('click', (event) => {
        event.stopPropagation();
    });

    document.addEventListener('click', (event) => {
        if (isOpen() && !panel.contains(event.target) && event.target !== button && !button.contains(event.target)) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            close();
        }
    });

    // Keep the language row in sync when the value changes elsewhere (the
    // mirrored toolbar's submenu, or a resume restoring the saved language).
    if (langSelect) {
        langSelect.addEventListener('change', refreshLangUi);
    }
    renderLangSubmenu();

    return {close};
};
