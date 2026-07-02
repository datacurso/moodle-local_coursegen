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
 * Planning-panel DOM-render helpers — sections and activities.
 *
 * @module     local_coursegen/local/courseai/planning/render
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Append a new plan section card to the phase-2 planning list.
 *
 * ctx must contain: state, elements, activityLabels,
 *   getActivityIconUrl, escapeHtml, texts, formatTemplate, setProgress
 *
 * @param {Object} section
 * @param {Object} ctx
 */
export const addPlanSection = (section, ctx) => {
    const {state, elements, activityLabels, getActivityIconUrl, escapeHtml, texts, formatTemplate, setProgress} = ctx;
    const {planSectionsList, pcStep, pcSubtitle, pcToggleRow} = elements;

    if (!section || !planSectionsList) {
        return;
    }

    const activities = Array.isArray(section.activities) ? section.activities : [];
    state.totalSections += 1;
    state.totalActivities += activities.length;

    if (pcStep) {
        pcStep.textContent = formatTemplate(texts.courseai_plan_counter, {
            sections: state.totalSections,
            activities: state.totalActivities,
        });
    }
    if (pcSubtitle) {
        pcSubtitle.textContent = formatTemplate(texts.courseai_plan_adding, {
            name: section.name || '',
        });
    }
    const estimatedPct = Math.min(90, (state.totalActivities / (state.totalActivities + 6)) * 100);
    setProgress(estimatedPct);

    if (state.totalSections === 1 && pcToggleRow) {
        pcToggleRow.style.display = 'flex';
    }

    const sectionEl = document.createElement('div');
    sectionEl.className = 'ps-section';
    sectionEl.innerHTML = `
        <div class="ps-section-head">
            <span class="ps-section-num">${state.totalSections}</span>
            <div class="ps-section-info">
                <h3 class="ps-section-name">${escapeHtml(section.name || '')}</h3>
                <p class="ps-section-desc">${escapeHtml(section.description || '')}</p>
            </div>
            <span class="ps-section-count">${activities.length} ${texts.courseai_activities_count}</span>
        </div>
        <ul class="ps-activities">
            ${activities.map((activity) => {
                const activityType = activity.type || 'resource';
                const iconUrl = getActivityIconUrl(activityType);
                return `
                <li class="ps-activity">
                    <span class="ps-badge ps-badge--${escapeHtml(activityType)}">
                        <img src="${iconUrl}"
                             class="ps-badge-icon"
                             alt=""
                             onerror="this.style.display='none'">
                        <span class="ps-badge-text">
                            ${escapeHtml(activityLabels[activityType] || activityType || texts.courseai_activity_default)}
                        </span>
                    </span>
                    <div class="ps-activity-info">
                        <span class="ps-activity-name">${escapeHtml(activity.name || '')}</span>
                        <span class="ps-activity-desc">${escapeHtml(activity.description || '')}</span>
                    </div>
                </li>
            `;
            }).join('')}
        </ul>
    `;
    planSectionsList.appendChild(sectionEl);
};

/**
 * Append one activity item to its parent section in the detailed review panel.
 *
 * ctx must contain: state, elements, activityLabels,
 *   getActivityIconUrl, escapeHtml, texts, formatTemplate
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const addActivityToSection = (data, ctx) => {
    const {state, elements, activityLabels, getActivityIconUrl, escapeHtml, texts, formatTemplate} = ctx;
    const {prvHeaderSub} = elements;

    state.totalActivities += 1;
    const sectionEntry = state.planSectionsData.find((section) => section.sectionIndex === data.section_index);
    if (!sectionEntry) {
        return;
    }

    sectionEntry.activities.push({
        type: data.activity_type || data.type,
        name: data.title || data.name,
        description: data.description || ''
    });

    const done = sectionEntry.activities.length;
    if (sectionEntry.activityCount !== null && sectionEntry.activityCount !== undefined) {
        sectionEntry.metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
            done,
            total: sectionEntry.activityCount,
            description: sectionEntry.description,
        });
    } else {
        sectionEntry.metaEl.textContent = formatTemplate(texts.courseai_section_progress_no_total, {
            description: sectionEntry.description,
        });
    }

    const activityItem = document.createElement('div');
    activityItem.className = 'prv-activity-item';
    const activityType = data.activity_type || data.type || 'quiz';
    const activityName = data.title || data.name || texts.courseai_activity_default;
    const iconUrl = getActivityIconUrl(activityType);
    activityItem.innerHTML = `
        <span class="ps-badge ps-badge--${escapeHtml(activityType)}">
            <img src="${iconUrl}"
                 class="ps-badge-icon"
                 alt=""
                 onerror="this.style.display='none'">
            <span class="ps-badge-text">
                ${escapeHtml(activityLabels[activityType] || activityType)}
            </span>
        </span>
        <div class="prv-activity-text">
            <p class="prv-activity-name">${escapeHtml(activityName)}</p>
            <p class="prv-activity-desc">${escapeHtml(data.description || '')}</p>
        </div>
    `;
    sectionEntry.bodyEl.appendChild(activityItem);

    if (prvHeaderSub) {
        prvHeaderSub.textContent = formatTemplate(texts.courseai_plan_adding, {
            name: activityName,
        });
    }
};
