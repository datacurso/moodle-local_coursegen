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
 * The review the professor makes once the course is generated.
 *
 * Course creation without a template pauses for a review too, and everything
 * about how that review looks is borrowed rather than rebuilt, down to the
 * module that drives it: the decision card takes the composer's slot at the
 * bottom of the left column, and asking for a change brings the composer back
 * to type it in. What the professor reads is the generated activities
 * themselves, through the preview link of each row.
 *
 * What differs from free mode is how much can change. The template owns the
 * structure, so there is nothing to add, delete or reorder; the only
 * adjustment on offer is to generate some activities again, all of them from
 * the card or a single one from its row. Approving involves no model at all:
 * the course is built from the result the generation already stored.
 *
 * @module     local_coursegen/local/courseai/template/generation_review
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {getDecisionOverlay} from 'local_coursegen/local/courseai/ui/decision-overlay';
import {showNotices} from 'local_coursegen/local/courseai/template/generation_notices';

const STRING_KEYS = [
    'courseai_template_review_summary',
    'courseai_template_review_adjust',
    'courseai_btn_generate',
];

const ADJUST_ROW_SELECTOR = '[data-action="local_coursegen/template/adjust-activity"]';

let labels = null;

/**
 * The batched string request for every review string key.
 *
 * @param {Array<string>} keys
 * @returns {Array<Object>}
 */
const reviewStringRequests = (keys) => keys.map((key) => ({key, component: 'local_coursegen'}));

/**
 * Copy the fetched review strings onto the labels map, keyed by string id.
 *
 * @param {Object} target
 * @param {Array<string>} keys
 * @param {Array<string>} values
 * @returns {void}
 */
const assignReviewLabels = (target, keys, values) => {
    keys.forEach((key, index) => {
        target[key] = values[index];
    });
};

/**
 * The review's localised strings, fetched once.
 *
 * @returns {Promise<Object>} Keyed by string id.
 */
const getLabels = async() => {
    if (!labels) {
        const requests = reviewStringRequests(STRING_KEYS);
        const values = await getStrings(requests);
        labels = {};
        assignReviewLabels(labels, STRING_KEYS, values);
    }
    return labels;
};

/**
 * The row buttons that ask for a change to one generated activity.
 *
 * @returns {NodeListOf<Element>}
 */
const adjustButtons = () => document.querySelectorAll(ADJUST_ROW_SELECTOR);

/**
 * Show the row button of every activity that was generated, hide the rest.
 *
 * @param {Array} generated The activities the service reports as generated.
 * @returns {void}
 */
const showAdjustButtons = (generated) => {
    const uids = new Set((generated || []).map((entry) => entry.uid));
    adjustButtons().forEach((button) => {
        button.hidden = !uids.has(button.dataset.generationUid);
    });
};

/**
 * Hide every row button, once the review is over.
 *
 * @returns {void}
 */
const hideAdjustButtons = () => {
    adjustButtons().forEach((button) => {
        button.hidden = true;
    });
};

/**
 * Bring the composer back so the professor can type a change.
 *
 * Asking for a change means writing it, so the composer comes back for
 * exactly that, the way free mode's does. Its own button sends the change
 * instead of starting a second run.
 *
 * @param {Object} elements {composer, input, send}
 * @param {Object} texts
 * @returns {void}
 */
const openAdjustComposer = (elements, texts) => {
    const {composer, input, send} = elements;
    if (composer) {
        composer.hidden = false;
    }
    if (input) {
        input.value = '';
        input.focus();
    }
    if (send) {
        send.textContent = texts.courseai_template_review_adjust;
        send.disabled = false;
    }
};

/**
 * Validate the composer's instruction and, once it is not empty, settle the
 * review with a "regenerate" decision for the activities it is aimed at.
 *
 * @param {Object} elements {send, input, composer}
 * @param {Object} selected {ids}: the activity uids the change is aimed at; empty means all.
 * @param {Object} texts
 * @param {Function} settle
 * @returns {void}
 */
const submitAdjustment = (elements, selected, texts, settle) => {
    const {send, input, composer} = elements;
    const instruction = ((input && input.value) || '').trim();
    if (!instruction) {
        if (input) {
            input.focus();
        }
        return;
    }
    if (composer) {
        composer.hidden = true;
    }
    send.textContent = texts.courseai_btn_generate;
    settle({action: 'replan_activity', targetIds: selected.ids, instruction});
};

/**
 * Wire the controls of one review: the card's two buttons, each row's button
 * and the composer's send. Whichever answers first settles the review, and
 * every listener of this review is removed together through the abort signal.
 *
 * @param {Object} overlay
 * @param {Object} texts
 * @param {Function} resolve
 * @returns {void}
 */
const wireDecisionButtons = (overlay, texts, resolve) => {
    const elements = {
        composer: document.getElementById('tplInputBar'),
        input: document.getElementById('tplPromptInput'),
        send: document.getElementById('tplModeGenerate'),
    };
    const lifetime = new AbortController();
    const options = {signal: lifetime.signal};
    const selected = {ids: []};

    const settle = (answer) => {
        lifetime.abort();
        hideAdjustButtons();
        overlay.hide();
        resolve(answer);
    };
    const adjustTargets = (ids) => {
        selected.ids = ids;
        overlay.hide();
        openAdjustComposer(elements, texts);
    };

    document.getElementById('cgDecisionAccept').addEventListener(
        'click',
        () => settle({action: 'accept', targetIds: [], instruction: ''}),
        options
    );
    document.getElementById('cgDecisionAdjust').addEventListener('click', () => adjustTargets([]), options);
    adjustButtons().forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            adjustTargets([button.dataset.generationUid]);
        }, options);
    });
    elements.send.addEventListener('click', () => submitAdjustment(elements, selected, texts, settle), options);
};

/**
 * Open the review and resolve with what the professor answered.
 *
 * @param {Array} generated The activities that were generated, from the service.
 * @returns {Promise<Object>} {action, targetIds, instruction}
 */
export const askForDecision = async(generated) => {
    const texts = await getLabels();
    const overlay = getDecisionOverlay();
    const body = overlay.getBody();
    if (body) {
        const count = (generated || []).length;
        body.textContent = texts.courseai_template_review_summary.replace('{$a}', count);
    }
    showAdjustButtons(generated);
    await showNotices(generated);
    overlay.show();

    return new Promise((resolve) => wireDecisionButtons(overlay, texts, resolve));
};
