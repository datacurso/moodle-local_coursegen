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
 * Image counting and completion statistics for generated course activities.
 *
 * @module     local_coursegen/local/courseai/stream/completion
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Recursively collect all string values from a nested structure.
 *
 * @param {*} value
 * @param {string[]} output
 */
export const collectStringValues = (value, output) => {
    if (typeof value === 'string') {
        output.push(value);
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item) => collectStringValues(item, output));
        return;
    }

    if (value && typeof value === 'object') {
        Object.values(value).forEach((item) => collectStringValues(item, output));
    }
};

/**
 * Normalize a raw image source string: strip quotes and unescape slashes.
 *
 * @param {string} source
 * @returns {string}
 */
export const normalizeImageSource = (source) => {
    if (!source || typeof source !== 'string') {
        return '';
    }

    return source
        .trim()
        .replace(/^['"]|['"]$/g, '')
        .replace(/\\\//g, '/');
};

/**
 * Count distinct image references in an activity payload.
 *
 * @param {*} activityPayload
 * @returns {number}
 */
export const countImagesInActivityPayload = (activityPayload) => {
    const stringValues = [];
    collectStringValues(activityPayload || {}, stringValues);
    if (stringValues.length === 0) {
        return 0;
    }

    const imageSources = new Set();
    let fallbackImgTagCount = 0;

    stringValues.forEach((value) => {
        if (!value) {
            return;
        }

        const htmlImagePattern = /<img\b[^>]*\bsrc\s*=\s*(["'])(.*?)\1/gi;
        let htmlMatch = htmlImagePattern.exec(value);
        while (htmlMatch) {
            const normalized = normalizeImageSource(htmlMatch[2]);
            if (normalized) {
                imageSources.add(normalized);
            }
            htmlMatch = htmlImagePattern.exec(value);
        }

        const markdownImagePattern = /!\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g;
        let markdownMatch = markdownImagePattern.exec(value);
        while (markdownMatch) {
            const normalized = normalizeImageSource(markdownMatch[1]);
            if (normalized) {
                imageSources.add(normalized);
            }
            markdownMatch = markdownImagePattern.exec(value);
        }

        const generatedPathPattern = /\/tmp\/resource_files\/generated_images\/[a-z0-9._-]+/gi;
        const generatedPaths = value.match(generatedPathPattern) || [];
        generatedPaths.forEach((path) => {
            const normalized = normalizeImageSource(path);
            if (normalized) {
                imageSources.add(normalized);
            }
        });

        if (imageSources.size === 0) {
            const looseHtmlMatches = value.match(/<img\s+[^>]*src=/gi) || [];
            fallbackImgTagCount += looseHtmlMatches.length;
        }
    });

    return imageSources.size > 0 ? imageSources.size : fallbackImgTagCount;
};

/**
 * Compute and store completion statistics on state from a generated activities array.
 *
 * @param {Object} state
 * @param {Array} generatedActivities
 */
export const setCompletionStatsFromGeneratedResult = (state, generatedActivities) => {
    if (!Array.isArray(generatedActivities) || generatedActivities.length === 0) {
        return;
    }

    const sectionIndexes = new Set();
    let generatedImageCount = 0;
    generatedActivities.forEach((activity) => {
        const rawSection = activity?.parameters?.section;
        const parsedSection = Number(rawSection);
        if (!Number.isNaN(parsedSection)) {
            sectionIndexes.add(parsedSection);
        }

        generatedImageCount += countImagesInActivityPayload(activity);
    });

    const selectedImages = Object.keys(state.selectedDetailedImages || {})
        .filter((id) => state.selectedDetailedImages[id] !== false).length;

    const finalImageCount = generatedImageCount > 0 ? generatedImageCount : selectedImages;

    state.completionStats = {
        units: sectionIndexes.size
            || state.totalSections
            || Object.keys(state.detailedSectionMeta || {}).length
            || (generatedActivities.length > 0 ? 1 : 0),
        activities: generatedActivities.length,
        images: finalImageCount,
    };
};
