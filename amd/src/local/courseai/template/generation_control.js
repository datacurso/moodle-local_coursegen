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
 * Stop and resume of a template generation.
 *
 * A generation spends the license of the client for as long as the service keeps asking the AI, so stopping it has
 * to be certain: the stream is closed at once, and the service is then told to stop and confirms it. What the run
 * already made is kept, and resuming opens the stream again to make only what is missing.
 *
 * This module holds the state of that (running, stopping, stopped) and the two buttons of the header. It knows
 * nothing about streams: whoever runs a pass hands in the function that closes it. The buttons are the ones of free
 * mode (same classes), in a bar at the bottom of the left column where the composer would be.
 *
 * @module     local_coursegen/local/courseai/template/generation_control
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const STATE = {
    IDLE: 'idle',
    RUNNING: 'running',
    STOPPING: 'stopping',
    STOPPED: 'stopped',
};

/**
 * The state of the stop and resume of one run.
 *
 * @param {Function} onChange Called with the new state each time it changes.
 * @returns {Object} The control.
 */
export const createRunControl = (onChange = () => undefined) => {
    let state = STATE.IDLE;
    let closePass = null;
    let pendingStop = false;
    let resumeResolver = null;

    const move = (next) => {
        if (state === next) {
            return;
        }
        state = next;
        onChange(next);
    };

    return {
        /** @returns {string} One of STATE. */
        state: () => state,

        /** The run is going: the Stop button is offered. */
        begin: () => {
            pendingStop = false;
            move(STATE.RUNNING);
        },

        /**
         * A pass of the stream is open, and this is how it is closed.
         *
         * @param {Function} close Closes the pass and settles it as stopped.
         */
        attach: (close) => {
            closePass = close;
        },

        /** The pass ended by itself: there is nothing to close any more. */
        detach: () => {
            closePass = null;
        },

        /**
         * The person asked to stop.
         *
         * Before the stream exists the order is remembered and obeyed the moment it would open; once it exists it
         * is closed at once. Asking twice, or when nothing runs, does nothing.
         *
         * @returns {boolean} Whether the order was taken.
         */
        requestStop: () => {
            if (state !== STATE.RUNNING) {
                return false;
            }
            move(STATE.STOPPING);
            if (closePass) {
                const close = closePass;
                closePass = null;
                close();
            } else {
                pendingStop = true;
            }
            return true;
        },

        /**
         * Whether a stop was ordered before there was a stream to close; the order is spent by asking.
         *
         * @returns {boolean}
         */
        takePendingStop: () => {
            const was = pendingStop;
            pendingStop = false;
            return was;
        },

        /** The service confirmed (or the stop is final as far as the browser is concerned): Resume is offered. */
        markStopped: () => {
            closePass = null;
            move(STATE.STOPPED);
        },

        /**
         * Wait for the person to press Resume.
         *
         * @returns {Promise<void>} Resolves when they do.
         */
        waitForResume: () => new Promise((resolve) => {
            resumeResolver = resolve;
        }),

        /**
         * The person asked to resume.
         *
         * @returns {boolean} Whether the run was stopped and goes on now.
         */
        requestResume: () => {
            if (state !== STATE.STOPPED || !resumeResolver) {
                return false;
            }
            const resolve = resumeResolver;
            resumeResolver = null;
            move(STATE.RUNNING);
            resolve();
            return true;
        },

        /** The run is over, or waiting for the professor: no button is offered. */
        end: () => {
            closePass = null;
            pendingStop = false;
            resumeResolver = null;
            move(STATE.IDLE);
        },
    };
};

/**
 * Show the buttons the state calls for.
 *
 * @param {string} state One of STATE.
 * @param {Document} doc Where the header is.
 */
export const renderControl = (state, doc = document) => {
    const bar = doc.getElementById('tplRunBar');
    const stop = doc.getElementById('tplGenStop');
    const resume = doc.getElementById('tplGenResume');
    if (bar) {
        // The bar takes the place of the composer, which is away during the run: it shows only while there is a button.
        bar.hidden = state === STATE.IDLE;
    }
    if (stop) {
        stop.hidden = state !== STATE.RUNNING && state !== STATE.STOPPING;
        stop.disabled = state === STATE.STOPPING;
        stop.setAttribute('aria-busy', state === STATE.STOPPING ? 'true' : 'false');
        stop.dataset.state = state;
    }
    if (resume) {
        resume.hidden = state !== STATE.STOPPED;
    }
};

/**
 * Wire the two buttons to the control.
 *
 * @param {Object} control From createRunControl.
 * @param {Document} doc Where the header is.
 * @returns {Function} Takes the buttons off again.
 */
export const bindControlButtons = (control, doc = document) => {
    const stop = doc.getElementById('tplGenStop');
    const resume = doc.getElementById('tplGenResume');
    const onStop = () => control.requestStop();
    const onResume = () => control.requestResume();
    if (stop) {
        stop.addEventListener('click', onStop);
    }
    if (resume) {
        resume.addEventListener('click', onResume);
    }
    return () => {
        if (stop) {
            stop.removeEventListener('click', onStop);
        }
        if (resume) {
            resume.removeEventListener('click', onResume);
        }
    };
};
