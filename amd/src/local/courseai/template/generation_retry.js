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
 * What the person is offered when a generation stops on an error: say that what was made is kept and offer to try
 * again, in the thread of the left column, instead of a technical error with a stack trace.
 *
 * @module     local_coursegen/local/courseai/template/generation_retry
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const CARD_ID = 'cgRetryCard';
const STRINGS = [
    'courseai_template_error_title',
    'courseai_template_error_text',
    'courseai_template_error_high_demand',
    'courseai_template_retry',
    'courseai_template_retry_close',
];

/**
 * Ask whether to try again.
 *
 * @param {string} code The code the service gave to the failure.
 * @returns {Promise<boolean>} True to try again, false to close.
 */
export const askToRetry = async(code) => {
    const [title, text, busy, retry, close] = await getStrings(
        STRINGS.map((key) => ({key, component: 'local_coursegen'}))
    );
    document.getElementById(CARD_ID)?.remove();

    const card = document.createElement('div');
    card.id = CARD_ID;
    card.className = 'alert alert-danger m-2';
    card.setAttribute('role', 'alert');
    const heading = document.createElement('p');
    heading.className = 'fw-bold mb-1';
    heading.textContent = title;
    const body = document.createElement('p');
    body.className = 'mb-2';
    body.textContent = code === 'high_demand' ? busy : text;
    const buttons = document.createElement('div');
    buttons.className = 'd-flex gap-2';
    const retryButton = document.createElement('button');
    retryButton.type = 'button';
    retryButton.className = 'btn btn-primary btn-sm';
    retryButton.textContent = retry;
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn btn-secondary btn-sm';
    closeButton.textContent = close;
    buttons.append(retryButton, closeButton);
    card.append(heading, body, buttons);

    const host = document.getElementById('courseaiChatScroll') || document.body;
    host.append(card);
    card.scrollIntoView({block: 'nearest'});

    return new Promise((resolve) => {
        retryButton.addEventListener('click', () => {
            card.remove();
            resolve(true);
        });
        closeButton.addEventListener('click', () => {
            card.remove();
            resolve(false);
        });
    });
};
