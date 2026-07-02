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
 * Factory for the createCourseFromSession stream callback.
 *
 * @module     local_coursegen/courseai/bootstrap/create-course-callback
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build the async createCourseFromSession callback passed to the stream manager.
 *
 * @param {Object} params
 * @param {Object} params.elements
 * @param {Object} params.stepsUi
 * @param {Object} params.texts
 * @param {Function} params.getActions - Returns the current actions object (late-bound)
 * @returns {Function} async createCourseFromSession callback
 */
export const makeCreateCourseCallback = ({elements, stepsUi, texts, getActions}) => {
    /**
     * Callback invoked by the stream manager when course generation is complete.
     *
     * @returns {Promise<void>}
     */
    return async() => {
        const actions = getActions();
        if (actions) {
            // Advance progress to the review phase so it doesn't appear stuck.
            stepsUi.setProgress(92);
            if (elements.pcStep) {
                elements.pcStep.textContent = texts.courseai_review_step_label;
            }
            if (elements.pcTitle) {
                elements.pcTitle.textContent = texts.courseai_review_title;
            }
            if (elements.pcSubtitle) {
                elements.pcSubtitle.textContent = texts.courseai_review_subtitle;
            }
            // Swap the checkmark icon for an edit icon to signal user action needed.
            const planningSpinner = document.getElementById('planningSpinner');
            const planningCheckIcon = document.getElementById('planningCheckIcon');
            const pcIconWrap = document.getElementById('pcIconWrap');
            if (planningSpinner) {
                planningSpinner.style.display = 'none';
            }
            if (planningCheckIcon) {
                planningCheckIcon.style.display = 'none';
            }
            if (pcIconWrap) {
                const existingEdit = document.getElementById('planningEditIcon');
                if (!existingEdit) {
                    const editSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    editSvg.setAttribute('id', 'planningEditIcon');
                    editSvg.setAttribute('width', '20');
                    editSvg.setAttribute('height', '20');
                    editSvg.setAttribute('viewBox', '0 0 24 24');
                    editSvg.setAttribute('fill', 'none');
                    editSvg.setAttribute('stroke', 'currentColor');
                    editSvg.setAttribute('stroke-width', '2');
                    editSvg.setAttribute('stroke-linecap', 'round');
                    editSvg.setAttribute('stroke-linejoin', 'round');
                    editSvg.style.color = '#fff';
                    const path1 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    path1.setAttribute('d', 'M12 20h9');
                    const path2 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    path2.setAttribute('d', 'M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z');
                    editSvg.appendChild(path1);
                    editSvg.appendChild(path2);
                    pcIconWrap.appendChild(editSvg);
                } else {
                    existingEdit.style.display = '';
                }
            }
            // Show the course review panel before creating.
            const overrides = await actions.showCourseReviewPanel();
            if (overrides === null) {
                return;
            }
            await actions.createCourseFromSession(overrides);
        }
    };
};
