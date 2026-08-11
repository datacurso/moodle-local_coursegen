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
 * Activity detail-content builder for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/detail-content
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderMarkdownInline} from 'local_coursegen/local/courseai/ui/markdown';
import {createImagesDetail} from './images';

/**
 * Create a section label paragraph.
 *
 * @param {Object} ctx
 * @param {string} text
 * @returns {HTMLParagraphElement}
 */
export const createDetailLabel = (ctx, text) => { // eslint-disable-line no-unused-vars
    const label = document.createElement('p');
    label.className = 'dp-detail-label';
    label.textContent = text;
    return label;
};

/**
 * The sub-element lists a plan card can show, mapped to their i18n label. Adding a
 * new list-bearing activity type = ONE entry here + its list field in the service
 * schema; nothing else in the plugin changes. `nested` (book) renders chapter →
 * subchapter numbering; `secondaryAs` picks how the item's second line shows
 * ('badge' = the quiz-style type chip, 'sub' = a muted sub-line).
 */
const LIST_FIELDS = [
    {field: 'chapters', labelKey: 'courseai_chapters_label', nested: true, secondaryAs: 'sub'},
    {field: 'questions', labelKey: 'courseai_questions_label', secondaryAs: 'badge'},
    {field: 'pages', labelKey: 'courseai_pages_label', secondaryAs: 'sub'},
    {field: 'discussions', labelKey: 'courseai_discussions_label', secondaryAs: 'sub'},
    {field: 'entries', labelKey: 'courseai_entries_label', secondaryAs: 'sub'},
    {field: 'options', labelKey: 'courseai_options_label', secondaryAs: 'sub'},
];

/**
 * Read an item's primary + secondary text tolerantly across the shapes the service
 * emits: {title, summary} (chapters/pages/discussions/entries), {question, type}
 * (quiz), a plain string (choice options).
 *
 * @param {Object|string} it
 * @returns {{primary: string, secondary: string}}
 */
const readItem = (it) => {
    if (typeof it === 'string') {
        return {primary: it, secondary: ''};
    }
    const primary = it.title || it.question || it.name || it.concept || '';
    const secondary = it.summary || it.type || it.description || '';
    return {primary: String(primary), secondary: String(secondary)};
};

/**
 * Build a <ul> for one sub-element list (questions/chapters/pages/…). Book chapters
 * (nested) number as 1, 1.1, 1.2, 2, … and indent subchapters so the hierarchy reads.
 *
 * @param {Array} items
 * @param {Object} cfg - one LIST_FIELDS entry.
 * @returns {HTMLUListElement}
 */
const buildItemList = (items, cfg) => {
    const list = document.createElement('ul');
    list.className = 'dp-item-list';

    let chapterNo = 0;
    let subNo = 0;

    items.forEach((raw, index) => {
        const {primary, secondary} = readItem(raw);
        const isSub = cfg.nested && raw && typeof raw === 'object' && Number(raw.subchapter) === 1;

        const item = document.createElement('li');
        item.className = isSub ? 'dp-item dp-item--sub' : 'dp-item';

        const number = document.createElement('span');
        number.className = 'dp-item-num';
        if (cfg.nested) {
            if (isSub) {
                subNo += 1;
                number.textContent = `${chapterNo || 1}.${subNo}`;
            } else {
                chapterNo += 1;
                subNo = 0;
                number.textContent = `${chapterNo}.`;
            }
        } else {
            number.textContent = `${index + 1}.`;
        }

        const body = document.createElement('div');
        const title = document.createElement('p');
        title.className = 'dp-item-title';
        title.innerHTML = renderMarkdownInline(primary);
        body.appendChild(title);

        if (secondary && cfg.secondaryAs === 'sub') {
            const sub = document.createElement('p');
            sub.className = 'dp-item-sub';
            sub.innerHTML = renderMarkdownInline(secondary);
            body.appendChild(sub);
        }

        item.appendChild(number);
        item.appendChild(body);

        if (secondary && cfg.secondaryAs === 'badge') {
            const badge = document.createElement('span');
            badge.className = 'dp-q-type';
            badge.textContent = secondary;
            item.appendChild(badge);
        }

        list.appendChild(item);
    });

    return list;
};

/**
 * Build a DocumentFragment with every sub-element list the activity carries
 * (chapters, questions, pages, discussions, entries, options…) plus image suggestions.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {Object} options.parsed - Parsed activity data object.
 * @returns {DocumentFragment}
 */
export const buildActivityDetailContent = (ctx, {parsed}) => {
    const {texts} = ctx;
    const detailFragment = document.createDocumentFragment();

    LIST_FIELDS.forEach((cfg) => {
        const items = Array.isArray(parsed[cfg.field]) ? parsed[cfg.field] : [];
        if (items.length === 0) {
            return;
        }
        detailFragment.appendChild(createDetailLabel(ctx, texts[cfg.labelKey] || cfg.field));
        detailFragment.appendChild(buildItemList(items, cfg));
    });

    const imageSuggestions = Array.isArray(parsed.image_suggestions) ? parsed.image_suggestions : [];
    if (imageSuggestions.length > 0) {
        detailFragment.appendChild(createImagesDetail(ctx, {imageSuggestions}));
    }

    return detailFragment;
};
