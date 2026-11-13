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
 * One line of the card: what the administrator asked for, and the button that brings it.
 *
 * @param {Object} entry {sectionIndex, activityIndex, activity}.
 * @param {Object} texts The strings of the card.
 * @param {Function} onBring Called with (sectionIndex, activityIndex) when the button is pressed.
 * @returns {HTMLElement}
 */
const lineOf = (entry, texts, onBring) => {
    const {activity} = entry;
    const line = document.createElement('span');
    line.className = 'd-block mb-2';
    const what = document.createElement('span');
    what.className = 'd-block';
    what.textContent = String(activity.spaceinstruction || '').trim() || activity.name || '';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'cg-card-button cg-card-button--primary mt-1';
    button.textContent = activity.spacekind === 'activity' ? texts.choose : texts.upload;
    button.addEventListener('click', () => onBring(entry.sectionIndex, entry.activityIndex));
    line.append(what, button);
    return line;
};

/**
 * Show the card for the spaces that are missing (it replaces the one shown before).
 *
 * @param {Object[]} missing What {@see missingSpaces} returned.
 * @param {Function} onBring Called with (sectionIndex, activityIndex) to bring the file (or the activity) of a space.
 * @returns {Promise<void>}
 */
export const showSpaceGate = async(missing, onBring) => {
    const one = missing.length === 1 && missing[0].activity.spacekind !== 'activity';
    const [title, text, upload, choose] = await getStrings([
        {key: one ? 'courseai_template_space_gate_title' : 'courseai_template_space_gate_title_many', component: 'local_coursegen'},
        {key: 'courseai_template_space_gate_text', component: 'local_coursegen'},
        {key: 'courseai_template_space_gate_upload', component: 'local_coursegen'},
        {key: 'courseai_template_space_gate_choose', component: 'local_coursegen'},
    ]);
    clearSpaceGate();
    const card = document.createElement('div');
    card.id = CARD_ID;
    card.className = 'cg-log-entry cg-log-entry--turn-ai cg-log-entry--retrying';
    card.setAttribute('role', 'alert');
    const bar = document.createElement('span');
    bar.className = 'cg-log-bar';
    bar.setAttribute('aria-hidden', 'true');
    const content = document.createElement('span');
    content.className = 'cg-log-body';
    const message = document.createElement('span');
    message.className = 'cg-log-msg';
    const heading = document.createElement('strong');
    heading.className = 'd-block mb-1';
    heading.textContent = title;
    const body = document.createElement('span');
    body.className = 'd-block mb-2';
    body.textContent = text;
    message.append(heading, body, ...missing.map((entry) => lineOf(entry, {upload, choose}, onBring)));
    content.append(message);
    card.append(bar, content);
    const host = document.getElementById('cgNoticeDock') || document.body;
    host.append(card);
    card.scrollIntoView({block: 'nearest'});
};
