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
 * Reusable action-button factory for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/controls
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create a keyboard-accessible icon action button (span[role=button]).
 *
 * @param {Object}   options
 * @param {string}   options.variant    - BEM modifier (e.g. "ia", "delete").
 * @param {string}   [options.iconUrl]  - Image URL (mutually exclusive with iconSvg).
 * @param {string}   [options.iconSvg]  - Inline SVG string (takes precedence over iconUrl).
 * @param {string}   options.label      - Accessible label / tooltip.
 * @param {Function} options.onActivate - Called when the user clicks or presses Enter/Space.
 * @param {boolean}  [options.disabled] - Whether the button starts in a disabled state.
 * @returns {HTMLSpanElement}
 */
export const createActionControl = ({variant, iconUrl, iconSvg, label, onActivate, disabled}) => {
    const control = document.createElement('span');
    control.className = `dp-action-btn dp-action-btn--${variant}`;

    if (disabled) {
        control.classList.add('dp-action-btn--disabled');
    }

    control.setAttribute('role', 'button');
    control.setAttribute('tabindex', disabled ? '-1' : '0');
    control.setAttribute('aria-label', label);
    control.title = label;

    if (iconSvg) {
        control.innerHTML =
            `<span class="dp-action-icon dp-action-icon--${variant}" aria-hidden="true">${iconSvg}</span>`;
    } else {
        control.innerHTML =
            `<img src="${iconUrl}" class="dp-action-icon dp-action-icon--${variant}"` +
            ` alt="" aria-hidden="true" onerror="this.style.display='none'">`;
    }

    const activate = (event) => {
        if (control.classList.contains('dp-action-btn--disabled')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        onActivate();
    };

    control.addEventListener('click', activate);
    control.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            activate(event);
        }
    });

    return control;
};
