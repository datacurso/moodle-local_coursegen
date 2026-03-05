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
 * DOM selectors for AI course creation streaming page.
 *
 * @module     local_coursegen/selectors
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const regions = {
    root: "[data-region='local_coursegen/aicoursecreation/root']",
    status: "[data-region='local_coursegen/aicoursecreation/status']",
    output: "[data-region='local_coursegen/aicoursecreation/output']",
    threadId: "[data-region='local_coursegen/aicoursecreation/threadid']",
    chat: "[data-region='local_coursegen/aicoursecreation/chat']",
    feedbackPanel: "[data-region='local_coursegen/aicoursecreation/feedbackpanel']",
    feedbackText: "[data-region='local_coursegen/aicoursecreation/feedbacktext']",
    btnAccept: "[data-region='local_coursegen/aicoursecreation/btnaccept']",
    btnRevise: "[data-region='local_coursegen/aicoursecreation/btnrevise']",
    btnFetchResult: "[data-region='local_coursegen/aicoursecreation/fetchresult']",
};

export const activityRegions = {
    root: "[data-region='local_coursegen/activity/root']",
    userMessages: "[data-region='local_coursegen/activity/user_messages']",
    streamingSection: "[data-region='local_coursegen/activity/streaming']",
    form: "[data-region='local_coursegen/activity/form']",
    promptTextarea: "[data-region='local_coursegen/activity/prompt']",
    uploadButton: "[data-region='local_coursegen/activity/upload']",
    selectedFile: "[data-region='local_coursegen/activity/selectedfile']",
    selectedFileName: "[data-region='local_coursegen/activity/selectedfile_name']",
    removeSelectedFileButton: "[data-region='local_coursegen/activity/selectedfile_remove']",
    sendButton: "[data-region='local_coursegen/activity/send']",
};
