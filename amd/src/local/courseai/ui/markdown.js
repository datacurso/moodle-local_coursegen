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
 * One place that turns Markdown into HTML (reusing the bundled ``marked``
 * module) and one place that turns a structured plan section into the Markdown
 * the transcript shows. Both the live incremental renderer and the reload
 * replay use these, so generation and reload produce identical output.
 *
 * @module     local_coursegen/local/courseai/ui/markdown
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as markedModule from 'local_coursegen/marked';

/**
 * Render a Markdown string to HTML, reusing the bundled ``marked`` module.
 *
 * No DOMPurify is bundled, so as a defensive measure (the content is
 * server-generated plan text) script/style/embed blocks and inline
 * event-handler attributes are stripped from the output.
 *
 * @param {string} md - Markdown source.
 * @returns {string} Sanitized HTML, or '' when no parser is available.
 */
export const renderMarkdown = (md) => {
    const parse = markedModule.parse
        || (markedModule.marked && markedModule.marked.parse)
        || (markedModule.default && markedModule.default.parse);
    if (typeof parse !== 'function') {
        return '';
    }
    return parse(String(md || ''))
        .replace(/<\/?(?:script|style|iframe|object|embed|link|meta)[^>]*>/gi, '')
        .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
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
export const formatActivityDetailMd = (activity) => {
    if (!activity) {
        return '';
    }
    const lines = [];
    const activityDesc = String(activity.description || '').trim();
    if (activityDesc) {
        lines.push(activityDesc);
    }
    const detail = activity.detailedPlan || null;
    if (detail) {
        const chapters = Array.isArray(detail.chapters) ? detail.chapters : [];
        const questions = Array.isArray(detail.questions) ? detail.questions : [];
        if (chapters.length) {
            lines.push('');
            chapters.forEach((chapter, index) => {
                const cTitle = String(chapter.title || '').trim();
                const cSummary = String(chapter.summary || '').trim();
                lines.push((index + 1) + '. **' + cTitle + '**' + (cSummary ? ' — ' + cSummary : ''));
            });
        }
        if (questions.length) {
            lines.push('');
            questions.forEach((question, index) => {
                const qText = String(question.question || '').trim();
                const qType = String(question.type || '').trim();
                lines.push((index + 1) + '. ' + qText + (qType ? ' _(' + qType + ')_' : ''));
            });
        }
    }
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
        const activityDesc = String(activity.description || '').trim();
        if (activityDesc) {
            lines.push('');
            lines.push(activityDesc);
        }
        const detail = activity.detailedPlan || null;
        if (detail) {
            const chapters = Array.isArray(detail.chapters) ? detail.chapters : [];
            const questions = Array.isArray(detail.questions) ? detail.questions : [];
            if (chapters.length) {
                lines.push('');
                chapters.forEach((chapter, index) => {
                    const cTitle = String(chapter.title || '').trim();
                    const cSummary = String(chapter.summary || '').trim();
                    lines.push((index + 1) + '. **' + cTitle + '**' + (cSummary ? ' — ' + cSummary : ''));
                });
            }
            if (questions.length) {
                lines.push('');
                questions.forEach((question, index) => {
                    const qText = String(question.question || '').trim();
                    const qType = String(question.type || '').trim();
                    lines.push((index + 1) + '. ' + qText + (qType ? ' _(' + qType + ')_' : ''));
                });
            }
        }
    });
    return lines.join('\n').trim();
};
