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
 * What the person sees while a failure is retried by itself: which retry it is, of how many, and how long is left
 * for the next one, in the thread, with the same markup as the rest of it.
 *
 * @module     local_coursegen/local/courseai/template/auto_retry
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {currentFeed} from 'local_coursegen/local/courseai/template/thread_feed';
import {countdown} from 'local_coursegen/local/courseai/template/retry_schedule';

const CARD_ID = 'cgAutoRetryCard';

const STRINGS = [
    'courseai_template_retry_status',
    'courseai_template_retry_running',
    'courseai_template_retry_now',
    'courseai_template_retry_stop',
    'courseai_template_retry_default_reason',
];

/**
 * Take the card off the thread, if it is there.
 */
export const clearRetryCard = () => {
    document.getElementById(CARD_ID)?.remove();
};

/**
 * Build the card: the reason, a line that says which retry it is and the time left, and the two buttons.
 *
 * @param {string} reason What went wrong, in the words of the person's language.
 * @param {Object} text The strings of the card.
 * @returns {{card: HTMLElement, status: HTMLElement, now: HTMLButtonElement, stop: HTMLButtonElement}}
 */
const buildCard = (reason, text) => {
    const card = document.createElement('div');
    card.id = CARD_ID;
    card.className = 'cg-log-entry cg-log-entry--turn-ai';
    card.setAttribute('role', 'status');
    const bar = document.createElement('span');
    bar.className = 'cg-log-bar';
    bar.setAttribute('aria-hidden', 'true');
    const content = document.createElement('span');
    content.className = 'cg-log-body';
    const message = document.createElement('span');
    message.className = 'cg-log-msg';
    const heading = document.createElement('strong');
    heading.className = 'd-block mb-1';
    heading.textContent = reason || text.reason;
    const status = document.createElement('span');
    status.className = 'd-block mb-2 cg-retry-status';
    const buttons = document.createElement('span');
    buttons.className = 'cg-card-actions';
    const now = document.createElement('button');
    now.type = 'button';
    now.className = 'cg-card-button cg-card-button--primary';
    now.textContent = text.now;
    const stop = document.createElement('button');
    stop.type = 'button';
    stop.className = 'cg-card-button';
    stop.textContent = text.stop;
    buttons.append(now, stop);
    message.append(heading, status, buttons);
    content.append(message);
    card.append(bar, content);
    return {card, status, now, stop};
};

/**
 * Show a retry that is coming, and wait for it.
 *
 * The wait ends when the time runs out or when the person asks to retry at once; the person can also stop the
 * retries, and then the decision is theirs.
 *
 * @param {Object} what
 * @param {number} what.attempt Number of the retry, from 1.
 * @param {number} what.total How many retries there are.
 * @param {number} what.seconds Seconds until it.
 * @param {string} what.reason What went wrong.
 * @returns {Promise<string>} 'retry' to retry now, 'stop' when the person stopped the retries.
 */
export const waitBeforeRetry = async({attempt, total, seconds, reason}) => {
    const [status, running, now, stop, defaultReason] = await getStrings(
        STRINGS.map((key) => ({key, component: 'local_coursegen'}))
    );
    clearRetryCard();
    const view = buildCard(reason, {now, stop, reason: defaultReason});
    const feed = currentFeed() || document.getElementById('courseaiChatScroll') || document.body;
    feed.append(view.card);
    view.card.scrollIntoView({block: 'nearest'});

    let release = null;
    const early = new Promise((resolve) => {
        release = resolve;
    });
    let stopped = false;
    view.now.addEventListener('click', () => release());
    view.stop.addEventListener('click', () => {
        stopped = true;
        release();
    });

    const stage = document.getElementById('tplGenStage');
    await countdown(seconds, (left) => {
        const line = status.replace('{$a->attempt}', attempt).replace('{$a->total}', total).replace('{$a->seconds}', left);
        view.status.textContent = line;
        if (stage) {
            stage.textContent = line;
        }
    }, {skip: early});

    if (stopped) {
        clearRetryCard();
        return 'stop';
    }
    view.status.textContent = running.replace('{$a->attempt}', attempt).replace('{$a->total}', total);
    if (stage) {
        stage.textContent = view.status.textContent;
    }
    view.now.disabled = true;
    view.stop.disabled = true;
    return 'retry';
};
