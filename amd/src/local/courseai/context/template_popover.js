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
 * Wires the template column's picker line to the popover list it opens:
 * the trigger button, the chevron, the × that clears a choice, and the
 * document-level listeners that close it again (outside click, Escape).
 *
 * "From a template" is chosen on the page's first screen (start_path.js);
 * inside that path, the column's one-line picker opens this list right
 * below itself, before and after a template is chosen; its × clears the
 * choice without opening the list.
 *
 * @module     local_coursegen/local/courseai/context/template_popover
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createTemplateHandlers} from 'local_coursegen/local/courseai/context/template';

/**
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.texts
 * @param {Function} params.closeGuidelinePopover Closed whenever this one opens, so the
 *                   two panels never overlap around the same anchor.
 * @returns {{
 *   selectTemplate: Function,
 *   detachTemplate: Function,
 *   setTemplateLayout: Function,
 *   openTemplatePopover: Function,
 *   closeTemplatePopovers: Function
 * }}
 */
export const wireTemplatePopover = ({state, texts, closeGuidelinePopover}) => {
    const {
        renderTemplateLists, selectTemplate, detachTemplate, setTemplateLayout, closeTemplatePopovers,
        isLocked: isTemplateLocked, setPickerOpen, moveActive, pickActive,
    } = createTemplateHandlers({state, texts});

    const templatePopovers = [
        {panel: 'templatesPopoverTpl', search: 'templateSearchTpl', triggers: ['tplPicker']},
    ];

    const openTemplatePopover = (panelId, triggerEl = null) => {
        const spec = templatePopovers.find((p) => p.panel === panelId);
        const panel = document.getElementById(panelId);
        if (!spec || !panel || isTemplateLocked()) {
            return;
        }
        closeTemplatePopovers();
        closeGuidelinePopover();
        panel.classList.add('open');
        spec.triggers.forEach((id) => {
            let expanded = 'false';
            if (id === triggerEl?.id) {
                expanded = 'true';
            }
            document.getElementById(id)?.setAttribute('aria-expanded', expanded);
        });
        setPickerOpen(true);
        renderTemplateLists();
    };

    /**
     * Wire one template popover trigger's click handler.
     *
     * @param {Object} spec
     * @param {HTMLElement} panel
     * @param {string} id
     */
    const wireTemplatePopoverTrigger = (spec, panel, id) => {
        const trigger = document.getElementById(id);
        if (!trigger) {
            return;
        }
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (panel.classList.contains('open')) {
                closeTemplatePopovers();
            } else {
                openTemplatePopover(spec.panel, trigger);
            }
        });
    };

    /**
     * Wire one template popover spec: its triggers, its search box, and the
     * panel's own click guard.
     *
     * @param {Object} spec
     */
    const wireTemplatePopoverSpec = (spec) => {
        const panel = document.getElementById(spec.panel);
        if (!panel) {
            return;
        }
        spec.triggers.forEach((id) => wireTemplatePopoverTrigger(spec, panel, id));
        document.getElementById(spec.search)?.addEventListener('input', (e) => {
            state.templateSearchQuery = e.target.value;
            renderTemplateLists();
        });
        document.getElementById(spec.search)?.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveActive(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveActive(-1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                pickActive();
            }
        });
        // Clicks inside the panel must not count as "outside".
        panel.addEventListener('click', (e) => e.stopPropagation());
    };

    templatePopovers.forEach(wireTemplatePopoverSpec);

    // Clicking into the search line itself (focusing it, not typing) must
    // not count as "outside" either: only the picker's own trigger, clear
    // and chevron handlers below decide what a click there does.
    document.getElementById('tplPickerShell')?.addEventListener('click', (e) => e.stopPropagation());

    // The chevron closes the list while it is open; the button state has no
    // use for it (pointer-events is off there), so one listener covers both.
    document.getElementById('tplPickerChevron')?.addEventListener('click', (e) => {
        e.stopPropagation();
        closeTemplatePopovers();
    });

    document.addEventListener('click', () => closeTemplatePopovers());
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeTemplatePopovers();
        }
    });

    // The × on the picker line: clears the choice, the list stays closed.
    document.getElementById('tplPickerClear')?.addEventListener('click', (e) => {
        e.stopPropagation();
        detachTemplate();
    });

    return {selectTemplate, detachTemplate, setTemplateLayout, openTemplatePopover, closeTemplatePopovers};
};
