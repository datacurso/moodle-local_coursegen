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
 * Collapsed max-height (px) for a turn body before the fade + "show more"
 * control kicks in. A turn is only truncated when its real content height
 * (scrollHeight) exceeds this — short turns stay fully visible.
 */
const TURN_MAX_HEIGHT = 160;

/** "Show more" / "Show less" labels (kept here so the toggle is self-contained). */
const TOGGLE_MORE = 'Show more';
const TOGGLE_LESS = 'Show less';

/**
 * Build the expand/collapse chevron control for a long turn.
 *
 * @param {string} label - Visible label ("Show more").
 * @returns {HTMLButtonElement}
 */
const buildToggle = (label) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cg-log-toggle';
    btn.setAttribute('aria-expanded', 'false');
    const text = document.createElement('span');
    text.className = 'cg-log-toggle-text';
    text.textContent = label;
    const chevron = document.createElement('span');
    chevron.className = 'cg-log-toggle-chevron';
    chevron.setAttribute('aria-hidden', 'true');
    chevron.textContent = '⌄';
    btn.appendChild(text);
    btn.appendChild(chevron);
    return btn;
};

/**
 * Attach long-message fade + expand behaviour to a turn body, but only when the
 * content actually overflows the collapsed height (§7.1). Detection is deferred
 * to the next frame so layout (line wrapping) has settled before measuring.
 *
 * @param {HTMLElement} entry - The turn wrapper (receives the toggle).
 * @param {HTMLElement} msgEl - The text body to clamp.
 * @returns {void}
 */
const wireFadeExpand = (entry, msgEl) => {
    window.requestAnimationFrame(() => {
        if (msgEl.scrollHeight <= TURN_MAX_HEIGHT + 4) {
            return;
        }
        entry.classList.add('cg-log-entry--clamped');
        const toggle = buildToggle(TOGGLE_MORE);
        toggle.addEventListener('click', () => {
            const expanded = entry.classList.toggle('is-expanded');
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const textEl = toggle.querySelector('.cg-log-toggle-text');
            if (textEl) {
                textEl.textContent = expanded ? TOGGLE_LESS : TOGGLE_MORE;
            }
        });
        entry.appendChild(toggle);
    });
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
        const isUser = actor === 'user';

        // Each entry is a chat TURN (§7.1): user turns get a faint inset/border so
        // you can tell who spoke; AI turns stay flat with their ✨ icon. The single
        // thread is preserved — no opposed bubbles.
        const entry = document.createElement('div');
        entry.className = 'cg-log-entry ' + kindClass
            + (isUser ? ' cg-log-entry--turn-user' : ' cg-log-entry--turn-ai');
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

        // The message text lives in its own clampable element so the fade + expand
        // control can measure and toggle just the body (not the icon/timestamp).
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
        wireFadeExpand(entry, msgSpan);
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
