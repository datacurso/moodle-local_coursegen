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
 * items). This module handles opening/closing the panel and the searchable
 * language popover (same interaction pattern as the guidelines popover).
 *
 * @module     local_coursegen/local/courseai/context/plus-menu
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Escape a string for safe interpolation into innerHTML.
 *
 * @param {string} value
 * @returns {string}
 */
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[char]));

/**
 * Wire one "+" options menu instance.
 *
 * @param {Object} options
 * @param {HTMLElement|null} options.button      - The "+" trigger button.
 * @param {HTMLElement|null} options.panel       - The dropdown panel.
 * @param {HTMLElement|null} options.langItem    - The language menu item (popover trigger).
 * @param {HTMLElement|null} options.langValue   - Span showing the current language code.
 * @param {HTMLElement|null} options.langPopover - The searchable language popover panel.
 * @param {HTMLElement|null} options.langSearch  - The popover's search input.
 * @param {HTMLElement|null} options.langList    - The popover's <ul> results list.
 * @param {HTMLElement|null} options.langCloseBtn - The popover's close (X) button.
 * @param {HTMLSelectElement|null} options.langSelect - The hidden select (source of truth).
 * @param {Array} options.languages - [{code, name}] available languages.
 * @param {Function} [options.onOpen] - Called right before the panel opens
 *     (e.g. to close the guidelines popover so both never overlap).
 * @returns {{close: Function, closeLangPopover: Function}|null} Menu API, or
 *     null when the toolbar is absent.
 */
export const wirePlusMenu = ({
    button, panel, langItem, langValue, langPopover, langSearch, langList, langCloseBtn,
    langSelect, languages, onOpen,
}) => {
    if (!button || !panel) {
        return null;
    }

    // Normalized [{code, name}] list; falls back to the hidden select's
    // options (es/en defaults) when the site list is empty.
    const langEntries = (languages && languages.length > 0)
        ? languages.map((lang) => ({
            code: String(lang.code || '').toLowerCase(),
            name: String(lang.name || String(lang.code || '').toUpperCase()),
        })).filter((lang) => lang.code)
        : Array.prototype.map.call((langSelect && langSelect.options) || [], (opt) => ({
            code: String(opt.value).toLowerCase(),
            name: String(opt.value).toUpperCase(),
        }));

    const closeLangPopover = () => {
        if (langPopover) {
            langPopover.classList.remove('open');
        }
    };

    const close = () => {
        panel.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
    };

    const open = () => {
        if (typeof onOpen === 'function') {
            onOpen();
        }
        closeLangPopover();
        panel.classList.add('open');
        button.setAttribute('aria-expanded', 'true');
    };

    const isOpen = () => panel.classList.contains('open');

    const refreshLangValue = () => {
        if (langValue && langSelect) {
            langValue.textContent = String(langSelect.value || '').toUpperCase();
        }
    };

    const renderLangList = (query) => {
        if (!langList || !langSelect) {
            return;
        }
        const current = String(langSelect.value || '').toLowerCase();
        const needle = String(query || '').trim().toLowerCase();
        const matches = langEntries.filter((lang) => !needle
            || lang.name.toLowerCase().includes(needle)
            || lang.code.includes(needle));

        if (matches.length === 0) {
            langList.innerHTML = '<li class="pop-empty">—</li>';
            return;
        }

        langList.innerHTML = matches.map((lang) => (
            `<li class="pop-item pop-item--lang${lang.code === current ? ' selected' : ''}" data-lang="${lang.code}">
                <button class="pop-select-btn" type="button" data-lang="${lang.code}">
                    <span class="pop-lang-check">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </span>
                    <span class="pop-item-name">${escapeHtml(lang.name)}</span>
                </button>
            </li>`
        )).join('');
    };

    const openLangPopover = () => {
        if (!langPopover) {
            return;
        }
        close();
        if (langSearch) {
            langSearch.value = '';
        }
        renderLangList('');
        langPopover.classList.add('open');
        if (langSearch) {
            langSearch.focus();
        }
    };

    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (isOpen()) {
            close();
            return;
        }
        refreshLangValue();
        open();
    });

    if (langItem) {
        langItem.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            openLangPopover();
        });
    }

    if (langSearch) {
        langSearch.addEventListener('input', () => {
            renderLangList(langSearch.value);
        });
    }

    if (langList) {
        langList.addEventListener('click', (event) => {
            event.stopPropagation();
            const btn = event.target.closest('[data-lang]');
            if (!btn || !langSelect) {
                return;
            }
            langSelect.value = btn.dataset.lang;
            // Existing listeners propagate the change into the shared state
            // and the mirrored toolbar.
            langSelect.dispatchEvent(new Event('change', {bubbles: false}));
            refreshLangValue();
            closeLangPopover();
        });
    }

    if (langCloseBtn) {
        langCloseBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            closeLangPopover();
        });
    }

    if (langPopover) {
        // Clicks inside the popover (search input, list scroll) must not
        // reach the document-level closers.
        langPopover.addEventListener('click', (event) => {
            event.stopPropagation();
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
        if (langPopover && langPopover.classList.contains('open')
                && !langPopover.contains(event.target) && event.target !== langItem) {
            closeLangPopover();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (langPopover && langPopover.classList.contains('open')) {
            closeLangPopover();
            return;
        }
        if (isOpen()) {
            close();
        }
    });

    // Keep the language row in sync when the value changes elsewhere (the
    // mirrored toolbar, or a resume restoring the saved language).
    if (langSelect) {
        langSelect.addEventListener('change', refreshLangValue);
    }
    refreshLangValue();

    return {close, closeLangPopover};
};
