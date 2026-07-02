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
 * DOM renderer for the generation tracker panel.
 *
 * @module     local_coursegen/local/courseai/stream/tracker-renderer
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const STATUS_INDICATOR_HTML = '<svg class="spinner-icon" viewBox="0 0 24 24">'
    + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path></svg>'
    + '<svg class="check-icon" viewBox="0 0 24 24">'
    + '<polyline points="20 6 9 17 4 12"></polyline></svg>';

/**
 * Build and append the DOM element for a single activity row.
 *
 * @param {Object} activity
 * @param {Object} texts
 * @param {Function} getActivityLabel
 * @returns {HTMLElement}
 */
const buildActivityItem = (activity, texts, getActivityLabel) => {
    const itemEl = document.createElement('li');
    itemEl.className = `ps-activity ps-activity--${activity.status}`;

    if (activity.status === 'pending') {
        const skeleton = document.createElement('span');
        skeleton.className = 'ps-skeleton-line ps-skeleton-line--activity';
        skeleton.setAttribute('aria-hidden', 'true');
        itemEl.appendChild(skeleton);
        return itemEl;
    }

    const statusIndicator = document.createElement('span');
    statusIndicator.className = `ps-status-indicator ps-status-indicator--${activity.status}`;
    statusIndicator.setAttribute('aria-hidden', 'true');
    statusIndicator.innerHTML = STATUS_INDICATOR_HTML;

    const badgeEl = document.createElement('span');
    badgeEl.className = `ps-badge ps-badge--${activity.type}`;

    const badgeTextEl = document.createElement('span');
    badgeTextEl.className = 'ps-badge-text';
    badgeTextEl.textContent = getActivityLabel(activity.type, texts);
    badgeEl.appendChild(badgeTextEl);

    const activityInfo = document.createElement('div');
    activityInfo.className = 'ps-activity-info';

    const activityName = document.createElement('span');
    activityName.className = 'ps-activity-name';
    activityName.textContent = activity.title;
    activityInfo.appendChild(activityName);

    if (activity.imageTotal > 0) {
        const imageProgressTag = document.createElement('span');
        imageProgressTag.className = 'ps-image-progress';
        imageProgressTag.textContent = (
            `${activity.imageDone}/${activity.imageTotal} ${texts.courseai_images_label}`
        );
        activityInfo.appendChild(imageProgressTag);
    }

    itemEl.appendChild(statusIndicator);
    itemEl.appendChild(badgeEl);
    itemEl.appendChild(activityInfo);
    return itemEl;
};

/**
 * Build and append the DOM element for a single section block.
 *
 * @param {Object} section
 * @param {number} sectionIdx
 * @param {Object} texts
 * @param {Function} getActivityLabel
 * @returns {HTMLElement}
 */
const buildSectionBlock = (section, sectionIdx, texts, getActivityLabel) => {
    const sectionEl = document.createElement('div');
    sectionEl.className = 'ps-section';

    const doneCount = section.activities.filter((a) => a.status === 'done').length;
    const inProgressCount = section.activities.filter((a) => a.status === 'in_progress').length;
    const totalCount = section.activities.length;

    if (doneCount === 0 && inProgressCount === 0) {
        sectionEl.classList.add('ps-section--pending');
    } else if (inProgressCount > 0) {
        sectionEl.classList.add('ps-section--in_progress');
    } else {
        sectionEl.classList.add('ps-section--done');
    }

    const headEl = document.createElement('div');
    headEl.className = 'ps-section-head';

    const numEl = document.createElement('span');
    numEl.className = 'ps-section-num';
    numEl.textContent = String(sectionIdx + 1).padStart(2, '0');

    const infoEl = document.createElement('div');
    infoEl.className = 'ps-section-info';

    const nameEl = document.createElement('p');
    nameEl.className = 'ps-section-name';
    if (doneCount === 0 && inProgressCount === 0) {
        const sectionSkeleton = document.createElement('span');
        sectionSkeleton.className = 'ps-skeleton-line ps-skeleton-line--section';
        sectionSkeleton.setAttribute('aria-hidden', 'true');
        nameEl.appendChild(sectionSkeleton);
    } else {
        nameEl.textContent = section.name;
    }
    infoEl.appendChild(nameEl);

    const countEl = document.createElement('span');
    countEl.className = 'ps-section-count';
    countEl.textContent = `${doneCount}/${totalCount}`;

    headEl.appendChild(numEl);
    headEl.appendChild(infoEl);
    headEl.appendChild(countEl);

    const listEl = document.createElement('ul');
    listEl.className = 'ps-activities';

    section.activities.forEach((activity) => {
        listEl.appendChild(buildActivityItem(activity, texts, getActivityLabel));
    });

    sectionEl.appendChild(headEl);
    sectionEl.appendChild(listEl);
    return sectionEl;
};

/**
 * Re-render the entire generation tracker panel from state.
 *
 * @param {Object} state
 * @param {HTMLElement|null} pcDetailsPanel
 * @param {Object} texts
 * @param {Function} getActivityLabel
 */
export const renderGenerationTracker = (state, pcDetailsPanel, texts, getActivityLabel) => {
    if (!pcDetailsPanel || !state.generationTracker) {
        return;
    }

    const tracker = state.generationTracker;
    pcDetailsPanel.innerHTML = '';

    tracker.sections.forEach((section, sectionIdx) => {
        pcDetailsPanel.appendChild(buildSectionBlock(section, sectionIdx, texts, getActivityLabel));
    });
};
