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
 * The phase labels of a template generation and the header subtitle that shows them.
 *
 * @module     local_coursegen/local/courseai/template/generation_stage
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {hideWorkingIndicator, showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';

/** Phase keys the service reports, plus the two this module owns. */
const STAGE_STRINGS = {
    reviewing: 'courseai_template_stage_reviewing',
    style: 'courseai_template_stage_style',
    activities: 'courseai_template_stage_activities',
    activity_images: 'courseai_template_stage_activity_images',
    section_images: 'courseai_template_stage_section_images',
    saving: 'courseai_template_stage_saving',
    connecting: 'courseai_template_stage_connecting',
};
const TITLE_STRING = 'courseai_template_generating_title';
const ADJUST_FAILED_STRING = 'courseai_template_adjust_failed';
const ADJUST_TOO_LONG_STRING = 'courseai_template_adjust_toolong';

let labels = null;

/**
 * The batched string request for every phase key.
 *
 * @param {Array<string>} keys
 * @returns {Array<Object>}
 */
const stageStringRequests = (keys) => keys.map((key) => ({key: STAGE_STRINGS[key], component: 'local_coursegen'}));

/**
 * Copy the fetched phase strings onto the labels map, keyed by phase.
 *
 * @param {Object} target
 * @param {Array<string>} keys
 * @param {Array<string>} values
 */
const assignStageLabels = (target, keys, values) => {
    keys.forEach((key, index) => {
        target[key] = values[index];
    });
};

/**
 * The localised header strings, fetched once.
 *
 * @returns {Promise<Object>} Keyed by phase key, plus `title`.
 */
export const getLabels = async() => {
    if (!labels) {
        const keys = Object.keys(STAGE_STRINGS);
        const requests = stageStringRequests(keys);
        const values = await getStrings([
            ...requests,
            {key: TITLE_STRING, component: 'local_coursegen'},
            {key: ADJUST_FAILED_STRING, component: 'local_coursegen'},
            {key: ADJUST_TOO_LONG_STRING, component: 'local_coursegen'},
        ]);
        labels = {
            title: values[keys.length],
            adjustFailed: values[keys.length + 1],
            adjustTooLong: values[keys.length + 2],
        };
        assignStageLabels(labels, keys, values);
    }
    return labels;
};

/**
 * Show one phase label in the header's subtitle.
 *
 * @param {string} key
 */
export const paintStage = async(key) => {
    const text = (await getLabels())[key];
    if (!text) {
        return;
    }
    const stage = document.getElementById('tplGenStage');
    if (stage) {
        stage.textContent = text;
    }
    // Waiting for the professor is not work in progress: free mode shows no
    // indicator then, only the decision card.
    if (key === 'reviewing') {
        hideWorkingIndicator();
        return;
    }
    // Free mode keeps both panels on the same sentence, updating one indicator
    // in place rather than stacking an entry per phase. showWorkingIndicator
    // does exactly that, and pins itself to the bottom slot while the composer
    // is away - which here is the whole generation.
    showWorkingIndicator({}, text);
};
