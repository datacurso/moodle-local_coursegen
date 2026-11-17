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
 * Ask the person for a file with the same filepicker the input bar uses for the syllabus.
 *
 * @module     local_coursegen/local/courseai/template/syllabus_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import YUI from 'core/yui';
import {initFilepicker} from '../../../repository/courseai';
import {showFilePicker} from '../context/filepicker';

/**
 * Open the filepicker and wait for the person to pick a file.
 *
 * The picker of Moodle answers as soon as it is open, not when it is closed, so nothing is resolved until a file is
 * picked; closing it without one leaves the question as it was, ready to be asked again.
 *
 * @returns {Promise<Object|null>} {filename, draftitemid}, or null when the picker could not be opened.
 */
export const pickFile = () => new Promise((resolve) => {
    showFilePicker({
        state: {},
        CourseaiRepository: {initFilepicker},
        Notification,
        YUI,
        texts: {},
        onPicked: (filename, draftitemid) => resolve({filename, draftitemid}),
    }).catch(() => resolve(null));
});
