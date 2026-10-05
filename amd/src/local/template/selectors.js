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
 * Selectors for the template editor (course picker, configuration form,
 * sections review, "add from a template" menu and spaces).
 *
 * Every JS hook in the markup is a data-action (something to click), a
 * data-region (an element the scripts read or write) or a data-form attribute,
 * never a CSS class or an id. Hooks written for the spaces work and the naming
 * preview are namespaced as local_coursegen/template/<name>. The hooks that
 * were already in the markup before the spaces work keep their original
 * attribute: the data-for kinds of a row, the plain data-region names of the
 * wizard and of the instance rows, and data-action="save". Nothing outside
 * this file may hold a selector string.
 *
 * @module     local_coursegen/local/template/selectors
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default {
    actions: {
        save: '[data-action="save"]',
        instanceTrigger: '[data-instance-menu-trigger]',
        menuFromTemplate: '[data-action="local_coursegen/template/menu-from-template"]',
        menuBack: '[data-action="local_coursegen/template/menu-back"]',
        menuAddSpace: '[data-action="local_coursegen/template/menu-add-space"]',
        menuAny: '[data-action="local_coursegen/template/menu-from-template"], ' +
            '[data-action="local_coursegen/template/menu-back"], ' +
            '[data-action="local_coursegen/template/menu-add-space"]',
        pickTemplateSource: '[data-action="local_coursegen/template/pick-template-source"][data-source-cmid]',
        spaceEdit: '[data-action="local_coursegen/template/space-edit"]',
        spaceRemove: '[data-action="local_coursegen/template/space-remove"]',
        // Owned by Moodle core's activity chooser markup, not by this plugin.
        coreChooserOption: 'a[data-action="add-chooser-option"]',
    },
    regions: {
        wizard: '[data-region="template-wizard"]',
        config: '[data-region="config"]',
        structure: '[data-region="structure"]',
        configForm: '[data-region="config-form"]',
        coursePickerPanel: '[data-region="step-panel"][data-step="1"]',
        addInstance: '[data-region="add-instance"]',
        rowGap: '[data-region="row-gap"]',
        gapOrAddInstance: '[data-region="row-gap"], [data-region="add-instance"]',
        instanceRemove: '[data-region="instance-remove"]',
        instancePromptToggle: '[data-region="instance-prompt-toggle"]',
        instancePrompt: '[data-region="instance-prompt"]',
        instanceNameEditable: '[data-region="instance-name-editable"]',
        activityActionSelect: 'select[data-region="activity-action"]',
        sectionBehaviorSelect: 'select[data-region="section-behavior"]',
        activityInstruction: '[data-region="activity-instruction"]',
        activityInstructionRow: '[data-region="activity-instruction-row"]',
        templateTag: '[data-region="template-tag"]',
        instanceDropdown: '[data-region="local_coursegen/template/instance-dropdown"]',
        instanceDropdownMenu: '[data-region="local_coursegen/template/instance-dropdown-menu"]',
        activityIcon: '[data-region="local_coursegen/template/activity-icon"]',
        typeLabel: '[data-region="local_coursegen/template/type-label"]',
        spaceRow: '[data-region="local_coursegen/template/space-row"]',
        spaceBadge: '[data-region="local_coursegen/template/space-badge"]',
        spaceInstruction: '[data-region="local_coursegen/template/space-instruction"]',
        spaceTag: '[data-region="local_coursegen/template/space-tag"]',
        spaceRowInstruction: '[data-region="local_coursegen/template/space-row-instruction"]',
        spaceRequiredChoice: '[data-region="local_coursegen/template/space-required-choice"]:checked',
        spaceInstructionField: '[data-region="local_coursegen/template/space-instruction-field"]',
        templateName: '[data-region="local_coursegen/template/template-name"]',
        templateDescription: '[data-region="local_coursegen/template/template-description"]',
    },
    forms: {
        nameForm: '[data-form="local_coursegen/template/name-form"]',
    },
    rows: {
        section: '[data-for="section"]',
        activity: '[data-for="cmitem"]',
        instance: '[data-for="instancerow"]',
        instancePrompt: '[data-for="instanceprompt"]',
        // The three kinds of row a section holds, in the order they are read.
        anyRow: '[data-for="cmitem"], [data-for="instancerow"], ' +
            '[data-region="local_coursegen/template/space-row"]',
        sectionById: (sectionid) => `[data-for="section"][data-id="${sectionid}"]`,
        promptDrawerOf: (instanceid) => `[data-for="instanceprompt"][data-instance-id="${instanceid}"]`,
        promptFieldOf: (instanceid) => '[data-for="instanceprompt"]' +
            `[data-instance-id="${instanceid}"] [data-region="instance-prompt"]`,
    },
};
