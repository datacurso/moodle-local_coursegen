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
 * The detail of one picture of a template run: the template's picture and the new one side by side, and the way to
 * the previous and the next picture.
 *
 * @module     local_coursegen/local/courseai/template/images_modal
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import {activityPlace, nameOf} from 'local_coursegen/local/courseai/template/images_names';

const COMPONENT = 'local_coursegen';
const IDS = {
    modal: 'tplImageModal',
    modalTitle: 'tplImageModalTitle',
    modalWhere: 'tplImageModalWhere',
    modalOriginal: 'tplImageModalOriginal',
    modalCurrent: 'tplImageModalCurrent',
    modalOriginalLink: 'tplImageModalOriginalLink',
    modalCurrentLink: 'tplImageModalCurrentLink',
    modalPosition: 'tplImageModalPosition',
    modalPrev: 'tplImageModalPrev',
    modalNext: 'tplImageModalNext',
    modalClose: 'tplImageModalClose',
};

/** @type {Map<string, Object>} The pictures of the run, shared with the panel. */
let pictures = new Map();
let openKey = '';

const byId = (id) => document.getElementById(id);

/**
 * Whether the detail is open.
 *
 * @returns {boolean}
 */
export const isModalOpen = () => openKey !== '';

/**
 * Draw the open detail again, after its picture changed.
 *
 * @returns {Promise<void>}
 */
export const refreshModal = async() => {
    if (openKey) {
        await fillModal(openKey);
    }
};

/**
 * Put one picture in a frame of the detail, or say there is none.
 *
 * @param {HTMLElement} frame
 * @param {HTMLAnchorElement} link
 * @param {string} address
 * @returns {Promise<void>}
 */
const showInFrame = async(frame, link, address) => {
    frame.textContent = '';
    link.hidden = !address;
    if (!address) {
        frame.textContent = await getString('courseai_images_no_picture', COMPONENT);
        return;
    }
    const image = document.createElement('img');
    image.src = address;
    image.alt = '';
    frame.appendChild(image);
    link.href = address;
};

/**
 * Fill the detail with one picture.
 *
 * @param {string} key
 * @returns {Promise<void>}
 */
export const fillModal = async(key) => {
    const keys = Array.from(pictures.keys());
    const position = keys.indexOf(key);
    const picture = pictures.get(key);
    if (!picture || position < 0) {
        return;
    }
    byId(IDS.modalTitle).textContent = await nameOf(picture);
    let where = activityPlace(picture);
    if (!where) {
        where = await nameOf(picture);
    }
    byId(IDS.modalWhere).textContent = await getString('courseai_images_where', COMPONENT, where);
    await showInFrame(byId(IDS.modalOriginal), byId(IDS.modalOriginalLink), picture.original);
    await showInFrame(byId(IDS.modalCurrent), byId(IDS.modalCurrentLink), picture.current);
    byId(IDS.modalPosition).textContent = await getString('courseai_images_position', COMPONENT,
        {position: position + 1, total: keys.length});
    byId(IDS.modalPrev).disabled = position === 0;
    byId(IDS.modalNext).disabled = position === keys.length - 1;
};

/**
 * Open the detail of a picture.
 *
 * @param {string} key
 */
export const openModal = (key) => {
    const modal = byId(IDS.modal);
    if (!modal) {
        return;
    }
    openKey = key;
    modal.hidden = false;
    fillModal(key).catch(() => undefined);
    byId(IDS.modalClose).focus();
};

/**
 * Close the detail.
 */
export const closeModal = () => {
    const modal = byId(IDS.modal);
    if (modal) {
        modal.hidden = true;
    }
    openKey = '';
};

/**
 * Go to the previous or the next picture in the detail.
 *
 * @param {number} step -1 or 1.
 */
const moveModal = (step) => {
    const keys = Array.from(pictures.keys());
    const next = keys[keys.indexOf(openKey) + step];
    if (next) {
        openKey = next;
        fillModal(next).catch(() => undefined);
    }
};

/**
 * Start the detail on the pictures of the run.
 *
 * @param {Map<string, Object>} shared The pictures of the run by key.
 */
export const initImagesModal = (shared) => {
    pictures = shared;
    if (!byId(IDS.modal)) {
        return;
    }
    byId(IDS.modalClose).addEventListener('click', closeModal);
    byId(IDS.modalPrev).addEventListener('click', () => moveModal(-1));
    byId(IDS.modalNext).addEventListener('click', () => moveModal(1));
};
