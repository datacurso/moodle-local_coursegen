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
 * Asks the server whether an activity may be marked as a template.
 *
 * An activity can only be a template when it has at least one placeholder.
 * The server is the source of truth for that rule: when it refuses, its own
 * message is shown to the user, who can then add the placeholder first.
 *
 * @module     local_coursegen/local/template/template_placeholder_check
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {add as addToast} from 'core/toast';
import {checkTemplateActivity} from 'local_coursegen/local/template/repository';

/**
 * The text as it can be shown inside the toast, which renders its message as markup.
 *
 * @param {string} text The text to show.
 * @returns {string} The same text with its markup characters escaped.
 */
const escapeText = (text) => {
    const holder = document.createElement('span');
    holder.textContent = text;
    return holder.innerHTML;
};

/**
 * Whether the server lets an activity be marked as a template. When it does
 * not, the server's message is shown in an error toast.
 *
 * @param {number} cmid Course module id of the activity.
 * @returns {Promise<boolean>} True when the activity may be marked as a template.
 */
export const isTemplateAllowed = async(cmid) => {
    try {
        await checkTemplateActivity(cmid);
        return true;
    } catch (error) {
        const message = escapeText(error.message);
        await addToast(message, {type: 'danger', closeButton: true, autohide: false});
        return false;
    }
};
