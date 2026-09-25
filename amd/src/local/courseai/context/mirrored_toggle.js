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
 * A toggle whose checked state also has to be mirrored onto its
 * compact-toolbar counterpart: the "with images" and "with subsections"
 * switches both work this way.
 *
 * @module     local_coursegen/local/courseai/context/mirrored_toggle
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {Object} params
 * @param {HTMLElement} params.toggle
 * @param {HTMLElement} params.wrap
 * @param {Function} params.bindToggleWrap
 * @param {HTMLElement} [params.compactToggle]
 * @param {HTMLElement} [params.compactWrap]
 * @param {Function} params.onChange Called with the new checked value.
 */
export const wireMirroredToggle = ({toggle, wrap, bindToggleWrap, compactToggle, compactWrap, onChange}) => {
    if (!toggle || !wrap) {
        return;
    }
    bindToggleWrap(wrap, toggle);
    toggle.addEventListener('change', () => {
        wrap.classList.toggle('on', toggle.checked);
        if (compactToggle) {
            compactToggle.checked = toggle.checked;
        }
        if (compactWrap) {
            compactWrap.classList.toggle('on', toggle.checked);
        }
        onChange(toggle.checked);
    });
};
