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
 * DOM-builder helpers for the proposals panel.
 *
 * @module     local_coursegen/local/courseai/proposals/dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Name attribute shared by all proposal radio inputs (single-choice group). */
export const RADIO_NAME = 'courseai-proposal-choice';

/** Value used for the "Something else" radio option. */
export const OTHER_VALUE = '__other__';

/**
 * Resolve a section's display name from the center preview by its UUID.
 *
 * @param {string} id - section UUID (data-section-id in the center)
 * @returns {string} the section name, or '' if not found
 */
const sectionNameById = (id) => {
    if (!id) {
        return '';
    }
    const el = document.querySelector('.course-section[data-section-id="' + id + '"] .sectionname');
    return el ? el.textContent.trim() : '';
};

/**
 * Describe what a proposal will DO, concretely, instead of echoing the raw user
 * instruction. For "add activity" the backend only captures the action + position +
 * raw text, so enrich the localized summary with the TARGET section's name (resolved
 * from the center) so the user sees where the change lands.
 *
 * @param {Object} proposal        - ProposedAction from the backend.
 * @param {string} localizedSummary - Already-resolved summary string.
 * @returns {string}
 */
const describeProposal = (proposal, localizedSummary) => {
    const intent = proposal.intent || {};
    if (intent.action === 'add_activity' && intent.parent_section_id) {
        const name = sectionNameById(intent.parent_section_id);
        if (name) {
            return localizedSummary + ' in "' + name + '"';
        }
    }
    return localizedSummary;
};

/**
 * Build and return a proposal card element (label wrapping a radio input).
 *
 * @param {Object} proposal         - ProposedAction from the backend.
 * @param {string} localizedSummary - Already-resolved summary string.
 * @param {Object} texts
 * @returns {HTMLElement}
 */
export const buildProposalCard = (proposal, localizedSummary, texts) => {
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
    // UUIDs of the sections/activities this proposal touches, so selecting it can
    // highlight exactly those elements in the center preview. The backend sends
    // target_ids for edit/delete/reorder, and parent_section_id for add_* (where the
    // new item lands) — include both so every proposal highlights its target.
    const intent = proposal.intent || {};
    const targetIds = [];
    if (Array.isArray(intent.target_ids)) {
        targetIds.push(...intent.target_ids);
    }
    if (intent.parent_section_id) {
        targetIds.push(intent.parent_section_id);
    }
    radio.dataset.targetIds = JSON.stringify(targetIds);

    const textSpan = document.createElement('span');
    textSpan.className = 'plan-proposal-summary';
    textSpan.textContent = describeProposal(proposal, localizedSummary);

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
 * @param {Object} texts
 * @returns {{ wrapper: HTMLElement, textarea: HTMLTextAreaElement }}
 */
export const buildOtherOption = (texts) => {
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
 * @param {Object} texts
 * @returns {HTMLElement}
 */
export const buildFallenList = (fallenProposals, localizedFallenItems, texts) => {
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
