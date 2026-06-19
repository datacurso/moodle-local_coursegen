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
 * Guideline chip refresh helper for the Course AI context section.
 *
 * @module     local_coursegen/local/courseai/context/chip
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Refresh both main and compact guideline chip elements from current state.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Function} params.refreshChipsRow
 * @param {Function} params.refreshCompactChipsRow
 * @returns {void}
 */
export const refreshGuidelineChip = ({state, refreshChipsRow, refreshCompactChipsRow}) => {
    const chipGuideline = document.getElementById('chipGuideline');
    const chipGuidelineName = document.getElementById('chipGuidelineName');
    const guidelineBadge = document.getElementById('guidelineBadge');
    const compactChipGuideline = document.getElementById('compactChipGuideline');
    const compactChipGuidelineName = document.getElementById('compactChipGuidelineName');
    const compactGuidelineBadge = document.getElementById('compactGuidelineBadge');

    if (state.selectedGuidelineId) {
        const guideline = state.guidelines.find((g) => g.id === state.selectedGuidelineId);
        if (guideline) {
            if (chipGuideline && chipGuidelineName) {
                chipGuidelineName.textContent = guideline.name;
                chipGuideline.classList.remove('hidden');
                if (guidelineBadge) {
                    guidelineBadge.textContent = '1';
                    guidelineBadge.classList.remove('hidden');
                }
            }
            if (compactChipGuideline && compactChipGuidelineName) {
                compactChipGuidelineName.textContent = guideline.name;
                compactChipGuideline.classList.remove('hidden');
                if (compactGuidelineBadge) {
                    compactGuidelineBadge.textContent = '1';
                    compactGuidelineBadge.classList.remove('hidden');
                }
            }
        }
    } else {
        if (chipGuideline) {
            chipGuideline.classList.add('hidden');
            if (guidelineBadge) {
                guidelineBadge.classList.add('hidden');
            }
        }
        if (compactChipGuideline) {
            compactChipGuideline.classList.add('hidden');
            if (compactGuidelineBadge) {
                compactGuidelineBadge.classList.add('hidden');
            }
        }
    }
    refreshChipsRow();
    refreshCompactChipsRow();
};
