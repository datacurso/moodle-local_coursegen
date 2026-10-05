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
 * The files the teacher brings for the spaces of a template.
 *
 * A space is picked with the same file selector the syllabus uses: the file
 * lands in a draft area, and the row keeps its name and that draft area until
 * the generation starts and takes it.
 *
 * @module     local_coursegen/local/courseai/template/space_files
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import YUI from 'core/yui';
import {initFilepicker} from '../../../repository/courseai';
import {showFilePicker} from '../context/filepicker';
import Selectors from './selectors';
import {setSpaceFile, clearSpaceFile, hasMissingRequiredSpace} from './state';

/**
 * Whether a generation is on screen, from which on the files can no longer change.
 *
 * @returns {boolean}
 */
const isGenerating = () => document.body.classList.contains(Selectors.classes.generating);

/**
 * The button of a space card that opens the file selector.
 *
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @returns {HTMLButtonElement|null}
 */
const findPickButton = (sectionIndex, activityIndex) => {
    const position = `[data-section-index="${sectionIndex}"][data-activity-index="${activityIndex}"]`;
    const selector = `${Selectors.actions.pickSpaceFile}${position}`;
    return document.querySelector(selector);
};

/**
 * Put focus back on the button of a card the structure has just drawn again.
 *
 * @param {number} sectionIndex
 * @param {number} activityIndex
 */
const focusPickButton = (sectionIndex, activityIndex) => {
    const button = findPickButton(sectionIndex, activityIndex);
    if (button) {
        button.focus();
    }
};

/**
 * Show or clear the uploading state of a button: disabled, spinning, with its busy label.
 *
 * @param {HTMLButtonElement|null} button
 * @param {boolean} busy
 */
const setButtonBusy = (button, busy) => {
    if (!button) {
        return;
    }
    const label = button.querySelector(Selectors.regions.spaceButtonLabel);
    button.disabled = busy;
    button.classList.toggle(Selectors.classes.busy, busy);
    if (busy) {
        button.setAttribute('aria-busy', 'true');
        button.dataset.labelIdle = label.textContent;
        label.textContent = button.dataset.labelBusy;
        return;
    }
    button.removeAttribute('aria-busy');
    label.textContent = button.dataset.labelIdle || label.textContent;
};

/**
 * Open the file selector for one space and keep the file it returns.
 *
 * @param {Object} tplState
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Function} onChange - Called after the row changed, to draw it again; may return a promise.
 * @returns {Promise<void>}
 */
export const pickSpaceFile = async(tplState, sectionIndex, activityIndex, onChange) => {
    if (isGenerating()) {
        return;
    }
    const onPicked = async(filename, draftitemid) => {
        if (setSpaceFile(tplState, sectionIndex, activityIndex, filename, draftitemid)) {
            await onChange();
            focusPickButton(sectionIndex, activityIndex);
        }
    };
    const button = findPickButton(sectionIndex, activityIndex);
    setButtonBusy(button, true);
    try {
        await showFilePicker({
            state: {},
            CourseaiRepository: {initFilepicker},
            Notification,
            YUI,
            texts: {},
            onPicked,
        });
    } finally {
        setButtonBusy(button, false);
    }
};

/**
 * Empty the file of one space.
 *
 * @param {Object} tplState
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Function} onChange - Called after the row changed, to draw it again; may return a promise.
 * @returns {Promise<void>}
 */
export const removeSpaceFile = async(tplState, sectionIndex, activityIndex, onChange) => {
    if (isGenerating()) {
        return;
    }
    if (clearSpaceFile(tplState, sectionIndex, activityIndex)) {
        await onChange();
        focusPickButton(sectionIndex, activityIndex);
    }
};

/**
 * Let the Generate button follow the required spaces: it stays off while one has no file.
 *
 * Nothing happens before a template is loaded, or while a generation is on
 * screen: from then on the button belongs to the review.
 *
 * @param {Object} tplState
 * @param {HTMLButtonElement|null} genBtn
 */
export const refreshGenerateState = (tplState, genBtn) => {
    if (!genBtn || !tplState.loaded || isGenerating()) {
        return;
    }
    genBtn.disabled = hasMissingRequiredSpace(tplState);
};
