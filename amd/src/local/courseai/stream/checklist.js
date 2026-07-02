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
 * Checklist DOM helper for SSE stream round-based planning UI.
 *
 * @module     local_coursegen/local/courseai/stream/checklist
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return the checklist UL for the given round, creating the container if needed.
 *
 * @param {Object} elements
 * @param {number} currentRound
 * @param {Object} texts
 * @returns {HTMLElement|null}
 */
export const getOrCreateRoundChecklist = (elements, currentRound, texts) => {
    const existing = document.querySelector(`.courseai-checklist[data-round="${currentRound}"]`);
    if (existing) {
        return existing.querySelector('.courseai-checklist-list');
    }

    const container = document.createElement('div');
    container.className = 'courseai-checklist';
    container.setAttribute('data-round', currentRound);

    const list = document.createElement('ul');
    list.className = 'courseai-checklist-list';
    container.appendChild(list);

    const label = document.createElement('span');
    label.className = 'courseai-checklist-label';
    label.textContent = texts.courseai_checklist_label;
    container.insertBefore(label, list);

    const roundEl = elements.adjustmentHistory
        ? elements.adjustmentHistory.querySelector(`.courseai-round[data-round="${currentRound}"]`)
        : null;
    const responseSlot = roundEl
        ? roundEl.querySelector('.courseai-round-response')
        : null;

    if (responseSlot) {
        responseSlot.appendChild(container);
    } else if (elements.adjustmentHistory && elements.adjustmentHistory.parentNode) {
        elements.adjustmentHistory.parentNode.insertBefore(
            container,
            elements.adjustmentHistory.nextSibling
        );
    }

    return list;
};
