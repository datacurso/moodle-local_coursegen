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
 * Selectors for the template-mode guided form (structure view).
 *
 * All JS hooks in the server-rendered structure markup are data-action
 * (behaviour) or data-region (static panel elements) attributes — never CSS
 * classes — so re-renders never desync from the JS that wires them (see
 * local_coursegen/template_structure.mustache).
 *
 * @module     local_coursegen/local/courseai/template/selectors
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default {
    actions: {
        toggleSection: '[data-action="local_coursegen/template/toggle-section"]',
        pickSpaceFile: '[data-action="local_coursegen/template/pick-space-file"]',
        removeSpaceFile: '[data-action="local_coursegen/template/remove-space-file"]',
    },
    classes: {
        generating: 'cg-generating',
        busy: 'is-busy',
    },
    regions: {
        generationNotices: '[data-region="local_coursegen/template/generation-notices"]',
        spaceButtonLabel: '[data-region="local_coursegen/template/space-button-label"]',
    },
};
