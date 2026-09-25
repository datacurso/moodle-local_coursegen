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
 * The reduced input bar pinned at the bottom of the template-mode left panel:
 * syllabus attach (same no-course filepicker mechanics as free mode),
 * generate-images toggle, and language select. Values live in tplState, ready
 * for the generation payload.
 *
 * Split out of template_mode.js, which only wires the structure-editing
 * events and the Generate button.
 *
 * @module     local_coursegen/local/courseai/template/input_bar
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import YUI from 'core/yui';
import {initFilepicker} from '../../../repository/courseai';
import {bindToggleWrap, showFilePicker} from '../context/filepicker';

/**
 * Show/refresh or hide the input bar's syllabus chip to match tplState.
 *
 * @param {Object} tplState
 */
export const refreshSyllabusChip = (tplState) => {
    const hasFile = !!tplState.syllabusdraftitemid;
    const chipsRow = document.getElementById('tplChipsRow');
    const chip = document.getElementById('tplChipSyllabus');
    const chipName = document.getElementById('tplChipSyllabusName');
    if (chipName) {
        chipName.textContent = tplState.syllabusfilename || '';
    }
    if (chip) {
        chip.classList.toggle('hidden', !hasFile);
    }
    if (chipsRow) {
        let display = 'none';
        if (hasFile) {
            display = '';
        }
        chipsRow.style.display = display;
    }
};

/**
 * Wire the reduced input bar pinned at the bottom of the left panel: syllabus
 * attach (same no-course filepicker mechanics as free mode), generate-images
 * toggle, and language select. Values live in tplState, ready for the future
 * generation payload — the Generate button itself stays a stub elsewhere.
 *
 * @param {Object} tplState
 * @param {Object} state - Page state (createInitialState) carrying languages/defaultLang.
 */
export const wireInputBar = (tplState, state) => {
    // Adaptation prompt — composer textarea, value tracked in tplState.
    const promptInput = document.getElementById('tplPromptInput');
    if (promptInput) {
        promptInput.addEventListener('input', () => {
            tplState.prompt = promptInput.value;
        });
    }

    // Language select — same options source as free mode (the page-context
    // languages array parsed by courseai.js into state.languages).
    const langSelect = document.getElementById('tplLangSelect');
    if (langSelect) {
        (state.languages || []).forEach((language) => {
            const option = document.createElement('option');
            option.value = language.code;
            option.textContent = language.name;
            langSelect.appendChild(option);
        });
        if (tplState.lang) {
            langSelect.value = tplState.lang;
        }
        // If the default language isn't offered, track whatever the select
        // actually shows so state and UI never disagree.
        tplState.lang = langSelect.value || tplState.lang;
        langSelect.addEventListener('change', () => {
            tplState.lang = langSelect.value;
        });
    }

    // Generate-images toggle — same toggle-track pattern as free mode.
    const imgToggleWrap = document.getElementById('tplImgToggleWrap');
    const imgCheckbox = document.getElementById('tplWithImages');
    if (imgToggleWrap && imgCheckbox) {
        bindToggleWrap(imgToggleWrap, imgCheckbox);
        imgCheckbox.addEventListener('change', () => {
            let generateimages = 0;
            if (imgCheckbox.checked) {
                generateimages = 1;
            }
            tplState.generateimages = generateimages;
            imgToggleWrap.classList.toggle('on', imgCheckbox.checked);
        });
    }

    // Syllabus attach — reuses the free-mode courseai_filepicker_init flow via
    // showFilePicker's onPicked hook; the picked draft file lives in tplState.
    const attachBtn = document.getElementById('tplBtnSyllabus');
    if (attachBtn) {
        attachBtn.addEventListener('click', async() => {
            await showFilePicker({
                state: {},
                CourseaiRepository: {initFilepicker},
                Notification,
                YUI,
                texts: {},
                onPicked: (filename, draftitemid) => {
                    tplState.syllabusfilename = filename;
                    tplState.syllabusdraftitemid = draftitemid;
                    refreshSyllabusChip(tplState);
                },
            });
        });
    }

    const removeBtn = document.getElementById('tplChipSyllabusRemove');
    if (removeBtn) {
        removeBtn.addEventListener('click', () => {
            tplState.syllabusfilename = '';
            tplState.syllabusdraftitemid = 0;
            refreshSyllabusChip(tplState);
        });
    }
};
