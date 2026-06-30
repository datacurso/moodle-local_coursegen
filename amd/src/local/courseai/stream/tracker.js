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
 * Generation tracker data management for the SSE stream.
 *
 * @module     local_coursegen/local/courseai/stream/tracker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build the initial tracker data structure from latestInitialSections.
 *
 * @param {Object} state
 * @param {Object} texts
 * @returns {{sections: Array, flat: Array, currentIndex: number}}
 */
export const createGenerationTracker = (state, texts) => {
    const sourceSections = Array.isArray(state.latestInitialSections)
        ? state.latestInitialSections
        : [];

    const sections = sourceSections
        .filter((section) => !section?.deleted)
        .map((section, sectionIndex) => {
            const activities = (Array.isArray(section.activities) ? section.activities : [])
                .filter((activity) => !activity?.deleted);

            return {
                index: sectionIndex,
                name: section.name || `${texts.courseai_section_label} ${sectionIndex + 1}`,
                activities: activities.map((activity, activityIndex) => ({
                    sectionIndex,
                    activityIndex,
                    id: activity.id,
                    title: activity.title
                        || activity.name
                        || `${texts.courseai_activity_default} ${activityIndex + 1}`,
                    type: (activity.activity_type || activity.type || 'page').toLowerCase(),
                    status: 'pending',
                    imageDone: 0,
                    imageTotal: 0,
                })),
            };
        });

    const flat = [];
    sections.forEach((section) => {
        section.activities.forEach((activity) => flat.push(activity));
    });

    return {
        sections,
        flat,
        currentIndex: -1,
    };
};

/**
 * Find the index of the next pending activity starting from startFrom.
 *
 * @param {Object} state
 * @param {number} startFrom
 * @returns {number}
 */
export const findNextPendingIndex = (state, startFrom = 0) => {
    const tracker = state.generationTracker;
    if (!tracker || !Array.isArray(tracker.flat)) {
        return -1;
    }
    for (let idx = Math.max(0, startFrom); idx < tracker.flat.length; idx++) {
        if (tracker.flat[idx].status === 'pending') {
            return idx;
        }
    }
    return -1;
};

/**
 * Mark the flat activity at index as done.
 *
 * @param {Object} state
 * @param {number} index
 */
export const markTrackerActivityDone = (state, index) => {
    const tracker = state.generationTracker;
    if (!tracker || index < 0 || index >= tracker.flat.length) {
        return;
    }
    tracker.flat[index].status = 'done';
};

/**
 * Update the status of an activity identified by section/activity coordinates.
 *
 * @param {Object} state
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {string} status
 */
export const updateTrackerActivityStatusByCoordinates = (state, sectionIndex, activityIndex, status) => {
    const tracker = state.generationTracker;
    if (!tracker || !Array.isArray(tracker.sections) || !tracker.sections[sectionIndex]) {
        return;
    }

    const section = tracker.sections[sectionIndex];
    if (!Array.isArray(section.activities) || !section.activities[activityIndex]) {
        return;
    }

    section.activities[activityIndex].status = status;
};

/**
 * Mark every tracked activity as done and reset the current index.
 *
 * @param {Object} state
 * @param {Function} renderTracker - callback to re-render after mutation
 */
export const markAllTrackerActivitiesDone = (state, renderTracker) => {
    const tracker = state.generationTracker;
    if (!tracker || !Array.isArray(tracker.flat)) {
        return;
    }
    tracker.flat.forEach((activity) => {
        activity.status = 'done';
    });
    tracker.currentIndex = -1;
    renderTracker();
};

/**
 * Update image progress counts for a specific activity in the tracker.
 *
 * @param {Object} state
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {number} done
 * @param {number} total
 */
export const updateTrackerImageProgress = (state, sectionIndex, activityIndex, done, total) => {
    const tracker = state.generationTracker;
    if (!tracker || !Array.isArray(tracker.sections) || !tracker.sections[sectionIndex]) {
        return;
    }

    const section = tracker.sections[sectionIndex];
    if (!section.activities || !section.activities[activityIndex]) {
        return;
    }

    const activity = section.activities[activityIndex];
    const safeTotal = Math.max(0, Number(total) || 0);
    const safeDone = Math.max(0, Math.min(Number(done) || 0, safeTotal));
    activity.imageTotal = safeTotal;
    activity.imageDone = safeDone;
};

/**
 * Sum all image progress across all tracked activities.
 *
 * @param {Object} state
 * @returns {{done: number, total: number}}
 */
export const getTrackerImagesProgress = (state) => {
    const tracker = state.generationTracker;
    if (!tracker || !Array.isArray(tracker.flat)) {
        return {done: 0, total: 0};
    }

    return tracker.flat.reduce((acc, activity) => {
        const total = Math.max(0, Number(activity.imageTotal) || 0);
        const done = Math.max(0, Math.min(Number(activity.imageDone) || 0, total));
        return {
            done: acc.done + done,
            total: acc.total + total,
        };
    }, {done: 0, total: 0});
};

/**
 * Update the progress bar based on structured activity/image progress state.
 *
 * @param {Object} state
 * @param {Object} stepsUi
 */
export const updateGeneratingProgressFromStructuredState = (state, stepsUi) => {
    if (state.currentStage !== 'generating') {
        return;
    }

    const totalActivities = Math.max(0, Number(state.activityProgressTotal) || 0);
    const doneActivities = Math.max(0, Math.min(Number(state.activityProgressDone) || 0, totalActivities));

    if (totalActivities <= 0) {
        return;
    }

    if (doneActivities < totalActivities) {
        const activityProgress = Math.round((doneActivities / totalActivities) * 89);
        stepsUi.setProgress(Math.max(0, Math.min(89, activityProgress)));
        return;
    }

    const imageTotals = getTrackerImagesProgress(state);
    state.imageProgressDone = imageTotals.done;
    state.imageProgressTotal = imageTotals.total;

    if (imageTotals.total > 0) {
        const imageProgress = 90 + Math.round((imageTotals.done / imageTotals.total) * 9);
        stepsUi.setProgress(Math.max(90, Math.min(99, imageProgress)));
        return;
    }

    stepsUi.setProgress(90);
};
