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
 * The notices the generation leaves about an activity, shown in its row when the review opens.
 *
 * Today the only notice is a video the service could not find: the activity was created
 * without it, and the teacher reads which one it was before approving the course.
 *
 * @module     local_coursegen/local/courseai/template/generation_notices
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getString} from 'core/str';
import Selectors from 'local_coursegen/local/courseai/template/selectors';

/** The string that words each kind of notice the service reports. */
const NOTICE_STRINGS = {
    video: 'courseai_template_video_missing',
};

/**
 * Empty the notices of every row.
 *
 * @returns {void}
 */
const clearNotices = () => {
    document.querySelectorAll(Selectors.regions.generationNotices).forEach((region) => {
        region.replaceChildren();
    });
};

/**
 * The row region that holds the notices of one activity.
 *
 * @param {string} uid
 * @returns {Element|undefined}
 */
const regionOf = (uid) => Array.from(document.querySelectorAll(Selectors.regions.generationNotices))
    .find((region) => region.dataset.noticeUid === uid);

/**
 * Render one notice into the row of its activity.
 *
 * @param {Element} region
 * @param {Object} warning {kind, instruction}
 * @returns {Promise<void>}
 */
const renderNotice = async(region, warning) => {
    const message = await getString(NOTICE_STRINGS[warning.kind], 'local_coursegen', warning.instruction);
    const {html, js} = await Templates.renderForPromise('local_coursegen/template_generation_notice', {message});
    Templates.appendNodeContents(region, html, js);
};

/**
 * The renders still to do for the notices of the generated activities.
 *
 * @param {Array} generated The activities the service reports as generated.
 * @returns {Array<Promise<void>>}
 */
const pendingNotices = (generated) => (generated || []).flatMap((entry) => {
    const region = regionOf(entry.uid);
    if (!region) {
        return [];
    }
    const known = (entry.warnings || []).filter((warning) => NOTICE_STRINGS[warning.kind]);
    return known.map((warning) => renderNotice(region, warning));
});

/**
 * Show the notices of the generated activities, replacing any shown before.
 *
 * @param {Array} generated The activities the service reports as generated, each with its warnings.
 * @returns {Promise<void>}
 */
export const showNotices = async(generated) => {
    clearNotices();
    await Promise.all(pendingNotices(generated));
};
