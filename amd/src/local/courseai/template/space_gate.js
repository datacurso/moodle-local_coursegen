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
 * The check made when the teacher presses Generate: a required space still without its file stops it, before the AI
 * is asked anything, with a card that says what the administrator asked for and lets the teacher bring it from there.
 *
 * The card is one of the cards of the run (the same look as the retry card), in the slot above the input bar.
 *
 * @module     local_coursegen/local/courseai/template/space_gate
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {isSpaceFilled} from 'local_coursegen/local/courseai/template/state';

const CARD_ID = 'cgSpaceGateCard';

/**
 * The required spaces still without what the teacher brings, with their place in the structure.
 *
 * @param {Object} tplState
 * @returns {Object[]} Each {sectionIndex, activityIndex, activity}.
 */
export const missingSpaces = (tplState) => {
    const missing = [];
    (tplState.sections || []).forEach((section, sectionIndex) => {
        (section.activities || []).forEach((activity, activityIndex) => {
            if (activity.isspace && activity.spacerequired && !isSpaceFilled(activity)) {
                missing.push({sectionIndex, activityIndex, activity});
            }
        });
    });
    return missing;
};

/**
 * Remove the card, when everything it asked for is there or the template changed.
 */
export const clearSpaceGate = () => {
    document.getElementById(CARD_ID)?.remove();
};

/**
 * How many required spaces the template has, filled or not: the count the title of the card says.
 *
 * @param {Object} tplState
 * @returns {number}
 */
export const requiredSpaceCount = (tplState) => (tplState.sections || [])
    .reduce((count, section) => count + (section.activities || [])
        .filter((activity) => activity.isspace && activity.spacerequired).length, 0);

/**
 * One line of the list: what the administrator asked for (and the file of the template), and the button that uploads
 * the file. Pressing the text shows the card of the file in the structure.
 *
 * @param {Object} entry {sectionIndex, activityIndex, activity}.
 * @param {string} upload The text of the button.
 * @param {Object} actions {onBring, onReveal}, each called with (sectionIndex, activityIndex).
 * @returns {HTMLElement}
 */
const itemOf = (entry, upload, actions) => {
    const {activity} = entry;
    const item = document.createElement('li');
    item.className = 'cg-space-gate-item';
    const what = document.createElement('button');
    what.type = 'button';
    what.className = 'cg-space-gate-what';
    const prompt = document.createElement('span');
    prompt.className = 'cg-question-prompt';
    prompt.textContent = String(activity.spaceinstruction || '').trim() || activity.name || '';
    what.append(prompt);
    if (activity.spaceinstruction && activity.name) {
        const hint = document.createElement('span');
        hint.className = 'cg-question-hint';
        hint.textContent = activity.name;
        what.append(hint);
    }
    what.addEventListener('click', () => actions.onReveal(entry.sectionIndex, entry.activityIndex));
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-outline-secondary';
    button.textContent = upload;
    button.addEventListener('click', () => actions.onBring(entry.sectionIndex, entry.activityIndex));
    item.append(what, button);
    return item;
};

/**
 * Show the card for the files that are missing (it replaces the one shown before): how many are left, and the next one.
 *
 * @param {Object[]} missing What {@see missingSpaces} returned.
 * @param {number} total How many required files the template asks for.
 * @param {Object} actions {onBring, onReveal}: upload the file of a space, or show its card in the structure.
 * @returns {Promise<void>}
 */
export const showSpaceGate = async(missing, total, actions) => {
    const titleKey = total > 1 ? 'courseai_template_space_gate_title_many' : 'courseai_template_space_gate_title';
    const [title, upload] = await getStrings([
        {key: titleKey, component: 'local_coursegen', param: {missing: missing.length, total}},
        {key: 'courseai_template_space_gate_upload', component: 'local_coursegen'},
    ]);
    clearSpaceGate();
    const card = document.createElement('div');
    card.id = CARD_ID;
    card.className = 'cg-log-entry cg-log-entry--ai cg-log-entry--turn-ai cg-question-card cg-space-gate';
    card.setAttribute('role', 'group');
    card.setAttribute('aria-label', title);
    const bar = document.createElement('span');
    bar.className = 'cg-log-bar';
    bar.setAttribute('aria-hidden', 'true');
    const body = document.createElement('span');
    body.className = 'cg-log-body';
    const message = document.createElement('span');
    message.className = 'cg-log-msg';
    const heading = document.createElement('strong');
    heading.className = 'd-block mb-2';
    heading.textContent = title;
    // One file at a time, the next one missing: the card never grows with the number of files, and after each upload
    // it shows the next one (the title says how many are left).
    const list = document.createElement('ul');
    list.className = 'cg-space-gate-list';
    list.append(itemOf(missing[0], upload, actions));
    message.append(heading, list);
    body.append(message);
    card.append(bar, body);
    const host = document.getElementById('cgNoticeDock') || document.body;
    host.append(card);
    card.scrollIntoView({block: 'nearest'});
};
