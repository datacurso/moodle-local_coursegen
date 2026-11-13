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
import {noticeFeed} from 'local_coursegen/local/courseai/template/thread_feed';

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

    // The same markup as any other turn of the thread (see ui/log.js), so the failure reads as one more message of
    // the left column and not as a banner over the page.
    const card = document.createElement('div');
    card.id = CARD_ID;
    card.className = 'cg-log-entry cg-log-entry--danger cg-log-entry--turn-ai';
    card.setAttribute('role', 'alert');
    const bar = document.createElement('span');
    bar.className = 'cg-log-bar';
    bar.setAttribute('aria-hidden', 'true');
    const content = document.createElement('span');
    content.className = 'cg-log-body';
    const message = document.createElement('span');
    message.className = 'cg-log-msg';
    const heading = document.createElement('strong');
    heading.className = 'd-block mb-1';
    heading.textContent = title;
    const body = document.createElement('span');
    body.className = 'd-block mb-2';
    body.textContent = code === 'high_demand' ? busy : text;
    const buttons = document.createElement('span');
    buttons.className = 'cg-card-actions';
    const retryButton = document.createElement('button');
    retryButton.type = 'button';
    retryButton.className = 'cg-card-button cg-card-button--primary';
    retryButton.textContent = retry;
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'cg-card-button';
    closeButton.textContent = close;
    buttons.append(retryButton, closeButton);
    message.append(heading, body, buttons);
    content.append(message);
    card.append(bar, content);

    const host = noticeFeed() || document.getElementById('courseaiChatScroll') || document.body;
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
