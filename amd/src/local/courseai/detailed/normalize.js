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
 * Section normalization helper for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/normalize
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Normalize raw server sections to a stable internal structure.
 *
 * Soft-deleted elements stay in the server tree but must never render
 * (a deleted section/activity must not be offered as an action target).
 *
 * @param {Object} ctx
 * @param {Array}  sections - Raw sections array from the server.
 * @returns {Array}
 */
export const normalizeInitialSections = (ctx, sections) => {
    const {texts, formatTemplate} = ctx;
    const activeSections = (sections || []).filter((section) => !section.deleted);

    return activeSections.map((section, sectionidx) => ({
        id: section.id || `s${sectionidx}`,
        section_index: section.section_index ?? sectionidx,
        position: section.position ?? sectionidx,
        name: section.name || formatTemplate(texts.courseai_section_label, {section: sectionidx + 1, name: ''}),
        description: section.description || '',
        activities: (section.activities || [])
            .filter((activity) => !activity.deleted)
            .map((activity, activityidx) => ({
                id: activity.id || `s${sectionidx}-a${activityidx}`,
                position: activity.position ?? activityidx,
                activity_type: activity.activity_type || activity.type || 'quiz',
                title: activity.title || activity.name || `${texts.courseai_activity_default} ${activityidx + 1}`,
                description: activity.description || ''
            }))
    }));
};
