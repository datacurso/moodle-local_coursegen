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
 * The activities the teacher brings to the spaces of a template.
 *
 * A space asks for an activity of one type. The teacher picks one of their own, from the courses where they can
 * copy activities; the row keeps it until the generation starts, and the course gets a copy of it in that place.
 *
 * @module     local_coursegen/local/courseai/template/space_activities
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';
import Selectors from './selectors';
import {setSpaceActivity, clearSpaceActivity} from './state';

/**
 * Whether a generation is on screen, from which on the spaces can no longer change.
 *
 * @returns {boolean}
 */
const isGenerating = () => document.body.classList.contains(Selectors.classes.generating);

/**
 * Escape a text for HTML.
 *
 * @param {string} text
 * @returns {string}
 */
const escape = (text) => {
    const holder = document.createElement('span');
    holder.textContent = String(text ?? '');
    return holder.innerHTML;
};

/**
 * The activities of a type the teacher can bring.
 *
 * @param {string} modname
 * @returns {Promise<Object[]>} Each {cmid, name, coursename}.
 */
export const fetchCandidates = async(modname) => {
    const [request] = Ajax.call([{methodname: 'local_coursegen_get_space_candidates', args: {modname}}]);
    const response = await request;
    return response.activities || [];
};

/**
 * The list of activities to pick from, with a search box when there are several.
 *
 * @param {Object[]} candidates
 * @param {number} selected - The cmid already chosen, 0 when none.
 * @param {Object} texts - {search, empty}.
 * @returns {string} HTML.
 */
export const candidatesHtml = (candidates, selected, texts) => {
    if (!candidates.length) {
        return `<p class="text-muted mb-0">${escape(texts.empty)}</p>`;
    }
    const items = candidates.map((candidate) => {
        const checked = Number(candidate.cmid) === Number(selected) ? ' checked' : '';
        const search = escape(`${candidate.name} ${candidate.coursename}`.toLowerCase());
        return `<label class="list-group-item d-flex align-items-start gap-2" data-search="${search}">`
            + `<input type="radio" class="form-check-input mt-1" name="cgSpaceActivity"`
            + ` value="${Number(candidate.cmid)}"${checked}>`
            + `<span><strong>${escape(candidate.name)}</strong><br>`
            + `<small class="text-muted">${escape(candidate.coursename)}</small></span></label>`;
    }).join('');
    return `<input type="search" class="form-control mb-2" data-region="cg-space-activity-search"`
        + ` placeholder="${escape(texts.search)}" aria-label="${escape(texts.search)}">`
        + `<div class="list-group" style="max-height:50vh;overflow:auto">${items}</div>`;
};

/**
 * Show only the activities whose name or course has the searched words.
 *
 * @param {HTMLElement} root
 * @param {string} query
 */
const filterCandidates = (root, query) => {
    const words = query.toLowerCase().split(/\s+/).filter(Boolean);
    root.querySelectorAll('[data-search]').forEach((item) => {
        const text = item.dataset.search;
        item.classList.toggle('d-none', !words.every((word) => text.includes(word)));
    });
};

/**
 * Open the list of the teacher's activities for one space and keep the one they pick.
 *
 * @param {Object} tplState
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Function} onChange - Called after the row changed, to draw it again; may return a promise.
 * @returns {Promise<void>}
 */
export const pickSpaceActivity = async(tplState, sectionIndex, activityIndex, onChange) => {
    if (isGenerating()) {
        return;
    }
    const row = tplState.sections[sectionIndex]?.activities[activityIndex];
    if (!row || row.spacekind !== 'activity') {
        return;
    }
    try {
        const [candidates, title, search, empty, use] = await Promise.all([
            fetchCandidates(row.modname),
            getString('courseai_template_space_activity_modal', 'local_coursegen', row.typelabel || row.name),
            getString('courseai_template_space_activity_search', 'local_coursegen'),
            getString('courseai_template_space_activity_empty', 'local_coursegen'),
            getString('courseai_template_space_activity_use', 'local_coursegen'),
        ]);
        const selected = row.spaceactivity ? row.spaceactivity.cmid : 0;
        const modal = await ModalSaveCancel.create({
            title,
            body: candidatesHtml(candidates, selected, {search, empty}),
            buttons: {save: use},
            removeOnClose: true,
            show: true,
        });
        const root = modal.getRoot()[0];
        root.addEventListener('input', (event) => {
            if (event.target.matches('[data-region="cg-space-activity-search"]')) {
                filterCandidates(root, event.target.value);
            }
        });
        modal.getRoot().on(ModalEvents.save, async() => {
            const picked = root.querySelector('input[name="cgSpaceActivity"]:checked');
            const chosen = picked ? candidates.find((c) => Number(c.cmid) === Number(picked.value)) : null;
            if (chosen && setSpaceActivity(tplState, sectionIndex, activityIndex, chosen)) {
                await onChange();
            }
        });
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Empty the activity of one space.
 *
 * @param {Object} tplState
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Function} onChange - Called after the row changed, to draw it again; may return a promise.
 * @returns {Promise<void>}
 */
export const removeSpaceActivity = async(tplState, sectionIndex, activityIndex, onChange) => {
    if (isGenerating()) {
        return;
    }
    if (clearSpaceActivity(tplState, sectionIndex, activityIndex)) {
        await onChange();
    }
};
