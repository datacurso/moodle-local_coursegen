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
 * Decision log — append-only chronological log of user/AI/system events.
 *
 * @module     local_coursegen/local/courseai/ui/log
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Map kind → CSS modifier class applied to the left color bar. */
const KIND_CLASS = {
    user:    'cg-log-entry--user',
    ai:      'cg-log-entry--ai',
    danger:  'cg-log-entry--danger',
    info:    'cg-log-entry--info',
    success: 'cg-log-entry--success',
    neutral: 'cg-log-entry--neutral',
};

/** Actor icon rendered before the message text. */
const ACTOR_ICON = {
    user:    '👤',
    ai:      '✨',
    system:  '⚙️',
};

/**
 * Format a relative timestamp string.
 *
 * @param {number} createdAt - ms since epoch at entry creation.
 * @returns {string}
 */
const formatRelative = (createdAt) => {
    const delta = Math.round((Date.now() - createdAt) / 1000);
    if (delta < 5) {
        return 'now';
    }
    if (delta < 60) {
        return delta + 's';
    }
    const mins = Math.round(delta / 60);
    return mins + 'm';
};

/**
 * Create a decision-log attached to `container`.
 *
 * @param {Object} options
 * @param {HTMLElement} options.container - The scrollable log container element.
 * @returns {{ add: Function, clear: Function }}
 */
export const createLog = ({container}) => {
    /** @type {Array<{el: HTMLElement, createdAt: number}>} */
    const entries = [];

    let tickerId = null;

    /**
     * Update all visible relative timestamps every 10 s.
     */
    const startTicker = () => {
        if (tickerId !== null) {
            return;
        }
        tickerId = setInterval(() => {
            entries.forEach(({el, createdAt}) => {
                const tsEl = el.querySelector('.cg-log-ts');
                if (tsEl) {
                    tsEl.textContent = formatRelative(createdAt);
                }
            });
        }, 10000);
    };

    /**
     * Append a log entry to the container.
     *
     * @param {Object} params
     * @param {string} params.actor   - 'user' | 'ai' | 'system'
     * @param {string} params.kind    - 'user'|'ai'|'danger'|'info'|'success'|'neutral'
     * @param {string} params.message - Visible message text.
     */
    const add = ({actor, kind, message}) => {
        if (!container) {
            return;
        }

        const createdAt = Date.now();
        const kindClass = KIND_CLASS[kind] || KIND_CLASS.neutral;
        const icon = ACTOR_ICON[actor] || ACTOR_ICON.system;

        const entry = document.createElement('div');
        entry.className = 'cg-log-entry ' + kindClass;
        entry.setAttribute('role', 'status');

        const bar = document.createElement('span');
        bar.className = 'cg-log-bar';
        bar.setAttribute('aria-hidden', 'true');

        const body = document.createElement('span');
        body.className = 'cg-log-body';

        const iconSpan = document.createElement('span');
        iconSpan.className = 'cg-log-actor';
        iconSpan.setAttribute('aria-hidden', 'true');
        iconSpan.textContent = icon;

        const msgSpan = document.createElement('span');
        msgSpan.className = 'cg-log-msg';
        msgSpan.textContent = message;

        const tsSpan = document.createElement('span');
        tsSpan.className = 'cg-log-ts';
        tsSpan.textContent = 'now';

        body.appendChild(iconSpan);
        body.appendChild(msgSpan);
        body.appendChild(tsSpan);

        entry.appendChild(bar);
        entry.appendChild(body);

        container.appendChild(entry);
        container.scrollTop = container.scrollHeight;

        entries.push({el: entry, createdAt});
        startTicker();
    };

    /**
     * Remove all entries from the container and reset state.
     */
    const clear = () => {
        if (container) {
            container.innerHTML = '';
        }
        entries.length = 0;
        if (tickerId !== null) {
            clearInterval(tickerId);
            tickerId = null;
        }
    };

    return {add, clear};
};
