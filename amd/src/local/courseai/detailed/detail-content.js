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
 * Build a DocumentFragment with chapters, questions, and image suggestions.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {Object} options.parsed - Parsed activity data object.
 * @returns {DocumentFragment}
 */
export const buildActivityDetailContent = (ctx, {parsed}) => {
    const {texts} = ctx;
    const detailFragment = document.createDocumentFragment();

    const chapters = Array.isArray(parsed.chapters) ? parsed.chapters : [];
    if (chapters.length > 0) {
        detailFragment.appendChild(createDetailLabel(ctx, texts.courseai_chapters_label));
        const list = document.createElement('ul');
        list.className = 'dp-item-list';

        chapters.forEach((chapter, index) => {
            const item = document.createElement('li');
            item.className = 'dp-item';

            const number = document.createElement('span');
            number.className = 'dp-item-num';
            number.textContent = `${index + 1}.`;

            const body = document.createElement('div');
            const title = document.createElement('p');
            title.className = 'dp-item-title';
            title.textContent = chapter.title || '';
            body.appendChild(title);

            if (chapter.summary) {
                const sub = document.createElement('p');
                sub.className = 'dp-item-sub';
                sub.textContent = chapter.summary;
                body.appendChild(sub);
            }

            item.appendChild(number);
            item.appendChild(body);
            list.appendChild(item);
        });

        detailFragment.appendChild(list);
    }

    const questions = Array.isArray(parsed.questions) ? parsed.questions : [];
    if (questions.length > 0) {
        detailFragment.appendChild(createDetailLabel(ctx, texts.courseai_questions_label));
        const list = document.createElement('ul');
        list.className = 'dp-item-list';

        questions.forEach((question, index) => {
            const item = document.createElement('li');
            item.className = 'dp-item';

            const number = document.createElement('span');
            number.className = 'dp-item-num';
            number.textContent = `${index + 1}.`;

            const body = document.createElement('div');
            const title = document.createElement('p');
            title.className = 'dp-item-title';
            title.textContent = question.question || '';
            body.appendChild(title);

            item.appendChild(number);
            item.appendChild(body);

            if (question.type) {
                const type = document.createElement('span');
                type.className = 'dp-q-type';
                type.textContent = question.type;
                item.appendChild(type);
            }

            list.appendChild(item);
        });

        detailFragment.appendChild(list);
    }

    const imageSuggestions = Array.isArray(parsed.image_suggestions) ? parsed.image_suggestions : [];
    if (imageSuggestions.length > 0) {
        detailFragment.appendChild(createImagesDetail(ctx, {imageSuggestions}));
    }

    return detailFragment;
};
