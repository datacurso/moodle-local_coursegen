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
import {ATTEMPTS, countdown} from 'local_coursegen/local/courseai/template/retry_schedule';
import {showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';

const CARD_ID = 'cgAutoRetryCard';
const SERVICE_CARD_ID = 'cgServiceRetryCard';
/** A retry card of the service that nothing has renewed leaves the screen after this long, in seconds. */
const SERVICE_CARD_LINGER_SECONDS = 20;

const STRINGS = [
    'courseai_template_retry_status',
    'courseai_template_retry_running',
    'courseai_template_retry_default_reason',
    'courseai_template_service_retry_reason',
];

/**
 * Take the card off the thread, if it is there.
 */
export const clearRetryCard = () => {
    document.getElementById(CARD_ID)?.remove();
};

/** How many retries the service announced in this run, and whether the page has started its own. */
let serviceRetries = 0;
let pageRetrying = false;

/**
 * The retries are over, because the run moved, ended or the person has to decide: nothing is left on the screen and
 * the next failure starts counting from the first retry.
 */
export const retriesOver = () => {
    pageRetrying = false;
    serviceRetries = 0;
    clearRetryCard();
    document.getElementById(SERVICE_CARD_ID)?.remove();
};

/**
 * Build the card: the reason and a line that says which retry it is and the time left. The retries are made by
 * themselves, so the card has no buttons: the person decides only when they have run out.
 *
 * @param {string} reason What went wrong, in the words of the person's language.
 * @returns {{card: HTMLElement, status: HTMLElement}}
 */
const buildCard = (reason) => {
    const card = document.createElement('div');
    card.className = 'cg-log-entry cg-log-entry--turn-ai cg-log-entry--retrying';
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
    heading.textContent = reason;
    const status = document.createElement('span');
    status.className = 'd-block cg-retry-status';
    message.append(heading, status);
    content.append(message);
    card.append(bar, content);
    return {card, status};
};

/**
 * Show a retry that is coming, and wait for it.
 *
 * @param {Object} what
 * @param {number} what.attempt Number of the retry, from 1.
 * @param {number} what.total How many retries there are.
 * @param {number} what.seconds Seconds until it.
 * @param {boolean} [what.aiFailed] Whether what failed is the AI service and not the connection.
 * @returns {Promise<string>} Always 'retry'.
 */
export const waitBeforeRetry = async({attempt, total, seconds, aiFailed = false}) => {
    const [status, running, connectionReason, aiReason] = await getStrings(
        STRINGS.map((key) => ({key, component: 'local_coursegen'}))
    );
    clearRetryCard();
    // One retry on the screen at a time: the one of the page takes over from the one of the service, and goes on
    // counting where the service stopped, so the person reads one count and not two.
    pageRetrying = true;
    clearServiceRetry();
    const shownAttempt = serviceRetries + attempt;
    const shownTotal = serviceRetries + total;
    // The same words as the retry of the service, so the person reads one story while the AI is the one that fails.
    const view = buildCard(aiFailed ? aiReason : connectionReason);
    view.card.id = CARD_ID;
    const feed = currentFeed() || document.getElementById('courseaiChatScroll') || document.body;
    feed.append(view.card);
    view.card.scrollIntoView({block: 'nearest'});

    const stage = document.getElementById('tplGenStage');
    await countdown(seconds, (left) => {
        const line = status.replace('{$a->attempt}', shownAttempt).replace('{$a->total}', shownTotal).replace('{$a->seconds}', left);
        view.status.textContent = line;
        if (stage) {
            stage.textContent = line;
        }
    });

    view.status.textContent = running.replace('{$a->attempt}', shownAttempt).replace('{$a->total}', shownTotal);
    if (stage) {
        stage.textContent = view.status.textContent;
    }
    return 'retry';
};

/**
 * The service got through its retries (the AI answered again): the next failure starts counting from the first retry.
 */
export const serviceRecovered = () => {
    if (!pageRetrying) {
        serviceRetries = 0;
    }
    clearServiceRetry();
};

let serviceTurn = 0;
let serviceLinger = null;
let serviceCard = null;

/**
 * Take the card of the retry the service is making off the thread, if it is there.
 */
export const clearServiceRetry = () => {
    serviceTurn++;
    clearTimeout(serviceLinger);
    serviceCard?.remove();
    serviceCard = null;
};

/**
 * Show a retry that the service makes by itself when the AI provider fails: which retry it is, of how many, and the
 * time left for the next one, counting down. The service is the one that retries, so the card has no buttons.
 *
 * A newer retry replaces the card, the end of the retries takes it off, and one that nothing renews leaves by itself.
 *
 * @param {Object} data {attempt, of, seconds, status}
 * @returns {Promise<string>} The line shown.
 */
export const showServiceRetry = async(data) => {
    if (pageRetrying) {
        // The page is the one that retries now: what the service does inside each of its retries is not shown.
        return '';
    }
    const strings = await getStrings(STRINGS.map((key) => ({key, component: 'local_coursegen'})));
    const [status, running, , reasonText] = strings;
    clearServiceRetry();
    // And the other way round: when the service retries again, the card of the page is not left beside it.
    clearRetryCard();
    const turn = serviceTurn;
    // The person is told whose problem it is, never the status code of the provider.
    const view = buildCard(reasonText);
    view.card.id = SERVICE_CARD_ID;
    serviceCard = view.card;
    const feed = currentFeed() || document.getElementById('courseaiChatScroll') || document.body;
    feed.append(view.card);
    view.card.scrollIntoView({block: 'nearest'});

    // The service retries first and the page after it: one count for both, so the total is the two series together.
    serviceRetries = data.of || 0;
    const attempt = data.attempt || 0;
    const total = serviceRetries + ATTEMPTS;
    const stage = document.getElementById('tplGenStage');
    // The line under the title, the one in the status bar of the thread and the card say the same, every second.
    const say = (line) => {
        view.status.textContent = line;
        if (stage) {
            stage.textContent = line;
        }
        showWorkingIndicator({}, line);
    };
    const fill = (text, left) => text
        .replace('{$a->attempt}', attempt)
        .replace('{$a->total}', total)
        .replace('{$a->seconds}', left);
    const early = new Promise((resolve) => {
        const watch = setInterval(() => {
            if (turn !== serviceTurn) {
                clearInterval(watch);
                resolve();
            }
        }, 250);
    });
    const line = fill(status, data.seconds || 0);
    say(line);
    if (data.seconds) {
        countdown(data.seconds, (left) => say(fill(status, left)), {skip: early}).then(() => {
            if (turn !== serviceTurn) {
                return;
            }
            say(fill(running, 0));
            serviceLinger = setTimeout(() => {
                if (turn === serviceTurn) {
                    clearServiceRetry();
                }
            }, SERVICE_CARD_LINGER_SECONDS * 1000);
        });
    }
    return line;
};
