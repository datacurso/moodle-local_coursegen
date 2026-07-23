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
 * Inline prompt editor for activity configuration.
 *
 * @module     local_coursegen/local/template/prompt_editor
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

/**
 * Append a prompt display/editor below an activity.
 *
 * @param {HTMLElement} cmitem The cmitem element.
 * @param {number} cmid Course module ID.
 * @param {string} val Current prompt value.
 * @param {Object} st Wizard state.
 */
export const appendPrompt = (cmitem, cmid, val, st) => {
    const pw = document.createElement('div');
    pw.setAttribute('data-tpl-prompt-wrap', cmid);
    pw.style.cssText = 'padding:0 1rem .5rem 3.5rem';
    showEditor(pw, cmid, val, st);
    cmitem.appendChild(pw);
};

/**
 * Show an inline textarea editor for the prompt.
 *
 * @param {HTMLElement} pw Prompt wrapper element.
 * @param {number} cmid Course module ID.
 * @param {string} val Current value.
 * @param {Object} st Wizard state.
 */
const showEditor = (pw, cmid, val, st) => {
    pw.innerHTML = `<textarea class="form-control" data-tpl-prompt="${cmid}" rows="2"
        placeholder="Describe how AI should modify this activity...">${val}</textarea>`;
    const ta = pw.querySelector('textarea');
    ta?.addEventListener('input', () => {
        st.activityPrompt[cmid] = ta.value;
        setState(st);
    });
};
