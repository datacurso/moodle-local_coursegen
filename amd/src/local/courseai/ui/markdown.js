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
 * The one place that turns the model's Markdown into HTML — ``marked``
 * renders it, ``DOMPurify`` sanitizes it. Both the live incremental renderer
 * and the reload replay use this, so generation and reload produce identical
 * output. Turning a structured plan section into the Markdown rendered here
 * lives in plan_markdown.js.
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
    let purify = DOMPurify.default || null;
    if (DOMPurify.sanitize) {
        purify = DOMPurify;
    }
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
 * Sanitize HTML that is already HTML.
 *
 * The mould's own markup, rendered with a draft in it, arrives as HTML rather
 * than as Markdown: it is a preview of real activity content, headings, lists
 * and styling included. It still goes through the same allow-list as anything
 * else shown here, because part of what it carries is model text.
 *
 * @param {string} html - HTML source.
 * @returns {string} Sanitized HTML, or '' when sanitizing is unavailable.
 */
export const renderHtml = (html) => {
    let purify = DOMPurify.default || null;
    if (DOMPurify.sanitize) {
        purify = DOMPurify;
    }
    if (!purify || typeof purify.sanitize !== 'function') {
        // Sanitizing is not optional: show nothing rather than raw HTML.
        return '';
    }
    return purify.sanitize(String(html || ''), {ALLOWED_TAGS, ALLOWED_ATTR});
};
