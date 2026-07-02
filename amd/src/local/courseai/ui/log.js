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
 * Create a decision-log feed.
 *
 * The feed is chronological: planning-phase entries land in `container` (above the
 * section checklist), and once the plan has settled (isActionPhase() === true) user
 * actions land in `actionContainer` (below the checklist) so everything flows down
 * like an organic chat. Each new entry is scrolled into view so the newest sits by
 * the input.
 *
 * @param {Object} options
 * @param {HTMLElement} options.container        - Planning-phase log container (above checklist).
 * @param {HTMLElement} [options.actionContainer] - Post-settle log container (below checklist).
 * @param {Function}    [options.isActionPhase]   - Returns true once action entries belong below the checklist.
 * @returns {{ add: Function, clear: Function }}
 */
export const createLog = ({container, actionContainer, isActionPhase}) => {
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
    /**
     * Pick the chronological target: below the checklist once the plan has settled.
     *
     * @returns {HTMLElement|null}
     */
    const resolveTarget = () => {
        const useAction = typeof isActionPhase === 'function' && isActionPhase();
        if (useAction && actionContainer) {
            return actionContainer;
        }
        return container;
    };

    const add = ({actor, kind, message}) => {
        const target = resolveTarget();
        if (!target) {
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

        target.appendChild(entry);
        // Pin the feed to the bottom so the newest entry sits next to the input. Defer
        // to the next frame: a fresh entry may wrap to several lines, so its height is
        // not laid out yet on the synchronous append. scrollIntoView on the entry is
        // more reliable than scrollTop math when sibling heights change.
        window.requestAnimationFrame(() => {
            entry.scrollIntoView({block: 'nearest', inline: 'nearest'});
        });

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
        if (actionContainer) {
            actionContainer.innerHTML = '';
        }
        entries.length = 0;
        if (tickerId !== null) {
            clearInterval(tickerId);
            tickerId = null;
        }
    };

    return {add, clear};
};
