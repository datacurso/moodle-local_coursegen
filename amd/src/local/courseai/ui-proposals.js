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

/** ID of the container element rendered by courseai_page.mustache. */
const BLOCK_ID = 'planProposalsBlock';

/**
 * Create the proposals UI controller.
 *
 * @param {Object} deps
 * @returns {{ renderProposals: Function, clear: Function }}
 */
export const createProposalsUi = (deps) => {
    const {texts, runPlanAction, emitLog} = deps;

    const log = (params) => { if (typeof emitLog === 'function') { emitLog(params); } };
    const getBlock = () => document.getElementById(BLOCK_ID);

    const clear = () => {
        const block = getBlock();
        if (!block) { return; }
        block.innerHTML = '';
        block.style.display = 'none';
    };

    const disableControls = (block) => {
        block.querySelectorAll('input, textarea, button').forEach((el) => { el.disabled = true; });
    };

    const enableControls = (block) => {
        block.querySelectorAll('input, textarea, button').forEach((el) => { el.disabled = false; });
    };

    const sendAction = async(block, pendingAction) => {
        disableControls(block);
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
                log({actor: 'user', kind: 'info',
                    message: (texts.courseai_log_proposal_applied || 'You applied: {$a}').replace('{$a}', truncated)});
                await sendAction(block, {action: 'feedback', instruction});
            } else {
                const selectedLabel = selected.closest('.plan-proposal-card');
                const summaryText = selectedLabel
                    ? (selectedLabel.querySelector('.plan-proposal-summary') || {}).textContent || ''
                    : '';
                const truncated = summaryText.length > 80 ? summaryText.slice(0, 80) + '…' : summaryText;
                log({actor: 'user', kind: 'info',
                    message: (texts.courseai_log_proposal_applied || 'You applied: {$a}').replace('{$a}', truncated)});
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
    };

    return {renderProposals, clear};
};
