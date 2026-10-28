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
 * Moodle's file picker for the chat to create an activity: opens it and tells which file was chosen.
 *
 * @module     local_coursegen/local/activityai/filepicker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import YUI from 'core/yui';

import * as repository from 'local_coursegen/local/activityai/repository';

/**
 * Open the file picker. A new draft area is made each time, so each file of the chat has its own.
 *
 * @param {Object} options
 * @param {number} options.courseid The course of the activity.
 * @param {string} [options.role] image to pick only pictures; the server limits what the picker shows and accepts.
 * @param {string[]|null} [options.acceptedTypes] The extensions that can be chosen, such as ['.png'], or null for any.
 * @param {Function} options.onPicked Called with {draftitemid, filename} when a file is chosen.
 * @returns {Promise<void>}
 */
export const pickFile = async({courseid, role = '', acceptedTypes = null, onPicked}) => {
    const pickerdata = await repository.initFilepicker({courseid, role});
    if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
        return;
    }

    const pickerOptions = JSON.parse(pickerdata.options);
    const clientIdKey = 'client_id';
    pickerOptions[clientIdKey] = pickerdata.clientid;
    pickerOptions.itemid = pickerdata.draftitemid;
    if (acceptedTypes) {
        pickerOptions.accepted_types = acceptedTypes;
    }

    YUI.use('core_filepicker', 'node', 'node-event-simulate', 'core_dndupload', (Y) => {
        if (pickerdata.templates) {
            try {
                const templates = JSON.parse(pickerdata.templates);
                if (templates && typeof templates === 'object') {
                    M.core_filepicker.set_templates(Y, templates);
                }
            } catch (ex) {
                // Ignore.
            }
        }

        pickerOptions.formcallback = (fileinfo) => {
            onPicked({
                draftitemid: pickerOptions.itemid,
                filename: fileinfo && fileinfo.file ? String(fileinfo.file) : '',
            });
        };

        if (!M.core_filepicker.instances[pickerOptions[clientIdKey]]) {
            M.core_filepicker.init(Y, pickerOptions);
        }

        M.core_filepicker.instances[pickerOptions[clientIdKey]].show();
    });
};
