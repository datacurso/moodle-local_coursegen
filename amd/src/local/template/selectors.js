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
 * Selectors for the template editor (course picker, name form and sections review).
 *
 * Every JS hook in the markup is a data-action (something to click), a
 * data-region (an element the scripts read or write) or a data-form attribute,
 * never a CSS class or an id. Nothing outside this file may hold a selector string.
 *
 * @module     local_coursegen/local/template/selectors
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default {
    actions: {
        save: '[data-action="save"]',
    },
    regions: {
        wizard: '[data-region="template-wizard"]',
        config: '[data-region="config"]',
        structure: '[data-region="structure"]',
        sectionsConfig: '[data-region="sections-config"]',
        coursePickerPanel: '[data-region="step-panel"][data-step="1"]',
        selectedBanner: '[data-region="selected-banner"]',
        selectedName: '[data-region="selected-name"]',
        selectedShort: '[data-region="selected-short"]',
        selectedLink: '[data-region="selected-link"]',
        activityActionSelect: 'select[data-region="activity-action"]',
        activityInstructionField: 'textarea[data-region="activity-instruction"]',
        activitySelect: '[data-region="activity-select"]',
        activitySelectChecked: '[data-region="activity-select"]:checked',
        selectAll: '[data-region="select-all"]',
        sectionSelectAll: '[data-region="section-select-all"]',
        bulkAction: 'select[data-region="bulk-action"]',
        templateName: '[data-region="local_coursegen/template/template-name"]',
        templateDescription: '[data-region="local_coursegen/template/template-description"]',
    },
    forms: {
        nameForm: '[data-form="local_coursegen/template/name-form"]',
    },
    rows: {
        section: '[data-for="section"]',
        activity: '[data-for="cmitem"]',
        instructionOf: (cmid) => `[data-for="instruction"][data-id="${cmid}"]`,
    },
};
