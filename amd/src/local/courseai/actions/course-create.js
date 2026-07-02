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
 * Course-review panel and course-creation action helpers.
 *
 * @module     local_coursegen/local/courseai/actions/course-create
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show the inline course-review panel, load categories from the backend,
 * and resolve to the user's overrides object (or null if cancelled).
 *
 * @param {Object}   state
 * @param {Object}   elements          - unused but kept for API symmetry
 * @param {Object}   texts
 * @param {Function} getCourseSettings
 * @param {Object}   FormAutocomplete  - core/form-autocomplete module
 * @returns {Promise<Object|null>}
 */
export const showCourseReviewPanel = async(state, elements, texts, getCourseSettings, FormAutocomplete) => {
    const panel = document.getElementById('courseReviewPanel');
    const fullnameInput = document.getElementById('reviewFullname');
    const shortnameInput = document.getElementById('reviewShortname');
    const categorySelect = document.getElementById('reviewCategory');
    const confirmBtn = document.getElementById('reviewConfirmBtn');
    const cancelBtn = document.getElementById('reviewCancelBtn');

    if (!panel || !fullnameInput || !categorySelect || !confirmBtn || !cancelBtn) {
        return {};
    }

    let settingsData = null;
    try {
        settingsData = await getCourseSettings(state.sessionid);
    } catch (e) {
        // Fall through with empty data.
    }

    fullnameInput.value = settingsData?.fullname || state.courseTitle || '';
    shortnameInput.value = settingsData?.shortname || '';

    const defaultCategoryId = settingsData?.category || 0;
    const categories = settingsData?.categories || [];

    categorySelect.innerHTML = '';
    const emptyOpt = document.createElement('option');
    emptyOpt.value = '';
    emptyOpt.textContent = '-- ' + texts.courseai_review_category_label + ' --';
    categorySelect.appendChild(emptyOpt);

    categories.forEach((cat) => {
        const opt = document.createElement('option');
        opt.value = String(cat.id);
        opt.textContent = cat.pathname;
        categorySelect.appendChild(opt);
    });

    if (defaultCategoryId > 0) {
        categorySelect.value = String(defaultCategoryId);
    }

    try {
        FormAutocomplete.enhance(
            categorySelect,
            false,
            texts.courseai_review_category_label || '',
            texts.courseai_no_results || ''
        );
    } catch (e) {
        // Fall through — plain select still works.
    }

    return new Promise((resolve) => {
        let resolved = false;

        const cleanup = () => {
            confirmBtn.removeEventListener('click', onConfirm);
            cancelBtn.removeEventListener('click', onCancel);
        };
        const hidePanel = () => { panel.style.display = 'none'; };

        const onConfirm = () => {
            if (resolved) { return; }
            resolved = true;
            cleanup();
            hidePanel();
            const overrides = {};
            const fullname = fullnameInput.value.trim();
            if (fullname) { overrides.fullname = fullname; }
            const shortname = shortnameInput.value.trim();
            if (shortname) { overrides.shortname = shortname; }
            const category = parseInt(categorySelect.value, 10);
            if (category > 0) { overrides.category = category; }
            resolve(overrides);
        };

        const onCancel = () => {
            if (resolved) { return; }
            resolved = true;
            cleanup();
            hidePanel();
            resolve(null);
        };

        confirmBtn.addEventListener('click', onConfirm);
        cancelBtn.addEventListener('click', onCancel);
        panel.style.display = '';
        setTimeout(() => fullnameInput.focus(), 100);
    });
};

/**
 * Call the createCourse WS, animate progress, then invoke showCompletionView on success.
 *
 * @param {Object}   state
 * @param {Object}   elements
 * @param {Object}   texts
 * @param {Object}   stepsUi
 * @param {Object}   Notification
 * @param {Function} createCourse
 * @param {Function} showCompletionView
 * @param {Object|null} overrides
 * @returns {Promise<Object|null>}
 */
export const createCourseFromSession = async(
    state, elements, texts, stepsUi, Notification, createCourse, showCompletionView, overrides = null
) => {
    if (!state.sessionid) { return; }

    const {pcSubtitle, pcPct} = elements;
    const CREATE_COURSE_TIMEOUT_MS = 180000;
    let progressInterval = null;

    try {
        if (elements.pcStep) { elements.pcStep.textContent = texts.courseai_state_completed; }
        if (elements.pcTitle) { elements.pcTitle.textContent = texts.courseai_course_creating; }
        if (pcSubtitle) { pcSubtitle.textContent = texts.courseai_course_creating_subtitle; }

        // Read current displayed progress from the DOM so we continue smoothly
        // from wherever the review panel left off, instead of recalculating from stale SSE state.
        const currentPctText = pcPct ? pcPct.textContent : '';
        const parsedPct = parseInt(currentPctText, 10);
        const startProgress = !isNaN(parsedPct) && parsedPct >= 0 ? parsedPct : 92;
        const targetProgress = 98;
        const duration = 2000;
        const intervalMs = 100;
        const startTime = Date.now();

        progressInterval = setInterval(() => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(1, elapsed / duration);
            const eased = 1 - Math.pow(1 - progress, 3); // ease-out
            const currentProgress = startProgress + (eased * (targetProgress - startProgress));
            stepsUi.setProgress(Math.round(currentProgress));
            if (currentProgress >= targetProgress) { clearInterval(progressInterval); }
        }, intervalMs);

        const payload = {recordid: state.sessionid};
        if (overrides) {
            if (overrides.fullname) { payload.fullname = overrides.fullname; }
            if (overrides.shortname) { payload.shortname = overrides.shortname; }
            if (overrides.category) { payload.category = overrides.category; }
        }
        const timeoutPromise = new Promise((_, reject) => {
            setTimeout(() => reject(new Error(texts.courseai_error_connection)), CREATE_COURSE_TIMEOUT_MS);
        });
        const result = await Promise.race([createCourse(payload), timeoutPromise]);

        if (progressInterval) { clearInterval(progressInterval); }
        stepsUi.setProgress(100);

        if (!result || !result.success) {
            throw new Error(result?.message || texts.courseai_error_create_course);
        }

        showCompletionView(result);
        return result;
    } catch (error) {
        if (progressInterval) { clearInterval(progressInterval); }
        if (elements.pcStep) { elements.pcStep.textContent = texts.courseai_state_error; }
        if (pcSubtitle) { pcSubtitle.textContent = error?.message || texts.courseai_error_create_course; }
        await Notification.exception(error);
        return null;
    }
};
