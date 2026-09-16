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
 * The review the professor makes before a course is written.
 *
 * Course creation without a template pauses here too, for the same reason:
 * content that takes minutes to produce is expensive to throw away, so it is
 * reviewed while it is still a plan. Everything about how that review looks is
 * borrowed rather than rebuilt, down to the module that drives it: the plan
 * reads as a checklist in the left thread, the decision card takes the
 * composer's slot at the bottom of that same column, and asking for a change
 * brings the composer back to type it in.
 *
 * What differs is how much can change. The template owns the structure, so
 * there is nothing to add, delete or reorder; the only adjustment on offer is
 * to plan the content again.
 *
 * @module     local_coursegen/local/courseai/template/plan_review
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getStrings} from 'core/str';
import {getDecisionOverlay} from 'local_coursegen/local/courseai/ui/decision-overlay';
import {renderHtml} from 'local_coursegen/local/courseai/ui/markdown';

const STRING_KEYS = [
    'courseai_template_review_summary',
    'courseai_template_plan_empty',
    'courseai_template_review_adjust',
    'courseai_btn_generate',
];

let labels = null;

/**
 * The review's localised strings, fetched once.
 *
 * @returns {Promise<Object>} Keyed by string id.
 */
const getLabels = async() => {
    if (!labels) {
        const values = await getStrings(
            STRING_KEYS.map((key) => ({key, component: 'local_coursegen'}))
        );
        labels = {};
        STRING_KEYS.forEach((key, index) => {
            labels[key] = values[index];
        });
    }
    return labels;
};

/**
 * One plan entry as Markdown, for the checklist detail slot.
 *
 * @param {Object} entry
 * @returns {string}
 */
export const planAsHtml = (entry) => {
    const blocks = [];
    if (entry.summary) {
        blocks.push(`<p>${entry.summary}</p>`);
    }
    // The mould's own markup, with the draft written into it. Reading the plan
    // is reading the activity: its headings are headings, its lists are lists,
    // its layout is the layout that will be delivered.
    (entry.parts || []).forEach((part) => {
        const html = (part.html || '').trim();
        if (!html) {
            return;
        }
        blocks.push(`<h5>${part.title || ''}</h5>`, html);
    });
    return blocks.join('\n');
};

/**
 * Turn one plan entry into the Mustache context its template expects.
 *
 * @param {Object} entry
 * @param {Object} texts
 * @returns {Object}
 */
const planContext = (entry, texts) => ({
    summary: entry.summary || '',
    hasparts: (entry.parts || []).length > 0,
    emptylabel: texts.courseai_template_plan_empty,
});

/**
 * Show one activity's plan under its own row in the structure.
 *
 * @param {Object} entry One entry of the plan.
 * @returns {Promise<void>}
 */
export const renderActivityPlan = async(entry) => {
    const row = document.querySelector(`[data-generation-cmid="${entry.cmid}"]`);
    if (!row) {
        return;
    }
    const texts = await getLabels();
    const {html, js} = await Templates.renderForPromise(
        'local_coursegen/template_plan_activity',
        planContext(entry, texts)
    );
    const existing = row.querySelector('[data-region="template-plan"]');
    if (existing) {
        Templates.replaceNode(existing, html, js);
        return;
    }
    Templates.appendNodeContents(row, html, js);
};

/**
 * Remove every plan block, for a run that starts over.
 */
export const clearPlans = () => {
    document.querySelectorAll('[data-region="template-plan"]').forEach((node) => node.remove());
};

/**
 * Open the review and resolve with what the professor answered.
 *
 * @param {Array} plan The whole plan, one entry per activity.
 * @returns {Promise<Object>} {action, targetIds, instruction}
 */
export const askForDecision = async(plan) => {
    await Promise.all((plan || []).map((entry) => renderActivityPlan(entry)));

    const texts = await getLabels();
    const overlay = getDecisionOverlay();
    const body = overlay.getBody();
    if (body) {
        const count = (plan || []).length;
        body.textContent = texts.courseai_template_review_summary.replace('{$a}', count);
    }
    overlay.show();

    return new Promise((resolve) => {
        const accept = document.getElementById('cgDecisionAccept');
        const adjust = document.getElementById('cgDecisionAdjust');
        const composer = document.getElementById('tplInputBar');
        const input = document.getElementById('tplPromptInput');
        const send = document.getElementById('tplModeGenerate');

        const answer = (decision) => {
            overlay.hide();
            resolve(decision);
        };

        accept.addEventListener('click', () => answer({
            action: 'accept',
            targetIds: [],
            instruction: '',
        }), {once: true});

        adjust.addEventListener('click', () => {
            // Asking for a change means writing it, so the composer comes back
            // for exactly that, the way free mode's does. Its own button sends
            // the change instead of starting a second run.
            overlay.hide();
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
                send.addEventListener('click', () => {
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
                    // No targets means the change is for every activity. Naming
                    // them one by one is the next step here, not a different
                    // mechanism: the service already accepts the list.
                    resolve({action: 'replan_activity', targetIds: [], instruction});
                }, {once: true});
            }
        }, {once: true});
    });
};

/**
 * Render a section's activities into its checklist detail slot.
 *
 * The whole section is rebuilt on every activity rather than appended to, so a
 * replanned activity replaces what it used to say instead of stacking a second
 * copy under the same heading.
 *
 * @param {HTMLElement} node
 * @param {Array} entries Every plan entry of that section.
 */
export const fillChecklistDetail = (node, entries) => {
    if (!node) {
        return;
    }
    const html = (entries || [])
        .map((entry) => `<h4>${entry.name || ''}</h4>${planAsHtml(entry)}`)
        .join('\n');
    node.innerHTML = renderHtml(html);
};
