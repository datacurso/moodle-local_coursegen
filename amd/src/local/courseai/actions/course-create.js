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

/** @type {string} AMD module implementing the ajax contract of the target-course autocomplete. */
const TARGET_COURSE_DATASOURCE = 'local_coursegen/local/courseai/target-course-datasource';

/**
 * Controller of the "create new / import into existing" controls. The page has a
 * single review panel, so it is wired once and reused on every panel show.
 *
 * @type {Object|null}
 */
let importControls = null;

/**
 * Replace the options of a category select with an empty choice plus the categories.
 *
 * @param {HTMLSelectElement} select
 * @param {Array<{id: number, pathname: string}>} categories
 * @param {string} emptyLabel
 * @returns {void}
 */
const fillCategoryOptions = (select, categories, emptyLabel) => {
    select.innerHTML = '';
    const emptyOpt = document.createElement('option');
    emptyOpt.value = '';
    emptyOpt.textContent = '-- ' + emptyLabel + ' --';
    select.appendChild(emptyOpt);
    categories.forEach((cat) => {
        const opt = document.createElement('option');
        opt.value = String(cat.id);
        opt.textContent = cat.pathname;
        select.appendChild(opt);
    });
};

/**
 * Enhance a select as a core autocomplete without ever throwing: a plain select still works.
 *
 * Signature: enhance(selector, tags, ajax, placeholder, caseSensitive, showSuggestions,
 * noSelectionString). `ajax` is an AMD MODULE NAME — passing a label there made RequireJS
 * try to load a module called "Category" ("Script error for 'Category'").
 *
 * @param {Object} FormAutocomplete core/form-autocomplete module
 * @param {HTMLSelectElement} select
 * @param {string|boolean} ajaxModule AMD module name implementing transport/processResults, or false
 * @param {string} placeholder
 * @param {string} noResults
 * @returns {void}
 */
const enhanceSelect = (FormAutocomplete, select, ajaxModule, placeholder, noResults) => {
    try {
        FormAutocomplete.enhance(select, false, ajaxModule, placeholder, false, true, noResults);
    } catch (e) {
        // Fall through — plain select still works.
    }
};

/**
 * Wire the import-into-existing-course controls of the review panel (ticket #9539).
 *
 * Returns null when the site setting is off (the markup is not rendered) so the
 * panel behaves exactly as before.
 *
 * @param {HTMLElement} panel #courseReviewPanel
 * @param {HTMLButtonElement} confirmBtn
 * @param {Object} texts
 * @param {Object} FormAutocomplete core/form-autocomplete module
 * @returns {Object|null} {reset, getMode, getTargetCourseId} or null when disabled
 */
const setupImportControls = (panel, confirmBtn, texts, FormAutocomplete) => {
    if (panel.dataset.importExistingEnabled !== '1') {
        return null;
    }
    const radios = Array.from(panel.querySelectorAll('input[name="coursegen-targetmode"]'));
    const targetFields = document.getElementById('importTargetFields');
    const categorySelect = document.getElementById('importCategory');
    const courseGroup = document.getElementById('importCourseGroup');
    const shortnameGroup = document.getElementById('reviewShortnameGroup');
    const newCategoryGroup = document.getElementById('reviewCategoryGroup');
    if (!radios.length || !targetFields || !categorySelect || !courseGroup) {
        return null;
    }

    // core/form-autocomplete does not re-read the underlying select once enhanced, so
    // the course field is rebuilt from its pristine markup (and re-enhanced) whenever
    // the category changes: the previous selection no longer belongs to the category.
    const courseFieldHtml = courseGroup.innerHTML;
    let courseSelect = null;
    let categoryEnhanced = false;

    const getMode = () => (radios.find((radio) => radio.checked)?.value === 'import' ? 'import' : 'new');
    const getTargetCourseId = () => {
        const id = parseInt(courseSelect?.value || '', 10);
        return id > 0 ? id : 0;
    };
    const syncConfirm = () => {
        confirmBtn.disabled = getMode() === 'import' && getTargetCourseId() === 0;
    };
    const buildCourseField = () => {
        courseGroup.innerHTML = courseFieldHtml;
        courseSelect = courseGroup.querySelector('select');
        if (!courseSelect) {
            return;
        }
        // Core dispatches a native change on the select when a suggestion is picked.
        courseSelect.addEventListener('change', syncConfirm);
        enhanceSelect(
            FormAutocomplete,
            courseSelect,
            TARGET_COURSE_DATASOURCE,
            texts.courseai_import_course_placeholder || '',
            texts.courseai_no_results || ''
        );
    };
    const applyMode = () => {
        const importing = getMode() === 'import';
        targetFields.style.display = importing ? '' : 'none';
        // The copy keeps the target's names and category: those fields are for new courses.
        if (shortnameGroup) { shortnameGroup.style.display = importing ? 'none' : ''; }
        if (newCategoryGroup) { newCategoryGroup.style.display = importing ? 'none' : ''; }
        syncConfirm();
    };

    radios.forEach((radio) => radio.addEventListener('change', applyMode));
    categorySelect.addEventListener('change', () => {
        buildCourseField();
        syncConfirm();
    });
    buildCourseField();

    return {
        /**
         * Put the controls back to their initial state for a (re)opened panel.
         *
         * @param {Array<{id: number, pathname: string}>} categories same list as #reviewCategory
         * @returns {void}
         */
        reset: (categories) => {
            fillCategoryOptions(categorySelect, categories, texts.courseai_import_category_label || '');
            if (!categoryEnhanced) {
                categoryEnhanced = true;
                enhanceSelect(
                    FormAutocomplete,
                    categorySelect,
                    false,
                    texts.courseai_import_category_placeholder || '',
                    texts.courseai_no_results || ''
                );
            }
            buildCourseField();
            radios.forEach((radio) => { radio.checked = radio.value === 'new'; });
            applyMode();
        },
        getMode,
        getTargetCourseId,
    };
};

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

    fillCategoryOptions(categorySelect, categories, texts.courseai_review_category_label || '');

    if (defaultCategoryId > 0) {
        categorySelect.value = String(defaultCategoryId);
    }

    // There is no remote datasource for the new-course category, so ajax=false.
    enhanceSelect(
        FormAutocomplete,
        categorySelect,
        false,
        texts.courseai_review_category_label || '',
        texts.courseai_no_results || ''
    );

    if (!importControls) {
        importControls = setupImportControls(panel, confirmBtn, texts, FormAutocomplete);
    }
    if (importControls) {
        // Same category list as the new-course field: already filtered by moodle/course:create,
        // which is exactly the permission the target's category needs.
        importControls.reset(categories);
    } else {
        confirmBtn.disabled = false;
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
            const targetMode = importControls ? importControls.getMode() : '';
            const targetCourseId = importControls ? importControls.getTargetCourseId() : 0;
            if (targetMode === 'import' && !targetCourseId) {
                // Never submit an import without a target; the button is disabled anyway.
                confirmBtn.disabled = true;
                return;
            }
            resolved = true;
            cleanup();
            hidePanel();
            const overrides = {};
            const fullname = fullnameInput.value.trim();
            if (fullname) { overrides.fullname = fullname; }
            if (targetMode === 'import') {
                // The copy keeps the target's short name and category.
                overrides.targetmode = 'import';
                overrides.targetcourseid = targetCourseId;
                resolve(overrides);
                return;
            }
            if (targetMode === 'new') { overrides.targetmode = 'new'; }
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
 * Call the createCourse WS, animate progress, then invoke showCompletionView on success
 * (or showImportProgressView when the backend queued an import into an existing course).
 *
 * @param {Object}   state
 * @param {Object}   elements
 * @param {Object}   texts
 * @param {Object}   stepsUi
 * @param {Object}   Notification
 * @param {Function} createCourse
 * @param {Function} showCompletionView
 * @param {Function} showImportProgressView
 * @param {Object|null} overrides
 * @returns {Promise<Object|null>}
 */
export const createCourseFromSession = async(
    state, elements, texts, stepsUi, Notification, createCourse, showCompletionView, showImportProgressView,
    overrides = null
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
            if (overrides.targetmode) { payload.targetmode = overrides.targetmode; }
            if (overrides.targetcourseid) { payload.targetcourseid = overrides.targetcourseid; }
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

        if (result.importqueued) {
            // courseid is the staging course; the import into the target runs in an adhoc task.
            showImportProgressView({...result, recordid: state.sessionid});
        } else {
            showCompletionView(result);
        }
        return result;
    } catch (error) {
        if (progressInterval) { clearInterval(progressInterval); }
        if (elements.pcStep) { elements.pcStep.textContent = texts.courseai_state_error; }
        if (pcSubtitle) { pcSubtitle.textContent = error?.message || texts.courseai_error_create_course; }
        await Notification.exception(error);
        return null;
    }
};
