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
 * Resizable panel splitter for the courseai planning workspace.
 *
 * Wires a draggable divider between the context panel and the planning panel.
 * Width is persisted to localStorage and restored on next load.
 *
 * @module     local_coursegen/local/courseai/ui/splitter
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// v2: the width range/default changed (and old values were corrupted by a vw
// default the splitter could not parse), so ignore any pre-v2 persisted width.
const STORAGE_KEY = 'local_coursegen_left_w_v2';
const DEFAULT_W = 560;
const MIN_W = 320;
const MAX_W = 720;
const ARROW_STEP = 24;
const CSS_PROP = '--cg-left-w';
const RESIZING_CLASS = 'cg-resizing';

/**
 * Clamp a value between min and max.
 *
 * @param {number} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/**
 * Apply the left panel width and update ARIA state.
 *
 * @param {HTMLElement} workspace
 * @param {HTMLElement} divider
 * @param {number} width - pixel width, will be clamped
 */
const applyWidth = (workspace, divider, width) => {
    const clamped = clamp(width, MIN_W, MAX_W);
    workspace.style.setProperty(CSS_PROP, clamped + 'px');
    divider.setAttribute('aria-valuenow', String(clamped));
};

/**
 * Persist width to localStorage.
 *
 * @param {number} width
 */
const persistWidth = (width) => {
    try {
        localStorage.setItem(STORAGE_KEY, String(clamp(width, MIN_W, MAX_W)));
    } catch (e) {
        // Storage unavailable — ignore silently.
    }
};

/**
 * Read persisted width from localStorage.
 *
 * @returns {number|null}
 */
const readPersistedWidth = () => {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw === null) {
            return null;
        }
        const parsed = parseInt(raw, 10);
        return isNaN(parsed) ? null : parsed;
    } catch (e) {
        return null;
    }
};

/**
 * Initialize the resizable splitter.
 *
 * Attaches pointer and keyboard event listeners to the divider element so the
 * user can drag or keyboard-navigate to resize the left panel.
 *
 * @param {Object} options
 * @param {HTMLElement} options.workspace - The `.courseai-workspace` element.
 * @param {HTMLElement} options.divider   - The `.cg-splitter` element.
 */
export const createSplitter = ({workspace, divider}) => {
    if (!workspace || !divider) {
        return;
    }

    // Restore persisted width on init.
    const stored = readPersistedWidth();
    if (stored !== null) {
        applyWidth(workspace, divider, stored);
    }

    let startX = 0;
    let startWidth = 0;
    let latestX = 0;
    let rafId = null;
    let dragging = false;

    /**
     * Begin a drag interaction.
     *
     * @param {PointerEvent} e
     */
    const onPointerDown = (e) => {
        dragging = true;
        startX = e.clientX;
        startWidth = parseInt(
            getComputedStyle(workspace).getPropertyValue(CSS_PROP) || String(DEFAULT_W),
            10
        );
        divider.setPointerCapture(e.pointerId);
        workspace.classList.add(RESIZING_CLASS);
    };

    /**
     * Update width during drag — throttled to one RAF per frame.
     *
     * @param {PointerEvent} e
     */
    const onPointerMove = (e) => {
        if (!dragging) {
            return;
        }
        // Track the LATEST pointer position; the throttled frame below applies it.
        // (Using the captured event of the first move made the divider lag and
        // only "respond when it felt like it".)
        latestX = e.clientX;
        if (rafId !== null) {
            return;
        }
        rafId = requestAnimationFrame(() => {
            rafId = null;
            applyWidth(workspace, divider, startWidth + (latestX - startX));
        });
    };

    /**
     * End the drag interaction.
     *
     * @param {PointerEvent} e
     */
    const onPointerUp = (e) => {
        if (!dragging) {
            return;
        }
        dragging = false;
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        divider.releasePointerCapture(e.pointerId);
        workspace.classList.remove(RESIZING_CLASS);
        const current = parseInt(
            getComputedStyle(workspace).getPropertyValue(CSS_PROP) || String(DEFAULT_W),
            10
        );
        persistWidth(current);
    };

    /**
     * Reset to default width on double-click.
     */
    const onDblClick = () => {
        workspace.style.removeProperty(CSS_PROP);
        divider.setAttribute('aria-valuenow', String(DEFAULT_W));
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore.
        }
    };

    /**
     * Keyboard navigation — ArrowLeft/Right step, Home/End jump to limits.
     *
     * @param {KeyboardEvent} e
     */
    const onKeyDown = (e) => {
        const currentRaw = workspace.style.getPropertyValue(CSS_PROP);
        const current = currentRaw ? parseInt(currentRaw, 10) : DEFAULT_W;
        let next = current;

        if (e.key === 'ArrowLeft') {
            next = current - ARROW_STEP;
        } else if (e.key === 'ArrowRight') {
            next = current + ARROW_STEP;
        } else if (e.key === 'Home') {
            next = MIN_W;
        } else if (e.key === 'End') {
            next = MAX_W;
        } else {
            return;
        }

        e.preventDefault();
        applyWidth(workspace, divider, next);
        persistWidth(clamp(next, MIN_W, MAX_W));
    };

    divider.addEventListener('pointerdown', onPointerDown);
    divider.addEventListener('pointermove', onPointerMove);
    divider.addEventListener('pointerup', onPointerUp);
    divider.addEventListener('pointercancel', onPointerUp);
    divider.addEventListener('dblclick', onDblClick);
    divider.addEventListener('keydown', onKeyDown);
};
