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
 * Adjustment-history restore helper for the Course AI entrypoint.
 *
 * @module     local_coursegen/courseai/bootstrap/adjustment-history
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Determine whether a message is a human adjustment message.
 *
 * @param {Object} message
 * @param {number} index
 * @returns {boolean}
 */
const isAdjustmentMessage = (message, index) => {
    if (!message || index === 0) {
        return false;
    }

    const role = String(message?.role || '').toLowerCase();
    if (role === 'human' || role === 'user') {
        return Boolean(String(message.content || '').trim());
    }

    return false;
};

/**
 * Restore the adjustment history UI from snapshot messages and planning rounds.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.elements
 * @param {Function} params.buildChecklistRoundFromSections
 * @param {Function} params.renderInitialChecklist
 * @param {Function} params.createRoundChecklistElement
 * @param {Array} params.messages
 * @param {Array} params.planningRounds
 * @param {Array} params.fallbackSections
 * @returns {void}
 */
export const restoreAdjustmentHistory = ({
    state,
    elements,
    buildChecklistRoundFromSections,
    renderInitialChecklist,
    createRoundChecklistElement,
    messages,
    planningRounds = [],
    fallbackSections = [],
}) => {
    if (!elements.adjustmentHistory) {
        return;
    }

    const humanMessages = Array.isArray(messages)
        ? messages.filter((message, index) => isAdjustmentMessage(message, index))
        : [];

    const rounds = Array.isArray(planningRounds) ? planningRounds : [];
    const fallbackRound = buildChecklistRoundFromSections(fallbackSections);
    const roundsByNumber = rounds.reduce((map, roundData, index) => {
        const roundNumber = Number(roundData?.round ?? index + 1);
        if (!Number.isNaN(roundNumber) && roundNumber >= 0) {
            map[roundNumber] = roundData;
        }
        return map;
    }, {});

    renderInitialChecklist(roundsByNumber[1] || fallbackRound);

    if (!humanMessages.length) {
        elements.adjustmentHistory.classList.add('hidden');
        elements.adjustmentHistory.innerHTML = '';
        return;
    }

    elements.adjustmentHistory.innerHTML = '';

    humanMessages.forEach((message, idx) => {
        const round = idx + 1;
        const roundContainer = document.createElement('div');
        roundContainer.className = 'courseai-round';
        roundContainer.setAttribute('data-round', String(round));

        const msgEl = document.createElement('div');
        msgEl.className = 'courseai-chat-history';

        const bubble = document.createElement('div');
        bubble.className = 'courseai-chat-message courseai-chat-message--user';
        const text = document.createElement('p');
        text.textContent = message.content;
        bubble.appendChild(text);
        msgEl.appendChild(bubble);

        const responseSlot = document.createElement('div');
        responseSlot.className = 'courseai-round-response';
        responseSlot.setAttribute('data-round', String(round));

        roundContainer.appendChild(msgEl);
        roundContainer.appendChild(responseSlot);

        const roundChecklist = createRoundChecklistElement(roundsByNumber[round + 1] || fallbackRound);
        if (roundChecklist) {
            responseSlot.appendChild(roundChecklist);
        }

        elements.adjustmentHistory.appendChild(roundContainer);
    });

    elements.adjustmentHistory.classList.remove('hidden');
    state.generationRound = humanMessages.length;
};
