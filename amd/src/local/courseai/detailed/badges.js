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
 * Image-count badge helpers for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/badges
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Format a localised image count string.
 *
 * @param {Object} ctx
 * @param {number} count
 * @returns {string}
 */
export const formatImageCount = (ctx, count) => {
    const {texts, formatTemplate} = ctx;
    if (count === 1) {
        return formatTemplate(texts.courseai_image_count_one, {count: 1});
    }
    return formatTemplate(texts.courseai_image_count_many, {count});
};

/**
 * Show or hide an image pill badge element based on count.
 *
 * @param {Object}      ctx
 * @param {HTMLElement} badgeEl
 * @param {number}      count
 */
export const setImageBadge = (ctx, badgeEl, count) => {
    if (!badgeEl) {
        return;
    }
    if (count > 0) {
        badgeEl.textContent = formatImageCount(ctx, count);
        badgeEl.style.display = 'inline-flex';
        return;
    }
    badgeEl.style.display = 'none';
};

/**
 * Recalculate and display the section-level image badge.
 *
 * @param {Object} ctx
 * @param {string} sectionId
 */
export const updateSectionImageBadge = (ctx, sectionId) => {
    const {state} = ctx;
    const meta = state.detailedSectionMeta[sectionId];
    if (!meta) {
        return;
    }
    const count = Object.values(state.detailedActivityEls).reduce((total, entry) => {
        if (entry.sectionId !== sectionId) {
            return total;
        }
        return total + (entry.imageCount || 0);
    }, 0);
    meta.imagesCount = count;
    setImageBadge(ctx, meta.imagesBadgeEl, count);
};

/**
 * Recount selected images for an entry and update badges.
 *
 * @param {Object}      ctx
 * @param {Object}      entry
 * @param {string}      sectionId
 */
export const recalculateEntryImageCount = (ctx, entry, sectionId) => {
    const {state} = ctx;
    if (!entry) {
        return;
    }
    const suggestions = Array.isArray(entry.imageSuggestions) ? entry.imageSuggestions : [];
    const activeSuggestions = suggestions.filter((suggestion) => !suggestion.deleted);
    const selectedCount = activeSuggestions.reduce((total, suggestion) => {
        const selected = state.selectedDetailedImages[suggestion.id] !== false;
        return total + (selected ? 1 : 0);
    }, 0);
    entry.imageCount = selectedCount;
    setImageBadge(ctx, entry.imageBadgeEl, selectedCount);
    updateSectionImageBadge(ctx, sectionId);
    updateDetailedHeaderStats(ctx);
};

/**
 * Placeholder header stats update — header subtitle is managed by stream.js.
 *
 * @param {Object} ctx
 */
export const updateDetailedHeaderStats = (ctx) => { // eslint-disable-line no-unused-vars
    // Stats tracking kept internally for image badge calculations.
    // Header subtitle is managed by stream.js status events.
};
