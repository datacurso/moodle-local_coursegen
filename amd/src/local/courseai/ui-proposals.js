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
 * Proposals UI — renders AI-interpreted proposals after free-text feedback.
 *
 * Consumes the `review_needed` event fields: proposals, fallen_proposals,
 * clarification. The user picks one proposal (or types something else, or
 * answers a clarification), then sends the appropriate pendingAction.
 *
 * @module     local_coursegen/local/courseai/ui-proposals
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {localizeMessage} from './i18n';

/** ID of the container element rendered by courseai_page.mustache. */
const BLOCK_ID = 'planProposalsBlock';

/** Name attribute shared by all proposal radio inputs (single-choice group). */
const RADIO_NAME = 'courseai-proposal-choice';

/** Value used for the "Something else" radio option. */
const OTHER_VALUE = '__other__';

/**
 * Create the proposals UI controller.
 *
 * @param {Object} deps
 * @param {Object} deps.state                  - Shared mutable state (needs state.sessionid, state.streamingurl).
 * @param {Object} deps.texts                  - Pre-loaded lang strings from loadCourseaiStrings.
 * @param {Function} deps.formatTemplate       - Template formatter utility.
 * @param {Function} deps.sendPlanningFeedback - WS helper to send a pendingAction.
 * @param {Function} deps.openSSEStream        - Opens/re-opens the SSE stream.
 * @returns {{ renderProposals: Function, clear: Function }}
 */
export const createProposalsUi = (deps) => {
    const {state, texts, sendPlanningFeedback, openSSEStream} = deps;

    /**
     * Return the #planProposalsBlock element, or null if not in the DOM.
     *
     * @returns {HTMLElement|null}
     */
    const getBlock = () => document.getElementById(BLOCK_ID);

    /**
     * Empty the block and hide it.
     */
    const clear = () => {
        const block = getBlock();
        if (!block) {
            return;
        }
        block.innerHTML = '';
        block.style.display = 'none';
    };

    /**
     * Disable all interactive controls inside the block (submit-guard).
     *
     * @param {HTMLElement} block
     */
    const disableControls = (block) => {
        const inputs = block.querySelectorAll('input, textarea, button');
        inputs.forEach((el) => {
            el.disabled = true;
        });
    };

    /**
     * Re-enable all interactive controls inside the block (error recovery).
     *
     * @param {HTMLElement} block
     */
    const enableControls = (block) => {
        const inputs = block.querySelectorAll('input, textarea, button');
        inputs.forEach((el) => {
            el.disabled = false;
        });
    };

    /**
     * Send a pendingAction and re-open the planning stream.
     *
     * @param {HTMLElement} block        - Container (controls are disabled during send).
     * @param {Object}      pendingAction
     * @returns {Promise<void>}
     */
    const sendAction = async(block, pendingAction) => {
        if (!sendPlanningFeedback || !state.sessionid) {
            return;
        }
        disableControls(block);
        try {
            await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
            openSSEStream(state.streamingurl, 0, 'planning');
        } catch (e) {
            enableControls(block);
        }
    };

    /**
     * Build and return a proposal card element (label wrapping a radio input).
     *
     * @param {Object} proposal         - ProposedAction from the backend.
     * @param {string} localizedSummary - Already-resolved summary string.
     * @returns {HTMLElement}
     */
    const buildProposalCard = (proposal, localizedSummary) => {
        const label = document.createElement('label');
        label.className = 'plan-proposal-card';
        if (proposal.destructive) {
            label.classList.add('plan-proposal--destructive');
        }

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = RADIO_NAME;
        radio.value = proposal.proposal_id;
        radio.className = 'plan-proposal-radio';

        const textSpan = document.createElement('span');
        textSpan.className = 'plan-proposal-summary';
        textSpan.textContent = localizedSummary;

        label.appendChild(radio);
        label.appendChild(textSpan);

        if (proposal.destructive) {
            const badge = document.createElement('span');
            badge.className = 'plan-proposal-destructive-badge';
            badge.textContent = texts.courseai_proposals_destructive_badge || 'Deletes content';
            label.appendChild(badge);
        }

        return label;
    };

    /**
     * Build and return the "Something else" option with its hidden textarea.
     *
     * @returns {{ wrapper: HTMLElement, textarea: HTMLTextAreaElement }}
     */
    const buildOtherOption = () => {
        const wrapper = document.createElement('div');
        wrapper.className = 'plan-proposal-other-wrap';

        const label = document.createElement('label');
        label.className = 'plan-proposal-card plan-proposal-card--other';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = RADIO_NAME;
        radio.value = OTHER_VALUE;
        radio.className = 'plan-proposal-radio';

        const textSpan = document.createElement('span');
        textSpan.className = 'plan-proposal-summary';
        textSpan.textContent = texts.courseai_proposals_other_label || 'Something else';

        label.appendChild(radio);
        label.appendChild(textSpan);

        const textarea = document.createElement('textarea');
        textarea.className = 'plan-proposal-other-textarea';
        textarea.placeholder = texts.courseai_proposals_other_placeholder || 'Describe what you want instead…';
        textarea.rows = 3;
        textarea.style.display = 'none';

        radio.addEventListener('change', () => {
            textarea.style.display = radio.checked ? '' : 'none';
        });

        wrapper.appendChild(label);
        wrapper.appendChild(textarea);

        return {wrapper, textarea};
    };

    /**
     * Build and return the fallen (no-longer-possible) proposals list element.
     *
     * @param {Array}  fallenProposals        - fallen_proposals array from the event.
     * @param {Array}  localizedFallenItems   - Array of {summary, reason} already resolved.
     * @returns {HTMLElement}
     */
    const buildFallenList = (fallenProposals, localizedFallenItems) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'plan-proposals-fallen';

        const label = document.createElement('p');
        label.className = 'plan-proposals-fallen-label';
        label.textContent = texts.courseai_proposals_fallen_label || 'No longer possible';
        wrapper.appendChild(label);

        const list = document.createElement('ul');
        list.className = 'plan-proposals-fallen-list';

        fallenProposals.forEach((fp, i) => {
            const item = localizedFallenItems[i] || {};
            const li = document.createElement('li');
            li.className = 'plan-proposals-fallen-item';

            const summarySpan = document.createElement('span');
            summarySpan.className = 'plan-proposals-fallen-summary';
            summarySpan.textContent = item.summary || '';

            const reasonSpan = document.createElement('span');
            reasonSpan.className = 'plan-proposals-fallen-reason';
            reasonSpan.textContent = item.reason || '';

            li.appendChild(summarySpan);
            if (item.reason) {
                li.appendChild(document.createTextNode(' — '));
                li.appendChild(reasonSpan);
            }

            list.appendChild(li);
        });

        wrapper.appendChild(list);
        return wrapper;
    };

    /**
     * Render proposals, clarification, and fallen proposals into the block.
     * Hides the block and returns early when there is nothing to show.
     *
     * @param {Object} data - The review_needed event payload.
     * @param {Array}  data.proposals         - ProposedAction[]
     * @param {Array}  data.fallen_proposals  - { summary, reason }[]
     * @param {Object|null} data.clarification - LocalizedMessage | null
     * @returns {Promise<void>}
     */
    const renderProposals = async(data) => {
        const proposals = Array.isArray(data.proposals) ? data.proposals : [];
        const fallenProposals = Array.isArray(data.fallen_proposals) ? data.fallen_proposals : [];
        const clarification = data.clarification || null;

        if (!proposals.length && !fallenProposals.length && !clarification) {
            clear();
            return;
        }

        const block = getBlock();
        if (!block) {
            return;
        }

        // Resolve all localized strings in parallel before touching the DOM.
        const localizedProposals = await Promise.all(
            proposals.map((p) => localizeMessage(p.summary))
        );

        const localizedFallenItems = await Promise.all(
            fallenProposals.map(async(fp) => ({
                summary: await localizeMessage(fp.summary),
                reason: await localizeMessage(fp.reason),
            }))
        );

        const localizedClarification = clarification ? await localizeMessage(clarification) : null;

        // --- Build DOM ---
        block.innerHTML = '';

        if (texts.courseai_proposals_title) {
            const title = document.createElement('p');
            title.className = 'plan-proposals-title';
            title.textContent = texts.courseai_proposals_title;
            block.appendChild(title);
        }

        if (localizedClarification) {
            const clarBox = document.createElement('div');
            clarBox.className = 'plan-proposals-clarification';

            const clarLabel = document.createElement('p');
            clarLabel.className = 'plan-proposals-clarification-label';
            clarLabel.textContent = texts.courseai_proposals_clarification_label || 'I need a bit more detail';
            clarBox.appendChild(clarLabel);

            const clarText = document.createElement('p');
            clarText.className = 'plan-proposals-clarification-text';
            clarText.textContent = localizedClarification;
            clarBox.appendChild(clarText);

            block.appendChild(clarBox);
        }

        const radioGroup = document.createElement('div');
        radioGroup.className = 'plan-proposals-group';
        radioGroup.setAttribute('role', 'radiogroup');

        proposals.forEach((proposal, i) => {
            const card = buildProposalCard(proposal, localizedProposals[i]);
            radioGroup.appendChild(card);
        });

        const {wrapper: otherWrapper, textarea: otherTextarea} = buildOtherOption();
        radioGroup.appendChild(otherWrapper);
        block.appendChild(radioGroup);

        // --- Action buttons ---
        const btnRow = document.createElement('div');
        btnRow.className = 'plan-proposals-btn-row';

        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'btn-proposals-apply';
        applyBtn.textContent = texts.courseai_btn_execute_proposal || 'Apply selection';

        applyBtn.addEventListener('click', async() => {
            const selected = block.querySelector(`input[name="${RADIO_NAME}"]:checked`);
            if (!selected) {
                return;
            }

            if (selected.value === OTHER_VALUE) {
                const instruction = otherTextarea.value.trim();
                if (!instruction) {
                    otherTextarea.focus();
                    return;
                }
                const pendingAction = {action: 'feedback', instruction};
                await sendAction(block, pendingAction);
            } else {
                const pendingAction = {action: 'execute_proposal', target_ids: [selected.value]};
                await sendAction(block, pendingAction);
            }
        });

        const dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'btn-proposals-dismiss';
        dismissBtn.textContent = texts.courseai_btn_discard_proposals || 'Dismiss suggestions';

        dismissBtn.addEventListener('click', async() => {
            const pendingAction = {action: 'discard_proposals', target_ids: []};
            await sendAction(block, pendingAction);
        });

        btnRow.appendChild(applyBtn);
        btnRow.appendChild(dismissBtn);
        block.appendChild(btnRow);

        if (fallenProposals.length) {
            const fallenEl = buildFallenList(fallenProposals, localizedFallenItems);
            block.appendChild(fallenEl);
        }

        block.style.display = '';
    };

    return {renderProposals, clear};
};
