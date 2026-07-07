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
 * File-picker and toggle-wrap helpers for the Course AI context section.
 *
 * @module     local_coursegen/local/courseai/context/filepicker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bind a toggle-wrap element so clicking or pressing Space/Enter toggles its checkbox.
 *
 * @param {HTMLElement|null} toggleWrap
 * @param {HTMLInputElement|null} checkbox
 * @returns {void}
 */
export const bindToggleWrap = (toggleWrap, checkbox) => {
    if (!toggleWrap || !checkbox) {
        return;
    }

    // Keep aria-checked in sync on wraps exposed as menuitemcheckbox (the
    // "+" menu toggle rows declare the attribute statically in the template).
    const syncAriaChecked = () => {
        if (toggleWrap.hasAttribute('aria-checked')) {
            toggleWrap.setAttribute('aria-checked', checkbox.checked ? 'true' : 'false');
        }
    };
    checkbox.addEventListener('change', syncAriaChecked);
    syncAriaChecked();

    const triggerToggle = () => {
        if (checkbox.disabled) {
            return;
        }
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change', {bubbles: true}));
    };

    toggleWrap.addEventListener('click', (event) => {
        if (event.target === checkbox) {
            return;
        }
        event.preventDefault();
        triggerToggle();
    });

    toggleWrap.addEventListener('keydown', (event) => {
        if (event.key !== ' ' && event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        triggerToggle();
    });

    if (!toggleWrap.hasAttribute('tabindex')) {
        toggleWrap.setAttribute('tabindex', '0');
    }
};

/**
 * Open the Moodle core file picker and wire its callback to update syllabus chip state.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.CourseaiRepository
 * @param {Object} params.Notification
 * @param {Object} params.YUI
 * @param {Object} params.texts
 * @param {Function} params.refreshChipsRow
 * @param {Function} params.refreshCompactChipsRow
 * @returns {Promise<void>}
 */
export const showFilePicker = async(
    {state, CourseaiRepository, Notification, YUI, texts, refreshChipsRow, refreshCompactChipsRow}
) => {
    try {
        const pickerdata = await CourseaiRepository.initFilepicker();

        if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
            await Notification.exception(new Error(
                texts.courseai_error_init_filepicker || 'Failed to initialize filepicker'
            ));
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
                if (fileinfo && fileinfo.file) {
                    const filename = String(fileinfo.file);
                    state.syllabusFilename = filename;
                    state.draftitemid = pickerOptions.itemid;

                    const chipSyllabus = document.getElementById('chipSyllabus');
                    const chipSyllabusName = document.getElementById('chipSyllabusName');
                    if (chipSyllabusName) {
                        chipSyllabusName.textContent = filename;
                    }
                    if (chipSyllabus) {
                        chipSyllabus.classList.remove('hidden');
                    }
                    refreshChipsRow();

                    const compactChipSyllabus = document.getElementById('compactChipSyllabus');
                    const compactChipSyllabusName = document.getElementById('compactChipSyllabusName');
                    if (compactChipSyllabusName) {
                        compactChipSyllabusName.textContent = filename;
                    }
                    if (compactChipSyllabus) {
                        compactChipSyllabus.classList.remove('hidden');
                    }
                    refreshCompactChipsRow();
                }
            };

            if (!M.core_filepicker.instances[pickerOptions[clientIdKey]]) {
                M.core_filepicker.init(Y, pickerOptions);
            }

            M.core_filepicker.instances[pickerOptions[clientIdKey]].show();
        });
    } catch (error) {
        await Notification.exception(error);
    }
};
