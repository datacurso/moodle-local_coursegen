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
 * Stepper UI — toggles classes on server-rendered step labels.
 *
 * @module     local_coursegen/local/template/stepper
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Update the stepper to highlight the current step.
 * The HTML is already rendered in the mustache template.
 * This only toggles font-weight-bold / text-muted classes.
 *
 * @param {HTMLElement} container The stepper container.
 * @param {Array} steps Step descriptors (unused, kept for API compat).
 * @param {number} currentStep The active step number.
 */
export const renderStepper = (container, steps, currentStep) => {
    container.querySelectorAll('[data-step-label]').forEach(span => {
        const stepNum = parseInt(span.dataset.stepLabel);
        if (stepNum === currentStep) {
            span.classList.add('font-weight-bold');
            span.classList.remove('text-muted');
        } else if (stepNum < currentStep) {
            span.classList.remove('font-weight-bold', 'text-muted');
        } else {
            span.classList.remove('font-weight-bold');
            span.classList.add('text-muted');
        }
    });
};
