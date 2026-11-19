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
import Notification from 'core/notification';
import {noticeFeed} from 'local_coursegen/local/courseai/template/thread_feed';

const CARD_ID = 'cgRetryCard';
// The failure of a run whose activities have a mold with nothing for the AI to fill: trying again cannot mend it.
export const UNFILLABLE = 'template_source_invalid';
// A refusal because of the license (see classes/local/service/license_failure.php): no credits left, a license not
// valid or not allowed here, a consumption limit. Waiting does not mend it: the person is told why and where to fix it.
export const NO_CREDITS = 'notenoughtokens';
export const LICENSE_CODES = [NO_CREDITS, 'license_not_allowed', 'invalidlicensekey', 'forbidden', 'error_ratelimit_exceeded'];
const MANAGE_CREDITS_URL = 'https://shop.datacurso.com/index.php?m=tokens_manager';
const STRINGS = [
    'courseai_template_error_title',
    'courseai_template_error_text',
    'courseai_template_error_high_demand',
    'courseai_template_retry',
    'courseai_template_retry_close',
    'courseai_license_no_credits_title',
    'courseai_license_no_credits',
    'courseai_license_title',
    'courseai_license_manage',
];

/**
 * The text of a message that may carry markup, read without running anything in it.
 *
 * @param {string} html
 * @returns {string}
 */
const plainText = (html) => new DOMParser().parseFromString(html || '', 'text/html').body.textContent.trim();

/**
 * The title and the text of the card: why it stopped, in the words that fit the failure.
 *
 * @param {string} code
 * @param {string} detail
 * @param {Object} texts The strings of the card, by key.
 * @returns {{title: string, text: string}}
 */
const wordsFor = (code, detail, texts) => {
    if (code === NO_CREDITS) {
        return {title: texts.courseai_license_no_credits_title, text: texts.courseai_license_no_credits};
    }
    if (LICENSE_CODES.includes(code)) {
        return {title: texts.courseai_license_title, text: plainText(detail) || texts.courseai_template_error_text};
    }
    if (code === 'high_demand') {
        return {title: texts.courseai_template_error_title, text: texts.courseai_template_error_high_demand};
    }
    if (code === UNFILLABLE) {
        return {title: texts.courseai_template_error_title, text: texts.courseai_template_error_unfillable};
    }
    return {title: texts.courseai_template_error_title, text: texts.courseai_template_error_text};
};

/**
 * Ask whether to try again.
 *
 * @param {string} code The code the service gave to the failure.
 * @param {string} detail What the service said about it: for a mold with nothing to fill, the names of the activities;
 *     for a refusal because of the license, its message.
 * @returns {Promise<boolean>} True to try again, false to close.
 */
export const askToRetry = async(code, detail = '') => {
    const keys = [...STRINGS, 'courseai_template_error_unfillable'];
    const values = await getStrings([
        ...STRINGS.map((key) => ({key, component: 'local_coursegen'})),
        {key: 'courseai_template_error_unfillable', component: 'local_coursegen', param: detail},
    ]);
    const texts = Object.fromEntries(keys.map((key, index) => [key, values[index]]));
    const {title, text} = wordsFor(code, detail, texts);
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
    body.textContent = text;
    const buttons = document.createElement('span');
    buttons.className = 'cg-card-actions';
    const retryButton = document.createElement('button');
    retryButton.type = 'button';
    retryButton.className = 'cg-card-button cg-card-button--primary';
    retryButton.textContent = texts.courseai_template_retry;
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'cg-card-button';
    closeButton.textContent = texts.courseai_template_retry_close;
    buttons.append(retryButton, closeButton);
    if (LICENSE_CODES.includes(code)) {
        const manage = document.createElement('a');
        manage.className = 'cg-card-button';
        manage.href = MANAGE_CREDITS_URL;
        manage.target = '_blank';
        manage.rel = 'noopener';
        manage.textContent = texts.courseai_license_manage;
        buttons.append(manage);
    }
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

/**
 * Tell a refusal because of the license that stops a run before it starts (no credits left...), in plain words with
 * the way to manage the credits, instead of the raw error of the service.
 *
 * @param {Object} error The error a request gave: {errorcode, message}.
 * @returns {Promise<boolean>} True when it was a refusal because of the license and it was told.
 */
export const tellLicenseRefusal = async(error) => {
    const code = error?.errorcode || error?.code || '';
    if (!LICENSE_CODES.includes(code)) {
        return false;
    }
    const [noCreditsTitle, noCredits, licenseTitle, manage, close] = await getStrings([
        'courseai_license_no_credits_title',
        'courseai_license_no_credits_start',
        'courseai_license_title',
        'courseai_license_manage',
        'courseai_template_retry_close',
    ].map((key) => ({key, component: 'local_coursegen'})));
    const body = document.createElement('div');
    const text = document.createElement('p');
    text.textContent = code === NO_CREDITS ? noCredits : plainText(error.message);
    const link = document.createElement('a');
    link.className = 'btn btn-primary';
    link.href = MANAGE_CREDITS_URL;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = manage;
    body.append(text, link);
    await Notification.alert(code === NO_CREDITS ? noCreditsTitle : licenseTitle, body.innerHTML, close);
    return true;
};
