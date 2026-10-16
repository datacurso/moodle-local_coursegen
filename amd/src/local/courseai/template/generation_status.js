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
 * that a request is being tried again or is slow, and, for the pictures, which one is being made, from which
 * reference, and how many are ready.
 *
 * The service sends stable codes and numbers, never sentences; every sentence here is a language string.
 *
 * @module     local_coursegen/local/courseai/template/generation_status
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const NOTICE_ID = 'cgGenNotice';
const PICTURES_ID = 'cgGenPictures';
const COUNTER_ID = 'cgGenPicturesCounter';
/** How many lines of pictures stay on screen: the ones being made and the last ones that finished. */
const VISIBLE_LINES = 5;
/** A notice about a slow request leaves the screen after this long if nothing else replaces it. */
const NOTICE_SECONDS = 25;

const NOTICE_STRINGS = {
    rate_limit_wait: 'courseai_template_notice_rate_limit_wait',
    rate_limit_over: 'courseai_template_notice_rate_limit_over',
    slow_calls: 'courseai_template_notice_slow_calls',
    retrying: 'courseai_template_notice_retrying',
};

let noticeTimer = null;

/**
 * Create the elements under the phase line, once.
 *
 * @returns {{notice: Element, pictures: Element, counter: Element}|null} Null when the page has no phase line.
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
        const counter = document.createElement('p');
        counter.id = COUNTER_ID;
        counter.className = 'small mb-1 fw-bold';
        counter.hidden = true;
        const pictures = document.createElement('ul');
        pictures.id = PICTURES_ID;
        pictures.className = 'small list-unstyled mb-0 text-muted';
        stage.after(notice, counter, pictures);
    }
    return {
        notice,
        counter: document.getElementById(COUNTER_ID),
        pictures: document.getElementById(PICTURES_ID),
    };
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
    elements.counter.hidden = true;
    elements.counter.textContent = '';
    elements.pictures.replaceChildren();
};

/**
 * Say what the service reports about the wait.
 *
 * @param {Object} data {code, seconds?, attempt?, of?}
 * @returns {Promise<string>} The sentence shown, empty when there was nothing to show.
 */
export const showNotice = async(data) => {
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

/**
 * The line that tells what happens to one picture.
 *
 * @param {Object} data {state, activity, reference, done, total}
 * @returns {Promise<string>}
 */
const pictureLine = (data) => {
    const params = {activity: data.activity, reference: data.reference};
    if (data.state === 'done') {
        return getString('courseai_template_image_done', 'local_coursegen', params);
    }
    if (data.state === 'failed') {
        return getString('courseai_template_image_failed', 'local_coursegen', params);
    }
    const key = data.reference ? 'courseai_template_image_start_ref' : 'courseai_template_image_start_new';
    return getString(key, 'local_coursegen', params);
};

/**
 * Show one picture event: the counter, and the line of the picture, newest first.
 *
 * @param {Object} data {state, activity, reference, done, total}
 * @returns {Promise<string>} The counter and the line of the picture as one sentence, empty when nothing was shown.
 */
export const showPicture = async(data) => {
    const elements = ensureElements();
    if (!elements) {
        return '';
    }
    const counter = await getString('courseai_template_image_counter', 'local_coursegen', {
        done: data.done,
        total: data.total,
    });
    elements.counter.textContent = counter;
    elements.counter.hidden = false;
    const line = await pictureLine(data);
    const item = document.createElement('li');
    item.textContent = line;
    elements.pictures.prepend(item);
    while (elements.pictures.children.length > VISIBLE_LINES) {
        elements.pictures.lastElementChild.remove();
    }
    return `${counter} · ${line}`;
};
