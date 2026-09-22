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
 * Turns a structured plan section, or one activity's own detail, into the
 * light Markdown the left transcript and the regeneration block render.
 *
 * Mirrors the service's ``render_plan_text`` (section → ``### name`` +
 * description; each activity → ``**title** _(type)_`` + description + its
 * detailed plan as nested bullets), so the live incremental transcript and
 * the persisted/replayed block read the same. Headings stay shallow
 * (``###``); the scoped ``.cg-log-md`` CSS keeps them compact.
 *
 * @module     local_coursegen/local/courseai/ui/plan_markdown
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Markdown lines for every sub-element list a detailed plan carries (chapters with
 * subchapter nesting, questions, pages, discussions, entries, options). Mirrors the
 * center card renderer (detail-content.js) so the left transcript lists the same items.
 *
 * @param {Object} detail - detailed_plan object (or null).
 * @returns {string[]}
 */
const detailListsMd = (detail) => {
    if (!detail) {
        return [];
    }
    const readItem = (it) => {
        if (typeof it === 'string') {
            return {primary: it, secondary: ''};
        }
        return {
            primary: String(it.title || it.question || it.name || it.concept || '').trim(),
            secondary: String(it.summary || it.type || it.description || '').trim(),
        };
    };
    const fields = ['chapters', 'questions', 'pages', 'discussions', 'entries', 'options'];
    const lines = [];
    fields.forEach((field) => {
        const items = Array.isArray(detail[field]) ? detail[field] : [];
        if (!items.length) {
            return;
        }
        // A real Markdown ordered list — one item per line — so `marked` renders each
        // on its own <li> and CSS counters number them (1, 2, …). Book subchapters
        // (subchapter=1) are nested (3-space indent, under the ordered marker) so the
        // CSS counters read 1.1, 1.2 and the hierarchy shows. The literal "1." we write
        // is irrelevant — marked/CSS handle the actual numbering.
        lines.push('');
        items.forEach((raw) => {
            const {primary, secondary} = readItem(raw);
            const isSub = field === 'chapters' && raw && typeof raw === 'object' && Number(raw.subchapter) === 1;
            const indent = isSub ? '   ' : '';
            lines.push(indent + '1. **' + primary + '**' + (secondary ? ' — ' + secondary : ''));
        });
    });
    return lines;
};

/**
 * The description to show for one activity.
 *
 * The detailed plan's own description is the full one — what the student
 * submits, the instructions, the criteria — while the plan's is the one-line
 * summary written before the activity was detailed. The centre cards show the
 * full one, so the transcript shows the same: two panels describing the same
 * activity differently is the bug this replaced.
 *
 * @param {Object} activity - { description, detailedPlan }
 * @returns {string} The fullest description available, or ''.
 */
const activityDescription = (activity) => {
    const detailed = String((activity.detailedPlan || {}).activity_description || '').trim();
    return detailed || String(activity.description || '').trim();
};

/**
 * Render ONE activity's BODY (description + detailed plan) to Markdown, WITHOUT
 * the title line. Used by the regeneration block, where the activity title +
 * icon live in the item head (like a section name) and only the body goes in
 * the clamped detail. Mirrors the per-activity portion of formatSectionMd.
 *
 * @param {Object} activity - { description, detailedPlan } (title/type ignored here).
 * @returns {string} Markdown for the activity body.
 */
export const formatActivityDetailMd = (activity) => {
    if (!activity) {
        return '';
    }
    const lines = [];
    const activityDesc = activityDescription(activity);
    if (activityDesc) {
        lines.push(activityDesc);
    }
    lines.push(...detailListsMd(activity.detailedPlan || null));
    return lines.join('\n').trim();
};

/**
 * Render one structured plan section to light Markdown for the transcript.
 *
 * @param {Object} section - { name, description, activities: [
 *     { title, activity_type, description, detailedPlan } ] }.
 *     detailedPlan is the structured detail: { activity_description?,
 *     chapters?: [{title, summary}], questions?: [{question, type}] }.
 * @returns {string} Markdown for the section.
 */
export const formatSectionMd = (section) => {
    if (!section) {
        return '';
    }
    const lines = [];
    const name = String(section.name || '').trim();
    if (name) {
        lines.push('### ' + name);
    }
    const description = String(section.description || '').trim();
    if (description) {
        lines.push('');
        lines.push(description);
    }
    (section.activities || []).forEach((activity) => {
        const title = String(activity.title || '').trim() || 'Activity';
        const type = String(activity.activity_type || '').trim();
        lines.push('');
        lines.push(type ? '**' + title + '** _(' + type + ')_' : '**' + title + '**');
        const activityDesc = activityDescription(activity);
        if (activityDesc) {
            lines.push('');
            lines.push(activityDesc);
        }
        lines.push(...detailListsMd(activity.detailedPlan || null));
    });
    return lines.join('\n').trim();
};
