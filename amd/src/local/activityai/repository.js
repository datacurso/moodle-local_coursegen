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
 * Repository for Activity AI.
 *
 * All write operations must be invoked from mutations.
 *
 * @module     local_coursegen/local/activityai/repository
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {
    createModStream,
    createMod,
    sendActivityFeedback,
    initActivityFilepicker,
    uploadActivityFile,
} from 'local_coursegen/repository/activity';

export const startSession = async(payload) => {
    return createModStream(payload);
};

export const sendFeedback = async(payload) => {
    return sendActivityFeedback(payload);
};

export const createActivity = async(payload) => {
    return createMod(payload);
};

export const initFilepicker = async(payload) => {
    return initActivityFilepicker(payload);
};

export const uploadFile = async(payload) => {
    return uploadActivityFile(payload);
};
