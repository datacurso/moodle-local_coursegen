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
 * Activity-type chooser modal (#tplActivityChooserModal) for the template-mode
 * guided form. Limited to the template's admin-allowed activity types
 * (allowedactivities from get_template_structure). The grid markup comes from
 * local_coursegen/template_activity_chooser (server rendered); picking a type
 * no longer inserts it immediately — it reveals the prompt panel below the
 * grid (selected-activity chip, generate-images radios, optional file upload,
 * prompt textarea), and only the confirm button hands the pick back through
 * wireChooserModal's onPick, now carrying {prompt, generateimages,
 * draftitemid, filename} extras.
 *
 * @module     local_coursegen/local/courseai/template/chooser
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import jQuery from 'jquery';
import Notification from 'core/notification';
import YUI from 'core/yui';
import Selectors from './selectors';
import {initFilepicker} from '../../../repository/courseai';

const MODAL_SELECTOR = '#tplActivityChooserModal';
const GRID_ID = 'tplChooserGrid';
const SEARCH_ID = 'tplChooserSearch';

// The section/position the chooser is currently adding into. Set by
// openActivityChooser, consumed (and cleared) by the confirm button handler.
// In edit mode it carries {sectionId, activityIndex} of the row being edited.
let pendingTarget = null;

// 'add' (default) or 'edit' — decides which callback the confirm button fires
// and which of the two confirm-label spans shows. Reset on every modal close.
let mode = 'add';

// The modname picked in the grid (chip shown in the prompt panel), or null
// while nothing is selected. Clicking another grid card just replaces it.
let pendingSelection = null;

// The file uploaded through the panel's filepicker button, if any.
let upload = {draftitemid: 0, filename: ''};

// modname => {displayname, purpose, iconhtml} for the currently rendered
// catalog — the selected-activity chip is built from here.
let catalog = {};

/**
 * Render the chooser grid for the given allowed-activity catalog. Content is
 * static per loaded template, so this only needs to run again when a NEW
 * template is selected — not on every open.
 *
 * @param {Array} allowedActivities - [{modname, displayname, purpose, iconhtml}]
 * @returns {Promise<void>}
 */
export const renderChooserGrid = async(allowedActivities) => {
    const grid = document.getElementById(GRID_ID);
    if (!grid) {
        return;
    }
    catalog = {};
    (allowedActivities || []).forEach((activity) => {
        catalog[activity.modname] = {
            displayname: activity.displayname,
            purpose: activity.purpose,
            iconhtml: activity.iconhtml,
        };
    });
    const context = {
        hasactivities: !!(allowedActivities && allowedActivities.length),
        activities: (allowedActivities || []).map((activity) => ({
            modname: activity.modname,
            displayname: activity.displayname,
            purpose: activity.purpose,
            iconhtml: activity.iconhtml,
            searchkey: (activity.displayname || '').toLowerCase(),
        })),
    };
    const {html, js} = await Templates.renderForPromise('local_coursegen/template_activity_chooser', context);
    Templates.replaceNodeContents(grid, html, js);
};

/**
 * Open the chooser modal targeting a given section/position.
 *
 * @param {number} sectionId
 * @param {number|null} position - 0-based insert index, or null to append.
 */
export const openActivityChooser = (sectionId, position) => {
    setMode('add');
    pendingTarget = {sectionId, position};
    const search = document.getElementById(SEARCH_ID);
    if (search) {
        search.value = '';
        filterChooserGrid('');
    }
    jQuery(MODAL_SELECTOR).modal('show');
};

/**
 * Switch the chooser between add and edit mode: remembers the mode and flips
 * the confirm button's hidden-toggled label spans (add ↔ save changes).
 *
 * @param {string} newMode - 'add' | 'edit'
 */
const setMode = (newMode) => {
    mode = newMode;
    const addLabel = document.querySelector(Selectors.regions.chooserConfirmAdd);
    const saveLabel = document.querySelector(Selectors.regions.chooserConfirmSave);
    if (addLabel) {
        addLabel.hidden = mode === 'edit';
    }
    if (saveLabel) {
        saveLabel.hidden = mode !== 'edit';
    }
};

/**
 * Prefill the prompt panel from an existing row's stored chooser extras so an
 * edit starts from what the professor originally typed: selected type chip,
 * prompt text, generate-images radios and the uploaded-file chip.
 *
 * @param {Object} current - {modname, prompt, generateimages, draftitemid, filename}
 */
const seedPanel = (current) => {
    selectActivity(current.modname);

    const promptEl = document.querySelector(Selectors.regions.chooserPrompt);
    if (promptEl) {
        promptEl.value = current.prompt || '';
        promptEl.style.height = 'auto';
        promptEl.style.height = promptEl.scrollHeight + 'px';
    }
    document.querySelectorAll(Selectors.regions.chooserGenerateImages).forEach((radio) => {
        radio.checked = Number(radio.value) === (current.generateimages ? 1 : 0);
    });
    upload = {
        draftitemid: current.draftitemid || 0,
        filename: current.filename || '',
    };
    refreshSelectedFileChip();
};

/**
 * Open the chooser modal in EDIT mode over an existing professor-added row:
 * panel prefilled from the row's stored extras, type changeable from the grid,
 * confirm relabelled "Save changes" and routed to the onEdit callback.
 *
 * @param {number} sectionId
 * @param {number} activityIndex - Current render index of the row being edited.
 * @param {Object} current - {modname, prompt, generateimages, draftitemid, filename}
 */
export const openActivityChooserForEdit = (sectionId, activityIndex, current) => {
    setMode('edit');
    pendingTarget = {sectionId, activityIndex};
    const search = document.getElementById(SEARCH_ID);
    if (search) {
        search.value = '';
        filterChooserGrid('');
    }
    seedPanel(current || {});
    jQuery(MODAL_SELECTOR).modal('show');
};

/**
 * Filter the chooser grid by the search query (client-side, no re-render).
 *
 * @param {string} query
 */
const filterChooserGrid = (query) => {
    const q = query.trim().toLowerCase();
    document.querySelectorAll(`#${GRID_ID} .option`).forEach((card) => {
        card.style.display = !q || card.dataset.search.includes(q) ? '' : 'none';
    });
};

/**
 * Select an activity type: remember it and reveal/update the prompt panel
 * (selected-activity chip). Repeated picks just replace the selection.
 *
 * @param {string} modname
 */
const selectActivity = (modname) => {
    const entry = catalog[modname];
    if (!entry) {
        return;
    }
    pendingSelection = modname;

    const iconEl = document.querySelector(Selectors.regions.chooserSelectedIcon);
    if (iconEl) {
        // Same container classes as the grid cards, so the loaded Boost theme
        // tints the icon by purpose natively.
        iconEl.className = 'chooser-selected-chip-icon icon-no-margin activityiconcontainer smaller ' + (entry.purpose || '');
        iconEl.innerHTML = entry.iconhtml || '';
    }
    const nameEl = document.querySelector(Selectors.regions.chooserSelectedName);
    if (nameEl) {
        nameEl.textContent = entry.displayname || modname;
    }

    const panel = document.querySelector(Selectors.regions.chooserPanel);
    if (panel) {
        panel.hidden = false;
    }
    const promptEl = document.querySelector(Selectors.regions.chooserPrompt);
    if (promptEl) {
        promptEl.focus();
    }
};

/**
 * Show/refresh or hide the selected-file chip to match the module `upload` state.
 */
const refreshSelectedFileChip = () => {
    const chip = document.querySelector(Selectors.regions.chooserSelectedFile);
    const nameEl = document.querySelector(Selectors.regions.chooserSelectedFileName);
    if (nameEl) {
        nameEl.textContent = upload.filename || '';
    }
    if (chip) {
        chip.style.display = upload.draftitemid ? '' : 'none';
    }
};

/**
 * Reset the prompt panel back to its pristine hidden state: no selection, no
 * file, empty prompt, "no images" radio. Runs on every modal close so a
 * reopen always starts clean.
 */
const resetChooserPanel = () => {
    setMode('add');
    pendingSelection = null;
    upload = {draftitemid: 0, filename: ''};

    const panel = document.querySelector(Selectors.regions.chooserPanel);
    if (panel) {
        panel.hidden = true;
    }
    const promptEl = document.querySelector(Selectors.regions.chooserPrompt);
    if (promptEl) {
        promptEl.value = '';
        promptEl.style.height = 'auto';
    }
    document.querySelectorAll(Selectors.regions.chooserGenerateImages).forEach((radio) => {
        radio.checked = radio.value === '0';
    });
    refreshSelectedFileChip();
};

/**
 * Open Moodle's core filepicker against a fresh SYSTEM-context draft area
 * (this page runs without a course) and remember the picked file. Same YUI
 * M.core_filepicker flow as the activity-AI chat form.
 *
 * @returns {Promise<void>}
 */
const openUploadPicker = async() => {
    if (upload.draftitemid) {
        // One file at a time — remove the current one first (chip's X button).
        return;
    }
    try {
        const pickerdata = await initFilepicker();
        if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
            return;
        }

        const pickerOptions = JSON.parse(pickerdata.options);
        const clientIdKey = 'client_id';
        pickerOptions[clientIdKey] = pickerdata.clientid;
        pickerOptions.itemid = pickerdata.draftitemid;

        YUI.use('core_filepicker', 'node', 'node-event-simulate', 'core_dndupload', (Y) => {
            if (pickerdata.templates) {
                try {
                    const templates = JSON.parse(pickerdata.templates);
                    if (templates && typeof templates === 'object') {
                        M.core_filepicker.set_templates(Y, templates);
                    }
                } catch (ex) {
                    // Ignore template errors.
                }
            }

            pickerOptions.formcallback = (fileinfo) => {
                upload = {
                    draftitemid: pickerOptions.itemid,
                    filename: fileinfo && fileinfo.file ? String(fileinfo.file) : '',
                };
                refreshSelectedFileChip();
            };

            if (!M.core_filepicker.instances[pickerOptions[clientIdKey]]) {
                M.core_filepicker.init(Y, pickerOptions);
            }

            M.core_filepicker.instances[pickerOptions[clientIdKey]].show();
        });
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Wire the prompt panel below the grid: textarea autoresize, file upload and
 * remove, and the confirm button that performs the actual insertion (add mode)
 * or in-place row update (edit mode).
 *
 * @param {Function} onPick - (sectionId, position, modname, extras) => void
 * @param {Function} onEdit - (sectionId, activityIndex, modname, extras) => void
 */
const wireChooserPanel = (onPick, onEdit) => {
    const promptEl = document.querySelector(Selectors.regions.chooserPrompt);
    if (promptEl) {
        promptEl.addEventListener('input', () => {
            promptEl.style.height = 'auto';
            promptEl.style.height = promptEl.scrollHeight + 'px';
        });
    }

    const uploadBtn = document.querySelector(Selectors.regions.chooserUpload);
    if (uploadBtn) {
        uploadBtn.addEventListener('click', (event) => {
            event.preventDefault();
            openUploadPicker();
        });
    }

    const removeBtn = document.querySelector(Selectors.regions.chooserSelectedFileRemove);
    if (removeBtn) {
        removeBtn.addEventListener('click', (event) => {
            event.preventDefault();
            upload = {draftitemid: 0, filename: ''};
            refreshSelectedFileChip();
        });
    }

    const confirmBtn = document.querySelector(Selectors.regions.chooserConfirm);
    if (confirmBtn) {
        confirmBtn.addEventListener('click', (event) => {
            event.preventDefault();
            if (!pendingTarget || !pendingSelection) {
                return;
            }
            const checked = document.querySelector(`${Selectors.regions.chooserGenerateImages}:checked`);
            const extras = {
                prompt: ((promptEl && promptEl.value) || '').trim(),
                generateimages: checked && Number(checked.value) === 1 ? 1 : 0,
                draftitemid: upload.draftitemid || 0,
                filename: upload.filename || '',
            };
            if (mode === 'edit') {
                onEdit(pendingTarget.sectionId, pendingTarget.activityIndex, pendingSelection, extras);
            } else {
                onPick(pendingTarget.sectionId, pendingTarget.position, pendingSelection, extras);
            }
            pendingTarget = null;
            jQuery(MODAL_SELECTOR).modal('hide');
        });
    }

    // Whether confirmed or dismissed, a closed modal always resets the panel
    // so the next open starts clean.
    jQuery(MODAL_SELECTOR).on('hidden.bs.modal', resetChooserPanel);
};

/**
 * Wire the chooser modal's search input, option clicks and prompt panel.
 * Called once — the grid's contents are replaced (never the grid element
 * itself), so delegation on the grid keeps working after renderChooserGrid
 * re-renders it. Clicking a grid card only selects it (revealing the prompt
 * panel); onPick fires when the panel's confirm button is pressed.
 *
 * @param {Function} onPick - (sectionId, position, modname, extras) => void where
 *     extras = {prompt: string, generateimages: 0|1, draftitemid: number, filename: string}
 * @param {Function} onEdit - (sectionId, activityIndex, modname, extras) => void,
 *     fired instead of onPick when the modal was opened by openActivityChooserForEdit
 */
export const wireChooserModal = (onPick, onEdit) => {
    const search = document.getElementById(SEARCH_ID);
    if (search) {
        search.addEventListener('input', () => filterChooserGrid(search.value));
    }

    const grid = document.getElementById(GRID_ID);
    if (!grid) {
        return;
    }
    grid.addEventListener('click', (event) => {
        const optionEl = event.target.closest(Selectors.actions.chooserOption);
        if (!optionEl || !pendingTarget) {
            return;
        }
        event.preventDefault();
        selectActivity(optionEl.dataset.modname);
    });

    wireChooserPanel(onPick, onEdit);
};
