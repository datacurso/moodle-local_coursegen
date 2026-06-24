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
 * @module     local_coursegen/local/courseai/ui-proposals
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {localizeMessage} from './i18n';
import {buildProposalCard, buildOtherOption, buildFallenList, RADIO_NAME} from './proposals/dom';
import {getDecisionOverlay} from './ui/decision-overlay';

/** ID of the proposals card injected into the LEFT decision-log feed. */
const BLOCK_ID = 'cgFeedProposals';

/**
 * Create the proposals UI controller.
 *
 * @param {Object}   deps
 * @param {Object}   deps.texts
 * @param {Function} deps.runPlanAction
 * @param {Function} [deps.emitLog]
 * @param {Object}   [deps.detailedUi] - Detailed renderer; used to skeleton the
 *                                       proposal's target the moment apply fires.
 * @returns {{ renderProposals: Function, clear: Function }}
 */
export const createProposalsUi = (deps) => {
    const {texts, runPlanAction, emitLog, detailedUi} = deps;

    const log = (params) => { if (typeof emitLog === 'function') { emitLog(params); } };

    const AFFECTED_CLASS = 'cg-affected';
    const AFFECTED_DESTRUCTIVE_CLASS = 'cg-affected--destructive';

    /** Remove the "this will be affected" highlight from every center element. */
    const clearAffectedHighlights = () => {
        document.querySelectorAll('.' + AFFECTED_CLASS).forEach((el) => {
            el.classList.remove(AFFECTED_CLASS, AFFECTED_DESTRUCTIVE_CLASS);
        });
    };

    /**
     * Highlight the center elements a proposal touches (sections/activities).
     *
     * @param {Array}   targetIds   - UUIDs of affected sections/activities.
     * @param {boolean} destructive - whether the proposal deletes content.
     * @returns {void}
     */
    const highlightAffected = (targetIds, destructive) => {
        let firstEl = null;
        (targetIds || []).forEach((id) => {
            // Scope to the CENTER preview only — never the left checklist
            // (.courseai-checklist-item also carries data-section-id and would
            // otherwise get an ugly outline).
            document.querySelectorAll(
                '.course-section[data-section-id="' + id + '"], '
                + '.activity[data-activity-id="' + id + '"]'
            ).forEach((el) => {
                el.classList.add(AFFECTED_CLASS);
                if (destructive) {
                    el.classList.add(AFFECTED_DESTRUCTIVE_CLASS);
                }
                if (!firstEl) {
                    firstEl = el;
                }
            });
        });
        // Bring the affected element into view so the user actually sees what the
        // selected proposal will change in the center preview.
        if (firstEl) {
            firstEl.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'smooth'});
        }
    };

    /**
     * Show or hide the decision card's generic Accept/Adjust row and subtitle.
     *
     * When proposals occupy the card body they bring their own Apply/Dismiss
     * actions, so the generic Accept/Adjust row must NOT also show — there must be
     * exactly one set of actions at a time. With no proposals (plain review) the
     * generic row is the only one and stays visible.
     *
     * @param {boolean} visible - true to show the generic decision row/subtitle.
     * @returns {void}
     */
    const toggleDecisionActions = (visible) => {
        const actions = document.querySelector('.cg-decision-overlay .cg-decision-actions');
        if (actions) { actions.style.display = visible ? '' : 'none'; }
        const subtitle = document.querySelector('.cg-decision-overlay .cg-decision-subtitle');
        if (subtitle) { subtitle.style.display = visible ? '' : 'none'; }
        // With proposals present, the proposals block carries its own heading, so the
        // generic "Review your course plan" title is redundant — hide it to avoid a
        // second stacked header.
        const title = document.querySelector('.cg-decision-overlay .cg-decision-title');
        if (title) { title.style.display = visible ? '' : 'none'; }
    };

    const clear = () => {
        clearAffectedHighlights();
        const block = document.getElementById(BLOCK_ID);
        if (block) { block.remove(); }
        // No proposals card → the generic Accept/Adjust row is the only decision UI.
        toggleDecisionActions(true);
    };

    // WU4: When the decision overlay is present and visible, inject proposals into
    // its body slot so they appear inside the centered decision card rather than
    // appended to the log feed. Fall back to the feed when the overlay is absent.
    const getBlock = () => {
        clear();
        const overlay = getDecisionOverlay(texts);
        const overlayBody = overlay.getBody();
        if (overlayBody) {
            // Overlay available — render proposals inside the decision card body.
            // The proposals bring their own Apply/Dismiss, so suppress the generic
            // Accept/Adjust row (and the subtitle that points at it): exactly one
            // set of actions shows at a time.
            toggleDecisionActions(false);
            const block = document.createElement('div');
            block.id = BLOCK_ID;
            block.className = 'cg-feed-proposals';
            overlayBody.innerHTML = '';
            overlayBody.appendChild(block);
            return block;
        }
        const feed = document.getElementById('cgLogAfter') || document.getElementById('cgLog');
        if (!feed) { return null; }
        const block = document.createElement('div');
        block.id = BLOCK_ID;
        block.className = 'cg-feed-proposals';
        feed.appendChild(block);
        return block;
    };

    const disableControls = (block) => {
        block.querySelectorAll('input, textarea, button').forEach((el) => { el.disabled = true; });
    };

    const enableControls = (block) => {
        block.querySelectorAll('input, textarea, button').forEach((el) => { el.disabled = false; });
    };

    /**
     * Send a proposal plan action and hide the overlay.
     *
     * @param {HTMLElement} block       - The proposals card DOM element.
     * @param {Object}      pendingAction - The plan action to send.
     * @returns {Promise<void>}
     */
    const sendAction = async(block, pendingAction) => {
        clearAffectedHighlights();
        disableControls(block);
        // WU4: hide the overlay as soon as an action is dispatched.
        getDecisionOverlay().hide();
        try {
            await runPlanAction(pendingAction);
        } catch (e) {
            enableControls(block);
        }
    };

    const renderProposals = async(data) => {
        const proposals = Array.isArray(data.proposals) ? data.proposals : [];
        const fallenProposals = Array.isArray(data.fallen_proposals) ? data.fallen_proposals : [];
        const clarification = data.clarification || null;

        if (!proposals.length && !fallenProposals.length && !clarification) {
            clear();
            return;
        }

        const block = getBlock();
        if (!block) { return; }

        const localizedProposals = await Promise.all(proposals.map((p) => localizeMessage(p.summary)));
        const localizedFallenItems = await Promise.all(
            fallenProposals.map(async(fp) => ({
                summary: await localizeMessage(fp.summary),
                reason: await localizeMessage(fp.reason),
            }))
        );
        const localizedClarification = clarification ? await localizeMessage(clarification) : null;

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
            radioGroup.appendChild(buildProposalCard(proposal, localizedProposals[i], texts));
        });
        const {wrapper: otherWrapper, textarea: otherTextarea} = buildOtherOption(texts);
        radioGroup.appendChild(otherWrapper);
        block.appendChild(radioGroup);

        // Single source of truth for selection side-effects: the "Something else"
        // textarea is visible ONLY while that option is selected, and the center
        // preview highlights exactly what the selected proposal will affect.
        const onSelectionChange = () => {
            const checked = block.querySelector('input[name="' + RADIO_NAME + '"]:checked');
            const isOther = Boolean(checked) && checked.value === '__other__';
            otherTextarea.style.display = isOther ? '' : 'none';

            clearAffectedHighlights();
            if (checked && !isOther) {
                let targetIds = [];
                try {
                    targetIds = JSON.parse(checked.dataset.targetIds || '[]');
                } catch (e) {
                    targetIds = [];
                }
                const card = checked.closest('.plan-proposal-card');
                const destructive = Boolean(card) && card.classList.contains('plan-proposal--destructive');
                highlightAffected(targetIds, destructive);
            }
        };
        radioGroup.querySelectorAll('input[name="' + RADIO_NAME + '"]').forEach((radio) => {
            radio.addEventListener('change', onSelectionChange);
        });

        const btnRow = document.createElement('div');
        btnRow.className = 'plan-proposals-btn-row';

        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'btn-proposals-apply';
        applyBtn.textContent = texts.courseai_btn_execute_proposal || 'Apply selection';

        applyBtn.addEventListener('click', async() => {
            const selected = block.querySelector(`input[name="${RADIO_NAME}"]:checked`);
            if (!selected) { return; }

            if (selected.value === '__other__') {
                const instruction = otherTextarea.value.trim();
                if (!instruction) { otherTextarea.focus(); return; }
                const truncated = instruction.length > 80 ? instruction.slice(0, 80) + '…' : instruction;
                const appliedLabel = texts.courseai_log_proposal_applied || 'You applied';
                log({actor: 'user', kind: 'info', message: appliedLabel + ': ' + truncated});
                await sendAction(block, {action: 'feedback', instruction});
            } else {
                const selectedLabel = selected.closest('.plan-proposal-card');
                const summaryText = selectedLabel
                    ? (selectedLabel.querySelector('.plan-proposal-summary') || {}).textContent || ''
                    : '';
                const truncated = summaryText.length > 80 ? summaryText.slice(0, 80) + '…' : summaryText;
                const appliedLabel = texts.courseai_log_proposal_applied || 'You applied';
                log({actor: 'user', kind: 'info', message: truncated ? appliedLabel + ': ' + truncated : appliedLabel});
                // Client-driven skeleton: put the loading shimmer on EXACTLY the
                // element this proposal affects right now, before the keepPlan
                // re-stream (which suppresses structural skeletons) reopens.
                if (detailedUi && typeof detailedUi.markProposalTargetPending === 'function') {
                    let intent = null;
                    try {
                        intent = JSON.parse(selected.dataset.intent || 'null');
                    } catch (e) {
                        intent = null;
                    }
                    if (intent) {
                        detailedUi.markProposalTargetPending(intent);
                    }
                }
                await sendAction(block, {action: 'execute_proposal', target_ids: [selected.value]});
            }
        });

        const dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'btn-proposals-dismiss';
        dismissBtn.textContent = texts.courseai_btn_discard_proposals || 'Dismiss suggestions';
        dismissBtn.addEventListener('click', async() => {
            log({actor: 'user', kind: 'neutral',
                message: texts.courseai_log_proposals_dismissed || 'You dismissed suggestions'});
            await sendAction(block, {action: 'discard_proposals', target_ids: []});
        });

        btnRow.appendChild(applyBtn);
        btnRow.appendChild(dismissBtn);
        block.appendChild(btnRow);

        if (fallenProposals.length) {
            block.appendChild(buildFallenList(fallenProposals, localizedFallenItems, texts));
        }

        block.style.display = '';
        window.requestAnimationFrame(() => {
            block.scrollIntoView({block: 'nearest', inline: 'nearest'});
        });
    };

    return {renderProposals, clear};
};
