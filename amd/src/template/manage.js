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
 * The list of templates: asks before deleting one and deletes it.
 *
 * @module     local_coursegen/template/manage
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {deleteTemplate} from 'local_coursegen/repository/template';

const LIST_REGION = '[data-region="local_coursegen/template/list"]';
const DELETE_ACTION = '[data-action="local_coursegen/template/delete"]';

/**
 * Ask the admin to confirm the deletion of a template.
 *
 * @param {HTMLElement} button The delete button, which carries the name of the template.
 * @return {Promise<boolean>} Whether the admin confirmed.
 */
async function confirmDeletion(button) {
    const title = await getString('template_delete', 'local_coursegen');
    const question = await getString('template_delete_confirm', 'local_coursegen', button.dataset.name);
    try {
        await Notification.deleteCancelPromise(title, question, title);
    } catch (cancelled) {
        return false;
    }

    return true;
}

/**
 * Delete a template and show the list again.
 *
 * @param {number} templateid The template to delete, for example 3.
 */
async function removeTemplate(templateid) {
    try {
        await deleteTemplate(templateid);
        window.location.reload();
    } catch (error) {
        Notification.exception(error);
    }
}

/**
 * Ask for confirmation and delete a template.
 *
 * @param {HTMLElement} button The delete button, which carries the id and the name of the template.
 */
async function confirmAndDelete(button) {
    const confirmed = await confirmDeletion(button);
    if (!confirmed) {
        return;
    }

    const templateid = parseInt(button.dataset.templateid, 10);
    await removeTemplate(templateid);
}

/**
 * Handle the clicks of the list.
 *
 * @param {Event} event The click.
 */
function onClick(event) {
    const button = event.target.closest(DELETE_ACTION);
    if (!button) {
        return;
    }

    event.preventDefault();
    confirmAndDelete(button);
}

/**
 * Start the list.
 */
export function init() {
    const root = document.querySelector(LIST_REGION);
    if (!root) {
        return;
    }

    root.addEventListener('click', onClick);
}
