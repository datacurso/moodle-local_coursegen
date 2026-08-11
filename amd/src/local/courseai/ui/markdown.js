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
 * Markdown helpers for the LEFT planning transcript.
 *
 * The one place that turns the model's Markdown into HTML — ``marked`` renders
 * it, ``DOMPurify`` sanitizes it — and the one place that turns a structured
 * plan section into the Markdown shown. Both the live incremental renderer and
 * the reload replay use these, so generation and reload produce identical
 * output.
 *
 * Used by the left transcript AND by the plan cards in the centre, so every
 * slot showing model text renders and is sanitized the same way.
 *
 * @module     local_coursegen/local/courseai/ui/markdown
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as markedModule from 'local_coursegen/marked';
import DOMPurify from 'local_coursegen/purify';

/**
 * What the plan text is allowed to contain once rendered.
 *
 * Descriptions, chapter titles and the transcript are prose: emphasis, code,
 * lists, links and headings. Nothing here needs an attribute other than a link
 * target, so the list stays this short and anything else is dropped.
 */
const ALLOWED_TAGS = [
    'p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'blockquote',
    'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
];
const ALLOWED_ATTR = ['href', 'title'];

/**
 * Run a marked parser and sanitize what it produced.
 *
 * DOMPurify does the sanitizing: it parses the HTML and walks it, which is the
 * only way to catch what a regular expression over the markup misses — a
 * `javascript:` URL inside an otherwise ordinary link, for one. The plan text
 * comes from our own service, so this is defence in depth, but a syllabus the
 * teacher uploaded reaches the model and the model writes this text.
 *
 * @param {Function|undefined} parse - The marked entry point to use.
 * @param {string} md - Markdown source.
 * @returns {string} Sanitized HTML, or '' when the parser is unavailable.
 */
const sanitize = (parse, md) => {
    if (typeof parse !== 'function') {
        return '';
    }
    const html = parse(String(md || ''));
    const purify = DOMPurify.sanitize ? DOMPurify : (DOMPurify.default || null);
    if (!purify || typeof purify.sanitize !== 'function') {
        // Sanitizing is not optional: show the text rather than raw HTML.
        return String(md || '');
    }
    return purify.sanitize(html, {ALLOWED_TAGS, ALLOWED_ATTR});
};

/**
 * Render a Markdown string to HTML, reusing the bundled ``marked`` module.
 *
 * Block-level: the result carries its own <p>, lists and headings, so use it
 * for a slot that is a container. Sanitized on the way out.
 *
 * @param {string} md - Markdown source.
 * @returns {string} Sanitized HTML, or '' when no parser is available.
 */
export const renderMarkdown = (md) => {
    const parse = markedModule.parse
        || (markedModule.marked && markedModule.marked.parse)
        || (markedModule.default && markedModule.default.parse);
    return sanitize(parse, md);
};

/**
 * Render Markdown WITHOUT wrapping it in a block element.
 *
 * For slots that are already a paragraph, a heading or a cell: the block
 * renderer would nest a <p> inside them, which is invalid HTML and collapses
 * the spacing. Emphasis, code spans and links still render.
 *
 * @param {string} md - Markdown source.
 * @returns {string} Sanitized inline HTML, or '' when no parser is available.
 */
export const renderMarkdownInline = (md) => {
    const parseInline = markedModule.parseInline
        || (markedModule.marked && markedModule.marked.parseInline)
        || (markedModule.default && markedModule.default.parseInline);
    return sanitize(parseInline, md);
};


/**
 * Render one structured plan section to light Markdown for the transcript.
 *
 * Mirrors the service's ``render_plan_text`` (section → ``### name`` +
 * description; each activity → ``**title** _(type)_`` + description + its
 * detailed plan as nested bullets), so the live incremental transcript and the
 * persisted/replayed block read the same. Headings stay shallow (``###``); the
 * scoped ``.cg-log-md`` CSS keeps them compact.
 *
 * @param {Object} section - { name, description, activities: [
 *     { title, activity_type, description, detailedPlan } ] }.
 *     detailedPlan is the structured detail: { activity_description?,
 *     chapters?: [{title, summary}], questions?: [{question, type}] }.
 * @returns {string} Markdown for the section.
 */
/**
 * Render ONE activity's BODY (description + detailed plan) to Markdown, WITHOUT
 * the title line. Used by the regeneration block, where the activity title +
 * icon live in the item head (like a section name) and only the body goes in
 * the clamped detail. Mirrors the per-activity portion of formatSectionMd.
 *
 * @param {Object} activity - { description, detailedPlan } (title/type ignored here).
 * @returns {string} Markdown for the activity body.
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
