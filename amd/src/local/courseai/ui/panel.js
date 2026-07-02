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
 * Shared inline text-input panel used throughout the planning UI.
 *
 * @module     local_coursegen/local/courseai/ui/panel
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create an inline textarea panel with cancel / send buttons.
 *
 * Covers both the "adjust" variant (fixed placeholder from texts) and the
 * "add" variant (custom placeholder passed by the caller). When no placeholder
 * is provided it falls back to texts.courseai_adjust_placeholder.
 *
 * @param {Object}   opts
 * @param {Function} opts.onSubmit    - Called with the trimmed text value when send is clicked.
 * @param {Object}   opts.texts       - Pre-loaded lang strings (courseai_btn_cancel, courseai_btn_send_adjust,
 *                                      courseai_adjust_placeholder).
 * @param {string}   [opts.placeholder] - Optional placeholder text; defaults to texts.courseai_adjust_placeholder.
 * @returns {{ panel: HTMLElement, open: Function }}
 */
export const createTextPanel = ({onSubmit, texts, placeholder}) => {
    const resolvedPlaceholder = placeholder !== undefined ? placeholder : (texts.courseai_adjust_placeholder || '');

    const panel = document.createElement('div');
    panel.className = 'dp-ai-inline';
    panel.style.display = 'none';

    const textarea = document.createElement('textarea');
    textarea.className = 'dp-ai-textarea';
    textarea.placeholder = resolvedPlaceholder;
    textarea.rows = 2;

    const actions = document.createElement('div');
    actions.className = 'dp-ai-actions';

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'dp-ai-btn dp-ai-btn--secondary';
    cancel.textContent = texts.courseai_btn_cancel;

    const send = document.createElement('button');
    send.type = 'button';
    send.className = 'dp-ai-btn dp-ai-btn--primary';
    send.textContent = texts.courseai_btn_send_adjust;

    const closePanel = (event) => {
        event.preventDefault();
        event.stopPropagation();
        panel.style.display = 'none';
        textarea.value = '';
    };

    cancel.addEventListener('click', closePanel);
    send.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const value = textarea.value.trim();
        if (!value) {
            textarea.focus();
            return;
        }
        onSubmit(value);
        panel.style.display = 'none';
        textarea.value = '';
    });

    actions.appendChild(cancel);
    actions.appendChild(send);
    panel.appendChild(textarea);
    panel.appendChild(actions);

    return {
        panel,
        open: () => {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            if (panel.style.display !== 'none') {
                textarea.focus();
            }
        },
    };
};
