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
 * Waits until the course page stops changing.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-state-settle
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Milliseconds without a change after which the page counts as quiet. */
const QUIET_MS = 500;

/** Milliseconds after which a page that keeps changing is not waited for any longer. */
const MAX_WAIT_MS = 3500;

/**
 * One wait for a page to be quiet.
 */
class QuietWatch {
    /**
     * @param {Object} target Where the changes are watched.
     * @param {Object} options Observer, schedule, cancel, quietMs and maxMs.
     * @param {Function} resolve Called once, when the wait is over.
     */
    constructor(target, options, resolve) {
        this.target = target;
        this.options = options;
        this.resolve = resolve;
        this.quietTimer = null;
        this.maxTimer = null;
        this.observer = null;
        this.finish = this.finish.bind(this);
        this.onChange = this.onChange.bind(this);
    }

    /**
     * Start watching and waiting.
     *
     * @returns {void}
     */
    begin() {
        const Observer = this.options.Observer;
        const schedule = this.options.schedule;
        this.observer = new Observer(this.onChange);
        this.observer.observe(this.target, {childList: true, subtree: true, attributes: true, characterData: true});
        this.restartQuiet();
        this.maxTimer = schedule(this.finish, this.options.maxMs);
    }

    /**
     * A change happened: the quiet time starts again.
     *
     * @returns {void}
     */
    onChange() {
        this.restartQuiet();
    }

    /**
     * Wait for the quiet time from now.
     *
     * @returns {void}
     */
    restartQuiet() {
        const schedule = this.options.schedule;
        const cancel = this.options.cancel;
        if (this.quietTimer !== null) {
            cancel(this.quietTimer);
        }
        this.quietTimer = schedule(this.finish, this.options.quietMs);
    }

    /**
     * The wait is over: stop watching and let the caller go on.
     *
     * @returns {void}
     */
    finish() {
        const cancel = this.options.cancel;
        if (this.quietTimer !== null) {
            cancel(this.quietTimer);
        }
        if (this.maxTimer !== null) {
            cancel(this.maxTimer);
        }
        this.quietTimer = null;
        this.maxTimer = null;
        this.observer.disconnect();
        this.resolve();
    }
}

/**
 * Wait until nothing under the target changes for a while, or for the maximum time when it keeps changing.
 *
 * @param {Object|null} target Where the changes are watched, normally the workspace of the page.
 * @param {Object} [options]
 * @param {Function} [options.Observer] The MutationObserver class.
 * @param {Function} [options.schedule] Runs a callback later, like setTimeout.
 * @param {Function} [options.cancel] Cancels what schedule scheduled, like clearTimeout.
 * @param {number} [options.quietMs] Milliseconds without a change that make the page quiet.
 * @param {number} [options.maxMs] Longest wait.
 * @returns {Promise<void>}
 */
export const waitForQuiet = (target, options = {}) => {
    if (!target) {
        return Promise.resolve();
    }
    const settings = {
        Observer: options.Observer || MutationObserver,
        schedule: options.schedule || setTimeout,
        cancel: options.cancel || clearTimeout,
        quietMs: options.quietMs || QUIET_MS,
        maxMs: options.maxMs || MAX_WAIT_MS,
    };
    return new Promise((resolve) => {
        const watch = new QuietWatch(target, settings, resolve);
        watch.begin();
    });
};
