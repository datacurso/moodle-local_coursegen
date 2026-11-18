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
 * The pictures of a template run: the "Imágenes" pill of the top bar, its list and the detail of one picture.
 *
 * Every picture event of the run carries a key, so each picture is one row that changes as it goes: waiting,
 * being made, ready or failed. The pill says how many are ready out of how many there are; a press opens the list,
 * where each row shows the template's picture and the new one, and a press on them opens both side by side.
 * The addresses of the pictures are asked to the plugin once a picture is known and again once it is made.
 *
 * @module     local_coursegen/local/courseai/template/images_panel
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getString} from 'core/str';
import {getTemplateImageUrls} from 'local_coursegen/local/courseai/template/repository';
import {closeActivitiesPanel} from 'local_coursegen/local/courseai/template/top_bar';
import {detailOf, nameOf} from 'local_coursegen/local/courseai/template/images_names';
import {closeModal, initImagesModal, openModal, refreshModal} from 'local_coursegen/local/courseai/template/images_modal';

const COMPONENT = 'local_coursegen';
const ROW_TEMPLATE = 'local_coursegen/template_image_row';
const RENDER_DELAY = 120;
const SLOT_KINDS = ['course_cover', 'section_image', 'summary_image'];
const IDS = {
    box: 'tplTopImages',
    toggle: 'tplTopImagesToggle',
    panel: 'tplTopImagesPanel',
    count: 'tplTopImagesCount',
    ready: 'tplTopImagesReady',
    bar: 'tplTopImagesBar',
    list: 'tplTopImagesList',
    modal: 'tplImageModal',
};

/** @type {Map<string, Object>} The pictures of the run by key, in the order they were first told. */
const pictures = new Map();
let sessionId = 0;
let renderTimer = null;
/** Whether every activity is written, so no new picture can appear and the total is final. */
let totalKnown = false;

const byId = (id) => document.getElementById(id);

/**
 * Use the session the pictures belong to, so their addresses can be asked for.
 *
 * @param {number} id
 */
export const useImagesSession = (id) => {
    sessionId = Number(id) || 0;
};

/**
 * Forget the pictures of a previous run and hide the pill.
 */
export const resetImages = () => {
    pictures.clear();
    totalKnown = false;
    closeModal();
    const box = byId(IDS.box);
    if (box) {
        box.hidden = true;
    }
    scheduleRender();
};

/**
 * The context of the row of a picture.
 *
 * @param {Object} picture
 * @param {number} waitingAfter
 * @returns {Promise<Object>}
 */
const rowContext = async(picture, waitingAfter) => ({
    key: picture.key,
    name: await nameOf(picture),
    detail: await detailOf(picture, waitingAfter),
    done: picture.state === 'done',
    working: picture.state === 'start',
    failed: picture.state === 'failed',
    waiting: picture.state === 'queued',
    original: picture.original || '',
    current: picture.current || '',
});

/**
 * The rows to draw: every picture that is not waiting, then the first waiting one with the count of the others.
 *
 * @returns {Array<{picture: Object, waitingAfter: number}>}
 */
const visibleRows = () => {
    const all = Array.from(pictures.values());
    const waiting = all.filter((picture) => picture.state === 'queued');
    const rows = all.filter((picture) => picture.state !== 'queued').map((picture) => ({picture, waitingAfter: 0}));
    if (waiting.length) {
        rows.push({picture: waiting[0], waitingAfter: waiting.length - 1});
    }
    return rows;
};

/**
 * Draw how many pictures are ready: out of the total once it is final, alone while activities are still written.
 *
 * While the AI writes the activities it may still add pictures, so a total would only grow; it is shown, with the
 * bar, once every activity is written.
 *
 * @param {number} done
 * @param {number} total
 * @returns {Promise<void>}
 */
const renderCounts = async(done, total) => {
    const bar = byId(IDS.bar);
    if (!totalKnown) {
        byId(IDS.count).textContent = `${done}`;
        byId(IDS.ready).textContent = await getString('courseai_images_ready_growing', COMPONENT, done);
        bar.parentElement.hidden = true;
        return;
    }
    byId(IDS.count).textContent = `${done}/${total}`;
    byId(IDS.ready).textContent = await getString('courseai_images_ready', COMPONENT, {done, total});
    let percent = 0;
    if (total > 0) {
        percent = Math.round((done / total) * 100);
    }
    bar.parentElement.hidden = false;
    bar.style.width = `${percent}%`;
};

/**
 * Say that every activity is written: no new picture can appear, so the total is final from now on.
 */
export const markImagesTotalKnown = () => {
    totalKnown = true;
    scheduleRender();
};

/**
 * Draw the pill, the head of the list and its rows.
 *
 * @returns {Promise<void>}
 */
const render = async() => {
    renderTimer = null;
    const list = byId(IDS.list);
    if (!list) {
        return;
    }
    const total = pictures.size;
    const done = Array.from(pictures.values()).filter((picture) => picture.state === 'done').length;
    const box = byId(IDS.box);
    if (box && total > 0) {
        box.hidden = false;
    }
    await renderCounts(done, total);
    const contexts = await Promise.all(visibleRows().map((row) => rowContext(row.picture, row.waitingAfter)));
    const rendered = await Promise.all(contexts.map((context) => Templates.renderForPromise(ROW_TEMPLATE, context)));
    const html = rendered.map((item) => item.html).join('');
    Templates.replaceNodeContents(list, html, '');
    await refreshModal();
};

/**
 * Draw again soon, once for a burst of events.
 */
const scheduleRender = () => {
    if (renderTimer) {
        return;
    }
    renderTimer = setTimeout(() => {
        render().catch(() => undefined);
    }, RENDER_DELAY);
};

/**
 * What the plugin needs to find the addresses of a picture.
 *
 * @param {Object} picture
 * @returns {Object}
 */
const itemOf = (picture) => {
    const file = picture.file || {};
    return {
        key: picture.key,
        kind: picture.kind,
        reference: picture.reference || '',
        // eslint-disable-next-line camelcase
        thread_id: file.thread_id || '',
        // eslint-disable-next-line camelcase
        file_id: file.file_id || '',
        filename: file.filename || '',
        path: file.path || '',
    };
};

/**
 * Ask the plugin for the addresses of a picture and draw them.
 *
 * @param {Object} picture
 * @returns {Promise<void>}
 */
const askAddresses = async(picture) => {
    if (!sessionId) {
        return;
    }
    const answer = await getTemplateImageUrls(sessionId, [itemOf(picture)]);
    const found = (answer || [])[0];
    if (!found) {
        return;
    }
    if (found.original) {
        picture.original = found.original;
    }
    if (found.current) {
        picture.current = found.current;
    }
    scheduleRender();
};

/**
 * Whether a picture starts from a picture of the template.
 *
 * @param {Object} picture
 * @returns {boolean}
 */
const hasOriginal = (picture) => SLOT_KINDS.includes(picture.kind) || (picture.kind === 'reference' && Boolean(picture.reference));

/**
 * Take one picture event of the run.
 *
 * @param {Object} data {key, kind, state, activity, place, reference, section, description, file}
 */
export const onPicture = (data) => {
    if (!data || !data.key) {
        return;
    }
    const known = pictures.get(data.key);
    const picture = Object.assign(known || {original: '', current: ''}, {
        key: data.key,
        kind: data.kind || '',
        state: data.state || 'queued',
        activity: data.activity || '',
        place: data.place || '',
        reference: data.reference || '',
        section: data.section,
        description: data.description || '',
    });
    if (data.file) {
        picture.file = data.file;
    }
    pictures.set(data.key, picture);
    if (!known && hasOriginal(picture)) {
        askAddresses(picture).catch(() => undefined);
    }
    if (picture.state === 'done' && picture.file) {
        askAddresses(picture).catch(() => undefined);
    }
    scheduleRender();
};

/**
 * Show or hide the list of pictures.
 *
 * @param {boolean} open
 */
const setOpen = (open) => {
    const toggle = byId(IDS.toggle);
    const panel = byId(IDS.panel);
    if (!toggle || !panel) {
        return;
    }
    if (open) {
        closeActivitiesPanel();
    }
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
};

/**
 * A press on the page: the pill toggles the list, a press outside closes it, a picture opens its detail.
 *
 * @param {Event} event
 */
const onPagePress = (event) => {
    const toggle = byId(IDS.toggle);
    const panel = byId(IDS.panel);
    if (!toggle || !panel) {
        return;
    }
    const opener = event.target.closest('[data-action="local_coursegen/template/open-image"]');
    if (opener) {
        openModal(opener.dataset.key);
        return;
    }
    if (toggle.contains(event.target)) {
        setOpen(panel.hidden);
        return;
    }
    const modal = byId(IDS.modal);
    if (modal && !modal.hidden && event.target === modal) {
        closeModal();
        return;
    }
    if (!panel.hidden && !panel.contains(event.target) && !(modal && modal.contains(event.target))) {
        setOpen(false);
    }
};

/**
 * A key on the page: Escape closes the detail first, then the list.
 *
 * @param {KeyboardEvent} event
 */
const onPageKey = (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    const modal = byId(IDS.modal);
    if (modal && !modal.hidden) {
        closeModal();
        return;
    }
    const panel = byId(IDS.panel);
    if (panel && !panel.hidden) {
        setOpen(false);
        byId(IDS.toggle).focus();
    }
};

/**
 * Start the images pill, its list and the detail.
 */
export const initImagesPanel = () => {
    if (!byId(IDS.box)) {
        return;
    }
    document.addEventListener('click', onPagePress);
    document.addEventListener('keydown', onPageKey);
    initImagesModal(pictures);
};
