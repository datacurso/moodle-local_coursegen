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
 * What a generation tells the person while it runs, under the phase it is in: that the AI provider asked to wait,
 * or that a request is being tried again or is slow. The pictures of the run are counted by the images pill.
 *
 * The service sends stable codes and numbers, never sentences; every sentence here is a language string.
 *
 * @module     local_coursegen/local/courseai/template/generation_status
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import {serviceRecovered, showServiceRetry} from 'local_coursegen/local/courseai/template/auto_retry';

const NOTICE_ID = 'cgGenNotice';
/** A notice about a slow request leaves the screen after this long if nothing else replaces it. */
const NOTICE_SECONDS = 25;

const NOTICE_STRINGS = {
    rate_limit_wait: 'courseai_template_notice_rate_limit_wait',
    rate_limit_over: 'courseai_template_notice_rate_limit_over',
    slow_calls: 'courseai_template_notice_slow_calls',
};

let noticeTimer = null;

/**
 * Create the elements under the phase line, once.
 *
 * @returns {{notice: Element}|null} Null when the page has no phase line.
 */
const ensureElements = () => {
    const stage = document.getElementById('tplGenStage');
    if (!stage) {
        return null;
    }
    let notice = document.getElementById(NOTICE_ID);
    if (!notice) {
        notice = document.createElement('p');
        notice.id = NOTICE_ID;
        notice.className = 'small mb-1 text-warning-emphasis';
        notice.setAttribute('aria-live', 'polite');
        notice.hidden = true;
        stage.after(notice);
    }
    return {notice};
};

/**
 * Empty everything this module shows: a new run, or a phase that has nothing to add.
 *
 * @returns {void}
 */
export const resetStatus = () => {
    const elements = ensureElements();
    if (!elements) {
        return;
    }
    clearTimeout(noticeTimer);
    elements.notice.hidden = true;
    elements.notice.textContent = '';
};

/**
 * Say what the service reports about the wait.
 *
 * @param {Object} data {code, seconds?, attempt?, of?}
 * @returns {Promise<string>} The sentence shown, empty when there was nothing to show.
 */
export const showNotice = async(data) => {
    if (data.code === 'retry_over') {
        serviceRecovered();
        return '';
    }
    if (data.code === 'retrying') {
        // The service is trying again after the AI provider failed: the same card as when the connection fails. The
        // card keeps the line of the status bar alive itself, so nothing is returned to be written there once.
        await showServiceRetry(data);
        return '';
    }
    const elements = ensureElements();
    const key = NOTICE_STRINGS[data.code];
    if (!elements || !key) {
        return '';
    }
    clearTimeout(noticeTimer);
    const text = await getString(key, 'local_coursegen', {
        seconds: data.seconds,
        attempt: data.attempt,
        of: data.of,
    });
    elements.notice.textContent = text;
    elements.notice.hidden = false;
    // The wait for a provider limit stays until it is over; the others fade if nothing else is said.
    if (data.code !== 'rate_limit_wait') {
        noticeTimer = setTimeout(() => {
            elements.notice.hidden = true;
        }, NOTICE_SECONDS * 1000);
    }
    return text;
};
