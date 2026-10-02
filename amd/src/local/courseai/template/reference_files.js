/**
 * The "Course files" section of the template screen: shows the places of the
 * selected template that take a file and sends what the teacher brings.
 *
 * The markup is local_coursegen/template_reference_slots; this module only
 * feeds it and listens to it.
 *
 * @module     local_coursegen/local/courseai/template/reference_files
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Templates from 'core/templates';
import {getTemplateReferenceSlots, sendReferenceFile} from './repository';
import Selectors from './selectors';

/**
 * Draw the places of a template, unless the screen moved on to another template meanwhile.
 *
 * @param {HTMLElement} region
 * @param {number} templateId
 */
const render = async(region, templateId) => {
    const data = await getTemplateReferenceSlots(templateId);
    if (region.dataset.templateId !== String(templateId)) {
        return;
    }
    const {html, js} = await Templates.renderForPromise('local_coursegen/template_reference_slots', data);
    Templates.replaceNodeContents(region, html, js);
    region.hidden = !data.hasslots;
};

/**
 * Show the places of a template.
 *
 * @param {HTMLElement} region
 * @param {number} templateId
 */
export const loadReferenceFiles = async(region, templateId) => {
    region.dataset.templateId = String(templateId);
    try {
        await render(region, templateId);
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Empty the section, when no template is selected.
 *
 * @param {HTMLElement} region
 */
export const clearReferenceFiles = (region) => {
    delete region.dataset.templateId;
    region.replaceChildren();
    region.hidden = true;
};

/**
 * Send a file, or the removal of one, and draw the places again from the server's own answer.
 *
 * @param {HTMLElement} region
 * @param {string} slotKey
 * @param {string} action 'upload' or 'remove'.
 * @param {File|null} file
 */
const send = async(region, slotKey, action, file) => {
    const templateId = parseInt(region.dataset.templateId, 10);
    try {
        await sendReferenceFile(templateId, slotKey, action, file);
    } catch (error) {
        Notification.exception(error);
    }
    await loadReferenceFiles(region, templateId);
};

/**
 * Listen to the file inputs and the remove buttons of the section.
 *
 * @param {HTMLElement} region
 */
export const wireReferenceFiles = (region) => {
    region.addEventListener('change', async(event) => {
        const input = event.target.closest(Selectors.actions.uploadReference);
        if (!input || !input.files.length) {
            return;
        }
        input.disabled = true;
        await send(region, input.dataset.slotKey, 'upload', input.files[0]);
    });
    region.addEventListener('click', async(event) => {
        const button = event.target.closest(Selectors.actions.removeReference);
        if (!button) {
            return;
        }
        button.disabled = true;
        await send(region, button.dataset.slotKey, 'remove', null);
    });
};
