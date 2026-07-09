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
 * Compose the "You added section/activity: NAME" turn.
 *
 * The added element has no name when the user clicks "add" — the model generates
 * it during the re-stream. So both the live path (at review) and the reload path
 * identify it the same way: it is the element in the settled plan whose id was
 * NOT present before the add (the caller passes those `beforeIds`). One shared
 * renderer keeps live === reload.
 *
 * @module     local_coursegen/local/courseai/ui/added-turn
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Name the newly-added SECTION turn, or null when none can be resolved.
 *
 * @param {Object} texts - Prefetched lang strings.
 * @param {Array} plan - The settled plan (live current_plan / reload round plan).
 * @param {string[]} beforeIds - Section ids that existed before the add.
 * @returns {string|null}
 */
export const addedSectionTurn = (texts, plan, beforeIds) => {
    const ids = beforeIds || [];
    const added = (plan || []).find(
        (s) => s && !s.deleted && s.id && ids.indexOf(s.id) === -1
    );
    const name = added && String(added.name || '').trim();
    if (!name) {
        return null;
    }
    return ((texts && texts.courseai_log_added_section_named) || 'You added section: {$a->name}')
        .replace('{$a->name}', name);
};

/**
 * Name the newly-added ACTIVITY turn, or null when none can be resolved.
 *
 * @param {Object} texts - Prefetched lang strings.
 * @param {Array} plan - The settled plan.
 * @param {string} parentSectionId - Section the activity was added to.
 * @param {string[]} beforeIds - Activity ids that existed in that section before.
 * @returns {string|null}
 */
export const addedActivityTurn = (texts, plan, parentSectionId, beforeIds) => {
    const ids = beforeIds || [];
    // The parent container may be a SUBSECTION: look through the top-level
    // sections first, then inside their subsections.
    let section = (plan || []).find((s) => s && s.id === parentSectionId);
    if (!section) {
        (plan || []).some((s) => {
            const sub = ((s && s.subsections) || []).find(
                (x) => x && x.id === parentSectionId
            );
            if (sub) {
                section = sub;
                return true;
            }
            return false;
        });
    }
    const activities = (section && section.activities) || [];
    const added = activities.find((a) => a && !a.deleted && a.id && ids.indexOf(a.id) === -1);
    const title = added && String(added.title || '').trim();
    if (!title) {
        return null;
    }
    return ((texts && texts.courseai_log_added_activity_named) || 'You added activity: {$a->title}')
        .replace('{$a->title}', title);
};
