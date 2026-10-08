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
 * What the page shows while the AI works on a long call: one line right after the steps of the feed that counts the
 * seconds in place, the paused state that stops every spinner while the AI waits for an answer, and the settled
 * state that turns the last step into a check once the run is over. The line is only ever the latest tick, so a
 * reload shows nothing stale and the page never accumulates one line per tick.
 *
 * @module     local_coursegen/local/courseai/template/generation_waiting
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const LINE_ID = 'cgWaitingLine';
const LINE_CLASS = 'cg-waiting-line';
const PAUSED_CLASS = 'cg-generation-paused';
const SETTLED_CLASS = 'cg-generation-settled';
const WAITING_STRING = 'template_agent_waiting';

let queue = Promise.resolve();

/**
 * Run a change after every earlier one has finished, so a tick never lands after the clear that follows it.
 *
 * @param {Function} task
 */
const enqueue = (task) => {
    queue = queue.then(task).catch(() => undefined);
};

/**
 * The line that counts the seconds, created right after the steps of the feed the first time it is needed.
 *
 * @returns {Element|null} The line, or null when the page has no place for it.
 */
const lineNode = () => {
    const existing = document.getElementById(LINE_ID);
    if (existing) {
        return existing;
    }
    const anchor = document.getElementById('cgLog');
    if (!anchor) {
        return null;
    }
    const line = document.createElement('div');
    line.id = LINE_ID;
    line.className = LINE_CLASS;
    line.setAttribute('role', 'status');
    anchor.insertAdjacentElement('afterend', line);
    return line;
};

/**
 * Show that the AI has been waiting on a call for some seconds; the same line is updated in place.
 *
 * @param {number} seconds Whole seconds the call has been in flight.
 */
export const showWaiting = (seconds) => {
    enqueue(async() => {
        const text = await getString(WAITING_STRING, 'local_coursegen', seconds);
        const line = lineNode();
        if (line) {
            line.textContent = text;
        }
    });
};

/**
 * Take the waiting line away, because the call ended or the run stopped.
 */
export const clearWaiting = () => {
    enqueue(() => {
        const line = document.getElementById(LINE_ID);
        if (line) {
            line.remove();
        }
    });
};

/**
 * Stop or resume the spinners: they stop while the AI waits for the answer of the professor.
 *
 * @param {boolean} paused True while a question is open.
 */
export const setPaused = (paused) => {
    document.body.classList.toggle(PAUSED_CLASS, paused);
};

/**
 * Mark the run as over or as going on: once it is over the last step of the feed shows a check, not a spinner.
 *
 * @param {boolean} settled True once the run has completed.
 */
export const setSettled = (settled) => {
    document.body.classList.toggle(SETTLED_CLASS, settled);
};
