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
 * The arguments of the request that starts a template generation.
 *
 * Kept apart from the AJAX call so it can be checked on its own. The guideline travels only as its id, and
 * only when one is chosen: the server reads the text, so nothing else can be sent in its place.
 *
 * @module     local_coursegen/local/courseai/template/start_args
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build the arguments of local_coursegen_start_template_generation.
 *
 * @param {number} templateId
 * @param {string} prompt The professor's single general instruction.
 * @param {number} draftItemId Draft area holding the syllabus, 0 when none.
 * @param {string} lang Language the professor picked for the course, empty for the template's own.
 * @param {boolean} withImages Whether the professor asked for the images to be generated.
 * @param {number|null} units Number of units wanted, null to keep the template's own.
 * @param {number} guidelineId Institutional guideline id, 0 for none.
 * @param {Object[]} spaceFiles The file of each space: {cmid, draftitemid}.
 * @param {Object[]} spaceActivities The activity the teacher brings to each space: {uid, cmid}.
 * @param {Object[]} spaceChoices The activity the teacher adds in each space, which the AI writes: {uid, modname, instruction}.
 * @returns {Object}
 */
export const buildStartArgs = (
    templateId, prompt, draftItemId, lang, withImages, units = null, guidelineId = 0,
    spaceFiles = [], spaceActivities = [], spaceChoices = []
) => {
    const args = {
        templateid: templateId,
        prompt: prompt,
        draftitemid: draftItemId,
        lang: lang || '',
        withimages: Boolean(withImages),
    };
    // Only what the teacher brought travels: the server takes an empty list when there is none.
    if (spaceFiles && spaceFiles.length) {
        args.spacefiles = spaceFiles;
    }
    if (spaceActivities && spaceActivities.length) {
        args.spaceactivities = spaceActivities;
    }
    if (spaceChoices && spaceChoices.length) {
        args.spacechoices = spaceChoices;
    }
    if (units !== null) {
        args.units = units;
    }
    if (guidelineId > 0) {
        args.systeminstructionid = guidelineId;
    }
    return args;
};
