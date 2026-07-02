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
 * Visual feedback helpers — semantic highlight flash and collapse-and-fade.
 *
 * Purely additive; touches no existing selectors or behaviours.
 * Relies on CSS classes and tokens defined in styles/aicoursecreation.css
 * (ui-refactor §7 + §8).
 *
 * @module     local_coursegen/local/courseai/ui/highlight
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Duration (ms) that a mark class stays before being removed. Matches --hold-mark. */
const HOLD_MARK_MS = 1200;

/** Duration (ms) for the collapse-and-fade transition. Matches --t-slow. */
const REMOVING_MS = 340;

/** Valid mark kinds mapped to their CSS class suffix. */
const VALID_KINDS = ['danger', 'info', 'success'];

/**
 * Per-element timer handles so rapid re-marks cancel the previous removal timer.
 *
 * @type {WeakMap<Element, ReturnType<setTimeout>>}
 */
const markTimers = new WeakMap();

/**
 * Remove all cg-mark-* classes from an element.
 *
 * @param {Element} el
 */
const clearMarkClasses = (el) => {
    VALID_KINDS.forEach((kind) => el.classList.remove('cg-mark-' + kind));
};

/**
 * Scroll the element into view and flash a temporary semantic highlight.
 *
 * The mark class is removed after HOLD_MARK_MS milliseconds. Calling this
 * again before the timer fires cancels the previous timer so only one removal
 * is ever scheduled per element.
 *
 * @param {Element|null} el  Target element. No-op when falsy.
 * @param {'danger'|'info'|'success'} kind  Semantic variant.
 * @returns {void}
 */
export const focusChange = (el, kind) => {
    if (!el) {
        return;
    }

    el.scrollIntoView({behavior: 'smooth', block: 'center'});

    clearMarkClasses(el);

    const existingTimer = markTimers.get(el);
    if (existingTimer !== undefined) {
        clearTimeout(existingTimer);
    }

    el.classList.add('cg-mark-' + kind);

    const timer = setTimeout(() => {
        clearMarkClasses(el);
        markTimers.delete(el);
    }, HOLD_MARK_MS);

    markTimers.set(el, timer);
};

/**
 * Collapse and fade an element, resolving once it can be safely removed.
 *
 * Adds the `.cg-removing` class which triggers the CSS transition defined in
 * aicoursecreation.css, then resolves the returned Promise after REMOVING_MS
 * so the caller can detach the node without a visible pop.
 *
 * @param {Element} el  Element to collapse.
 * @returns {Promise<void>}  Resolves when the transition is complete.
 */
export const markRemoving = (el) => {
    el.classList.add('cg-removing');
    return new Promise((resolve) => {
        setTimeout(resolve, REMOVING_MS);
    });
};
