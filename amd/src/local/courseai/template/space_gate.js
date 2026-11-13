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
 * One question of the card: what the administrator asked for, and the button that uploads the file.
 *
 * The markup is the one of the questions the AI asks (a file question), so the card reads like them.
 *
 * @param {Object} entry {sectionIndex, activityIndex, activity}.
 * @param {string} upload The text of the button.
 * @param {Function} onBring Called with (sectionIndex, activityIndex) when the button is pressed.
 * @returns {HTMLElement}
 */
const questionOf = (entry, upload, onBring) => {
    const {activity} = entry;
    const block = document.createElement('fieldset');
    block.className = 'cg-question';
    const prompt = document.createElement('legend');
    prompt.className = 'cg-question-prompt';
    prompt.textContent = String(activity.spaceinstruction || '').trim() || activity.name || '';
    block.append(prompt);
    if (activity.spaceinstruction && activity.name) {
        const hint = document.createElement('span');
        hint.className = 'cg-question-hint';
        hint.textContent = activity.name;
        block.append(hint);
    }
    const group = document.createElement('div');
    group.className = 'cg-question-file';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-outline-secondary';
    button.textContent = upload;
    button.addEventListener('click', () => onBring(entry.sectionIndex, entry.activityIndex));
    group.append(button);
    block.append(group);
    return block;
};

/**
 * Show the card for the files that are missing (it replaces the one shown before).
 *
 * @param {Object[]} missing What {@see missingSpaces} returned.
 * @param {Function} onBring Called with (sectionIndex, activityIndex) to upload the file of a space.
 * @returns {Promise<void>}
 */
export const showSpaceGate = async(missing, onBring) => {
    const [title, upload] = await getStrings([
        {key: missing.length === 1 ? 'courseai_template_space_gate_title' : 'courseai_template_space_gate_title_many',
            component: 'local_coursegen'},
        {key: 'courseai_template_space_gate_upload', component: 'local_coursegen'},
    ]);
    clearSpaceGate();
    const card = document.createElement('div');
    card.id = CARD_ID;
    card.className = 'cg-log-entry cg-log-entry--ai cg-log-entry--turn-ai cg-question-card';
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
    message.append(heading, ...missing.map((entry) => questionOf(entry, upload, onBring)));
    body.append(message);
    card.append(bar, body);
    const host = document.getElementById('cgNoticeDock') || document.body;
    host.append(card);
    card.scrollIntoView({block: 'nearest'});
};
