// This file is part of Moodle - http://moodle.org/

/**
 * SSE stream manager for courseai.
 *
 * @module     local_coursegen/local/courseai/stream
 */

import { setCompactChatState } from './ui-planning';

// Module-level variable to preserve phase 4 total activities
// This survives state resets that happen during stream opening
let preservedPhase4Total = 0;

/**
 * Create stream manager.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createStreamManager = (deps) => {
    const {
        state,
        elements,
        stepsUi,
        planningUi,
        detailedUi,
        renderPlanMarkdown,
        createCourseFromSession,
        texts,
    } = deps;

    const {
        prvHeaderSub,
        prvHeaderTitle,
        prvLiveNote,
        pcSubtitle,
        pcTitle,
        typingCursor,
        planningSpinner,
        pcStep,
        planningProgressCard,
        pcToggleRow,
        pcDetailsPanel,
        pcChevron,
    } = elements;

    const normalizeText = (value) => (value || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    const normalizeActivityType = (rawType) => {
        const cleaned = normalizeText(rawType).replace(/\s+/g, ' ');
        const aliases = {
            forum: 'forum',
            page: 'page',
            book: 'book',
            quiz: 'quiz',
            'quiz blueprint': 'quiz',
            assignment: 'assign',
            task: 'assign',
            resource: 'resource',
            file: 'resource',
            folder: 'folder',
            label: 'label',
            'text and media area': 'label',
            database: 'data',
            glossary: 'glossary',
            lesson: 'lesson',
            url: 'url',
            wiki: 'wiki',
            workshop: 'workshop',
            scorm: 'scorm',
            'scorm package': 'scorm',
            imscp: 'imscp',
            'ims content package': 'imscp',
            feedback: 'feedback',
            choice: 'choice',
            survey: 'survey',
            h5p: 'h5pactivity',
            'h5p activity': 'h5pactivity',
            certificate: 'customcert',
            customcert: 'customcert',
            chat: 'chat',
            lti: 'lti',
        };

        if (aliases[cleaned]) {
            return aliases[cleaned];
        }

        const firstWord = cleaned.split(' ')[0] || cleaned;
        if (aliases[firstWord]) {
            return aliases[firstWord];
        }

        return cleaned.replace(/\s+/g, '_');
    };

    const extractActivityFromStatus = (statusText) => {
        const text = statusText || '';

        let match = text.match(/^Designing\s+([^:]+):\s+(.+?)\.\.\./i);
        if (match) {
            return {
                type: normalizeActivityType(match[1]),
                title: match[2].trim(),
            };
        }

        match = text.match(/^Designing\s+Quiz\s+Blueprint\s+for:\s+(.+?)\.\.\./i);
        if (match) {
            return {
                type: 'quiz',
                title: match[1].trim(),
            };
        }

        match = text.match(/^Generating\s+Assignment\s+content\s+for:\s+(.+?)\.\.\./i);
        if (match) {
            return {
                type: 'assign',
                title: match[1].trim(),
            };
        }

        return null;
    };

    const isActivityDoneStatus = (statusText) => {
        const text = statusText || '';
        return /^(?:[A-Za-z][A-Za-z\s0-9/_-]*\s+ready:|Assembling final Quiz package\.\.\.)/i.test(text);
    };

    const humanizeType = (type) => {
        return String(type || '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (char) => char.toUpperCase());
    };

    const getActivityLabel = (type) => {
        const keyMap = {
            quiz: 'courseai_activity_quiz',
            book: 'courseai_activity_book',
            assign: 'courseai_activity_assign',
            forum: 'courseai_activity_forum',
            lesson: 'courseai_activity_lesson',
            url: 'courseai_activity_url',
            resource: 'courseai_activity_resource',
            page: 'courseai_activity_page',
            data: 'courseai_activity_data',
            glossary: 'courseai_activity_glossary',
            label: 'courseai_activity_resource',
            folder: 'courseai_activity_resource',
            wiki: null,
            workshop: null,
            scorm: null,
            imscp: null,
            feedback: null,
            choice: null,
            survey: null,
            h5pactivity: null,
            customcert: null,
            chat: null,
            lti: null,
        };

        const key = keyMap[type] || null;
        if (key && texts[key]) {
            return texts[key];
        }
        return type ? humanizeType(type) : texts.courseai_activity_default;
    };

    const createGenerationTracker = () => {
        const sourceSections = Array.isArray(state.latestInitialSections)
            ? state.latestInitialSections
            : [];

        const sections = sourceSections.map((section, sectionIndex) => {
            const activities = Array.isArray(section.activities) ? section.activities : [];
            return {
                index: sectionIndex,
                name: section.name || `${texts.courseai_section_label} ${sectionIndex + 1}`,
                activities: activities.map((activity, activityIndex) => ({
                    sectionIndex,
                    activityIndex,
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

    const renderGenerationTracker = () => {
        if (!pcDetailsPanel || !state.generationTracker) {
            return;
        }

        const tracker = state.generationTracker;
        pcDetailsPanel.innerHTML = '';

        tracker.sections.forEach((section, sectionIdx) => {
            const sectionEl = document.createElement('div');
            sectionEl.className = 'ps-section';

            const doneCount = section.activities.filter((activity) => activity.status === 'done').length;
            const inProgressCount = section.activities.filter((activity) => activity.status === 'in_progress').length;
            const totalCount = section.activities.length;
            const visibleCount = doneCount + inProgressCount;

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
            countEl.textContent = `${visibleCount}/${totalCount}`;

            headEl.appendChild(numEl);
            headEl.appendChild(infoEl);
            headEl.appendChild(countEl);

            const listEl = document.createElement('ul');
            listEl.className = 'ps-activities';

            section.activities.forEach((activity) => {
                const itemEl = document.createElement('li');
                itemEl.className = `ps-activity ps-activity--${activity.status}`;

                if (activity.status === 'pending') {
                    const skeleton = document.createElement('span');
                    skeleton.className = 'ps-skeleton-line ps-skeleton-line--activity';
                    skeleton.setAttribute('aria-hidden', 'true');
                    itemEl.appendChild(skeleton);
                } else {
                    const statusDot = document.createElement('span');
                    statusDot.className = `ps-status-dot ps-status-dot--${activity.status}`;
                    statusDot.setAttribute('aria-hidden', 'true');

                    const badgeEl = document.createElement('span');
                    badgeEl.className = `ps-badge ps-badge--${activity.type}`;

                    const badgeTextEl = document.createElement('span');
                    badgeTextEl.className = 'ps-badge-text';
                    badgeTextEl.textContent = getActivityLabel(activity.type);
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

                    itemEl.appendChild(statusDot);
                    itemEl.appendChild(badgeEl);
                    itemEl.appendChild(activityInfo);
                }
                listEl.appendChild(itemEl);
            });

            sectionEl.appendChild(headEl);
            sectionEl.appendChild(listEl);
            pcDetailsPanel.appendChild(sectionEl);
        });
    };

    const findNextPendingIndex = (startFrom = 0) => {
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

    const markTrackerActivityDone = (index) => {
        const tracker = state.generationTracker;
        if (!tracker || index < 0 || index >= tracker.flat.length) {
            return;
        }
        tracker.flat[index].status = 'done';
    };

    const updateTrackerActivityStatusByCoordinates = (sectionIndex, activityIndex, status) => {
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

    const syncTrackerFromStatus = (statusText) => {
        const tracker = state.generationTracker;
        if (!tracker || tracker.flat.length === 0) {
            return;
        }

        const parsedStart = extractActivityFromStatus(statusText);
        const doneStatus = isActivityDoneStatus(statusText);

        if (doneStatus && tracker.currentIndex >= 0) {
            markTrackerActivityDone(tracker.currentIndex);
            tracker.currentIndex = -1;
        }

        if (parsedStart) {
            if (tracker.currentIndex >= 0 && tracker.flat[tracker.currentIndex].status !== 'done') {
                markTrackerActivityDone(tracker.currentIndex);
            }

            let nextIndex = -1;
            const pendingStart = findNextPendingIndex(Math.max(0, tracker.currentIndex + 1));

            if (parsedStart.title) {
                const normalizedTitle = normalizeText(parsedStart.title);
                for (let idx = Math.max(0, pendingStart); idx < tracker.flat.length; idx++) {
                    const activity = tracker.flat[idx];
                    if (activity.status !== 'pending') {
                        continue;
                    }
                    if (normalizeText(activity.title) === normalizedTitle) {
                        nextIndex = idx;
                        break;
                    }
                }
            }

            if (nextIndex === -1) {
                nextIndex = pendingStart;
            }

            if (nextIndex >= 0) {
                tracker.flat[nextIndex].status = 'in_progress';
                tracker.currentIndex = nextIndex;
            }
        }

        renderGenerationTracker();
    };

    const markAllTrackerActivitiesDone = () => {
        const tracker = state.generationTracker;
        if (!tracker || !Array.isArray(tracker.flat)) {
            return;
        }
        tracker.flat.forEach((activity) => {
            activity.status = 'done';
        });
        tracker.currentIndex = -1;
        renderGenerationTracker();
    };

    const updateTrackerImageProgress = (sectionIndex, activityIndex, done, total) => {
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

    const getTrackerImagesProgress = () => {
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

    const collectStringValues = (value, output) => {
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

    const normalizeImageSource = (source) => {
        if (!source || typeof source !== 'string') {
            return '';
        }

        return source
            .trim()
            .replace(/^['"]|['"]$/g, '')
            .replace(/\\\//g, '/');
    };

    const countImagesInActivityPayload = (activityPayload) => {
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

    const setCompletionStatsFromGeneratedResult = (generatedActivities) => {
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

    const closeStream = () => {
        if (state.sseSource) {
            try {
                state.sseSource.close();
            } catch (e) {
                // Ignore stream close errors.
            }
            state.sseSource = null;
        }
    };

    const openSSEStream = (streamUrl, retryAttempt = 0, streamMode = 'planning') => {
        // When the user pauses a stream mid-way and then regenerates, the AI backend
        // continues running and writes the remaining events + "done" to the stream buffer.
        // When the new EventSource connects, it may read that stale "done" before the
        // new content arrives.  We detect this as a "stale done" (done with no preceding
        // content events) and retry automatically.
        const MAX_STALE_RETRIES = 3;
        const STALE_RETRY_DELAY_MS = 2000;

        // Per-attempt flag: set to true when any structural content event arrives.
        let contentReceived = false;

        const ensureStreamContentVisible = () => {
            const loadingEl = document.getElementById('planningLoading');
            const streamContentEl = document.getElementById('planningStreamContent');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }
            if (streamContentEl) {
                streamContentEl.style.display = '';
            }
        };

        if (!streamUrl) {
            throw new Error(texts.courseai_error_stream_url);
        }
        closeStream();

        // Keep compact chat disabled for the whole active stream lifecycle.
        // It is re-enabled explicitly on review/failed/error states.
        setCompactChatState(deps, 'disabled');

        // Only reset planning UI on the first attempt (not on stale-done retries).
        if (retryAttempt === 0) {
            if (streamMode !== 'generating') {
                preservedPhase4Total = 0;
            }

            const savedLatestInitialSections = streamMode === 'generating'
                ? (Array.isArray(state.latestInitialSections) ? state.latestInitialSections : [])
                : [];

            // PRESERVE phase4TotalActivities BEFORE reset
            const savedPhase4Total = state.phase4TotalActivities || 0;
            window.console.log('[PHASE4-DEBUG] BEFORE-RESET - Saving phase4TotalActivities:', savedPhase4Total);

            stepsUi.resetPlanningState({showLoading: streamMode !== 'generating'});

            if (streamMode === 'generating' && savedLatestInitialSections.length > 0) {
                state.latestInitialSections = savedLatestInitialSections;
            }

            if (streamMode === 'generating') {
                ensureStreamContentVisible();

                if (planningProgressCard) {
                    planningProgressCard.style.display = '';
                }
                if (pcToggleRow) {
                    pcToggleRow.style.display = 'flex';
                }
                state.planDetailsOpen = true;
                if (pcDetailsPanel) {
                    pcDetailsPanel.style.display = 'block';
                }
                if (pcChevron) {
                    pcChevron.style.transform = 'rotate(90deg)';
                }
                if (pcStep) {
                    pcStep.textContent = texts.courseai_state_completed;
                }
                if (pcTitle) {
                    pcTitle.textContent = texts.courseai_course_creating;
                }
                if (pcSubtitle) {
                    pcSubtitle.textContent = texts.courseai_course_creating_subtitle;
                }

                    state.generationTracker = createGenerationTracker();
                    state.structuredActivityProgress = false;
                    state.activityProgressTotal = 0;
                    state.activityProgressStarted = 0;
                    state.activityProgressDone = 0;
                    renderGenerationTracker();
                }

            // RESTORE phase4TotalActivities AFTER reset
            if (savedPhase4Total > 0) {
                state.phase4TotalActivities = savedPhase4Total;
                preservedPhase4Total = savedPhase4Total;
                window.console.log('[PHASE4-DEBUG] AFTER-RESET - Restored phase4TotalActivities:', state.phase4TotalActivities);
            }
        }

        state.sseSource = new EventSource(streamUrl);
        state.sseSource.addEventListener('message', async(event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            // Helper: create a new checklist container inside the matching round's response slot
            const getOrCreateRoundChecklist = (els, currentRound) => {
                const existing = document.querySelector(`.courseai-checklist[data-round="${currentRound}"]`);
                if (existing) {
                    return existing.querySelector('.courseai-checklist-list');
                }
                const container = document.createElement('div');
                container.className = 'courseai-checklist';
                container.setAttribute('data-round', currentRound);
                const list = document.createElement('ul');
                list.className = 'courseai-checklist-list';
                container.appendChild(list);
                const label = document.createElement('span');
                label.className = 'courseai-checklist-label';
                label.textContent = texts.courseai_checklist_label || 'Course sections';
                container.insertBefore(label, list);
                // Insert checklist into the corresponding round container's response slot
                const roundEl = els.adjustmentHistory
                    ? els.adjustmentHistory.querySelector(`.courseai-round[data-round="${currentRound}"]`)
                    : null;
                const responseSlot = roundEl
                    ? roundEl.querySelector('.courseai-round-response')
                    : null;
                if (responseSlot) {
                    responseSlot.appendChild(container);
                } else if (els.adjustmentHistory && els.adjustmentHistory.parentNode) {
                    els.adjustmentHistory.parentNode.insertBefore(
                        container,
                        els.adjustmentHistory.nextSibling
                    );
                }
                return list;
            };

            switch (data.type) {
                case 'activity': {
                    contentReceived = true;
                    // Activity data consumed internally; no sections view shown
                    break;
                }
                case 'section': {
                    contentReceived = true;
                    // Hide loading spinner and show stream content on first section
                    const loadingEl = document.getElementById('planningLoading');
                    const streamContentEl = document.getElementById('planningStreamContent');
                    if (loadingEl) {
                        loadingEl.style.display = 'none';
                    }
                    if (streamContentEl) {
                        streamContentEl.style.display = '';
                    }
                    // Capture first section name as the course title for the header
                    if (!state.courseTitle && data.name) {
                        state.courseTitle = data.name;
                        if (prvHeaderTitle) {
                            prvHeaderTitle.textContent = state.courseTitle;
                        }
                    }
                    // Add section to checklist in the left panel (loading state initially)
                    // For regeneration rounds, create a new checklist below the adjustment
                    const round = state.generationRound || 0;
                    const targetList = (round <= 1)
                        ? elements.checklistList
                        : getOrCreateRoundChecklist(elements, round);
                    if (targetList && data.name) {
                        const item = document.createElement('li');
                        item.className = 'courseai-checklist-item is-loading';
                        const activityCount = (data.activities || []).length;
                        item.setAttribute('data-section-index', data.section_index);
                        item.setAttribute('data-round', state.generationRound || 0);
                        item.setAttribute('data-remaining', activityCount);
                        item.innerHTML = '<span class="courseai-checklist-check">'
                            + '<svg class="spinner-icon" viewBox="0 0 24 24">'
                            + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
                            + '<svg class="check-icon" viewBox="0 0 24 24">'
                            + '<polyline points="20 6 9 17 4 12"/></svg></span>'
                            + '<span class="courseai-checklist-name">'
                            + data.name + '</span>';
                        targetList.appendChild(item);
                        // Show the parent checklist container
                        const listParent = targetList.closest('.courseai-checklist');
                        if (listParent) {
                            listParent.classList.remove('hidden');
                        }
                        // Also ensure the default checklist is visible for round 1
                        if (elements.checklist) {
                            elements.checklist.classList.remove('hidden');
                        }
                    }
                    break;
                }
                case 'detailed_plan_start': {
                    contentReceived = true;
                    detailedUi.initDetailedPlanView(data);
                    // Set the course title as the header title if captured from structure
                    if (state.courseTitle && prvHeaderTitle) {
                        prvHeaderTitle.textContent = state.courseTitle;
                    }
                    // Show initial progress when detailed planning begins
                    stepsUi.setProgress(5);
                    break;
                }
                case 'detailed_plan_field':
                    contentReceived = true;
                    detailedUi.handleDetailedPlanField(data);
                    break;
                case 'detailed_plan_activity': {
                    contentReceived = true;
                    detailedUi.handleDetailedPlanActivity(data);
                    // Track progress: each activity planned updates the bar (0→90%)
                    state.activitiesPlannedCount = (state.activitiesPlannedCount || 0) + 1;
                    const totalDetailed = state.detailedTotal || 1;
                    const pct = Math.min(90, (state.activitiesPlannedCount / totalDetailed) * 90);
                    stepsUi.setProgress(Math.round(pct));

                    // Mark section as done when all its activities are planned
                    if (data.section_index !== undefined) {
                        const round = state.generationRound || 0;
                        const items = document.querySelectorAll(
                            `.courseai-checklist-list [data-section-index="${data.section_index}"][data-round="${round}"]`
                        );
                        items.forEach((item) => {
                            const remaining = parseInt(item.getAttribute('data-remaining') || '1', 10);
                            const newRemaining = Math.max(0, remaining - 1);
                            item.setAttribute('data-remaining', newRemaining);
                            if (newRemaining === 0) {
                                item.classList.remove('is-loading');
                                item.classList.add('is-done');
                            }
                        });
                    }
                    break;
                }
                case 'token':
                    contentReceived = true;
                    stepsUi.switchPlanMode('markdown');
                    state.planBuffer += data.text || '';
                    renderPlanMarkdown();
                    break;
                case 'status': {
                    const statusText = data.text || '';

                    // During final generation, keep stream container visible and hide
                    // centered planning spinner regardless of event shape.
                    if (streamMode === 'generating') {
                        ensureStreamContentVisible();
                        if (!state.structuredActivityProgress) {
                            syncTrackerFromStatus(statusText);
                        }
                    }

                    // Update the loading spinner text while AI is still planning
                    const loadingTextEl = document.querySelector('.planning-loading-text');
                    if (loadingTextEl && statusText) {
                        loadingTextEl.textContent = statusText;
                    }

                    // Use module-level preserved value as fallback if state was reset
                    const totalActivities = state.phase4TotalActivities || preservedPhase4Total;

                    window.console.log('[PHASE4-DEBUG] Status event received:', statusText);
                    window.console.log('[PHASE4-DEBUG] Current stage:', state.currentStage);
                    window.console.log('[PHASE4-DEBUG] state.phase4TotalActivities:', state.phase4TotalActivities);
                    window.console.log('[PHASE4-DEBUG] preservedPhase4Total (fallback):', preservedPhase4Total);
                    window.console.log('[PHASE4-DEBUG] totalActivities (used for calc):', totalActivities);

                    // Always update the header subtitle with live status text
                    if (prvHeaderSub) {
                        prvHeaderSub.textContent = statusText;
                    }
                    // Also keep progress card subtitle updated
                    if (pcSubtitle) {
                        pcSubtitle.textContent = statusText;
                    }
                    // Show live note when in detailed planning mode
                    if (state.planningMode === 'detailed' && prvLiveNote) {
                        prvLiveNote.style.display = 'block';
                        prvLiveNote.textContent = texts.courseai_live_note_detailed;
                    }

                    // Track progress during content generation phase (after detailed plan approval)
                    // Two-phase hybrid approach:
                    // Phase 1: Starting activities (0% → 30%) - immediate feedback
                    // Phase 2: Completing activities (30% → 90%) - granular progress
                    // Use totalActivities (with fallback to module-level variable) to survive resets
                    if (state.currentStage === 'generating' && totalActivities > 0) {
                        window.console.log('[PHASE4-DEBUG] Inside tracking condition');

                        // Phase 1: Detect when an activity/resource STARTS
                        const startPattern = /^(Designing|Generating Assignment content)/i;
                        const isActivityStarting = startPattern.test(statusText);

                        // Phase 2: Detect when an activity/resource COMPLETES
                        const completePattern = new RegExp(
                            'ready|Assembling final|configuration ready|with \\d+ discussion',
                            'i'
                        );
                        const isActivityComplete = completePattern.test(statusText);

                        window.console.log('[PHASE4-DEBUG] Start pattern match:', isActivityStarting);
                        window.console.log('[PHASE4-DEBUG] Complete pattern match:', isActivityComplete);

                        if (isActivityStarting) {
                            // Phase 1: Track started activities (0% → 30%)
                            state.contentGenerationStarted = (state.contentGenerationStarted || 0) + 1;
                            const startProgress = Math.min(
                                30,
                                (state.contentGenerationStarted / totalActivities) * 30
                            );
                            window.console.log('[PHASE4-DEBUG] PHASE 1 - Started count:', state.contentGenerationStarted);
                            window.console.log('[PHASE4-DEBUG] PHASE 1 - Setting progress to:', Math.round(startProgress));
                            stepsUi.setProgress(Math.round(startProgress));
                        } else if (isActivityComplete) {
                            // Phase 2: Track completed activities (30% → 90%)
                            state.contentGenerationCurrent = (state.contentGenerationCurrent || 0) + 1;
                            const completeProgress = 30 + Math.min(
                                60,
                                (state.contentGenerationCurrent / totalActivities) * 60
                            );
                            window.console.log('[PHASE4-DEBUG] PHASE 2 - Complete count:', state.contentGenerationCurrent);
                            window.console.log('[PHASE4-DEBUG] PHASE 2 - Setting progress to:', Math.round(completeProgress));
                            stepsUi.setProgress(Math.round(completeProgress));
                        } else {
                            window.console.log('[PHASE4-DEBUG] No pattern matched for this event');
                        }
                    } else {
                        window.console.log(
                            '[PHASE4-DEBUG] NOT in tracking condition. Stage:',
                            state.currentStage,
                            'Total:',
                            totalActivities
                        );
                    }
                    break;
                }
                case 'activity_progress_init': {
                    state.structuredActivityProgress = true;
                    state.activityProgressTotal = Math.max(0, Number(data.total) || 0);
                    state.activityProgressStarted = 0;
                    state.activityProgressDone = 0;
                    if (state.currentStage === 'generating' && state.activityProgressTotal > 0) {
                        stepsUi.setProgress(0);
                    }
                    break;
                }
                case 'activity_progress_start': {
                    updateTrackerActivityStatusByCoordinates(
                        Number(data.section_index) || 0,
                        Number(data.activity_index) || 0,
                        'in_progress'
                    );

                    state.activityProgressStarted = (state.activityProgressStarted || 0) + 1;
                    if (state.currentStage === 'generating' && (state.activityProgressTotal || 0) > 0) {
                        const startProgress = Math.min(
                            30,
                            (state.activityProgressStarted / state.activityProgressTotal) * 30
                        );
                        stepsUi.setProgress(Math.round(startProgress));
                    }

                    renderGenerationTracker();
                    break;
                }
                case 'activity_progress_done': {
                    updateTrackerActivityStatusByCoordinates(
                        Number(data.section_index) || 0,
                        Number(data.activity_index) || 0,
                        'done'
                    );

                    state.activityProgressDone = (state.activityProgressDone || 0) + 1;
                    if (state.currentStage === 'generating' && (state.activityProgressTotal || 0) > 0) {
                        const completeProgress = 30 + Math.min(
                            60,
                            (state.activityProgressDone / state.activityProgressTotal) * 60
                        );
                        stepsUi.setProgress(Math.round(completeProgress));
                    }

                    renderGenerationTracker();
                    break;
                }
                case 'activity_progress_failed':
                    updateTrackerActivityStatusByCoordinates(
                        Number(data.section_index) || 0,
                        Number(data.activity_index) || 0,
                        'done'
                    );
                    renderGenerationTracker();
                    break;
                case 'image_progress_init': {
                    const activities = Array.isArray(data.activities) ? data.activities : [];
                    activities.forEach((item) => {
                        updateTrackerImageProgress(
                            Number(item.section_index) || 0,
                            Number(item.activity_index) || 0,
                            Number(item.done) || 0,
                            Number(item.total) || 0
                        );
                    });

                    const imageTotals = getTrackerImagesProgress();
                    state.imageProgressTotal = imageTotals.total;
                    if (state.currentStage === 'generating' && imageTotals.total > 0) {
                        stepsUi.setProgress(90);
                    }

                    renderGenerationTracker();
                    break;
                }
                case 'image_progress_tick': {
                    updateTrackerImageProgress(
                        Number(data.section_index) || 0,
                        Number(data.activity_index) || 0,
                        Number(data.done) || 0,
                        Number(data.total) || 0
                    );

                    const imageTotals = getTrackerImagesProgress();
                    state.imageProgressDone = imageTotals.done;
                    state.imageProgressTotal = imageTotals.total;

                    if (state.currentStage === 'generating' && imageTotals.total > 0) {
                        const imageProgress = Math.min(99, 90 + Math.round((imageTotals.done / imageTotals.total) * 9));
                        stepsUi.setProgress(imageProgress);
                    }

                    renderGenerationTracker();
                    break;
                }
                case 'image_progress_done':
                    if (state.currentStage === 'generating') {
                        stepsUi.setProgress(99);
                    }
                    break;
                case 'review_needed':
                    stepsUi.setStepState('planning', 'done');
                    state.currentStage = 'planning';
                    stepsUi.updateFlowNav();
                    if (Array.isArray(data.current_plan) && data.current_plan.length > 0) {
                        detailedUi.initDetailedPlanView({sections: data.current_plan});
                        data.current_plan.forEach((section, sectionIndex) => {
                            (section.activities || []).forEach((activity, activityIndex) => {
                                detailedUi.handleDetailedPlanActivity({
                                    section_index: sectionIndex,
                                    activity_index: activityIndex,
                                    data: activity.detailed_plan || {}
                                });
                            });
                        });
                    }
                    // Enable action buttons (IA/Delete) AFTER re-initialization so newly created controls get enabled
                    if (typeof detailedUi.enableAllActionControls === 'function') {
                        detailedUi.enableAllActionControls();
                    }
                    planningUi.showReviewActions(state.planningMode === 'detailed' ? 'detailed' : 'markdown');
                    // Re-enable compact chat now that review is ready
                    setCompactChatState(deps, 'enabled');
                    break;
                case 'completed': {
                    if (streamMode === 'generating') {
                        markAllTrackerActivitiesDone();
                    }
                    setCompletionStatsFromGeneratedResult(data.result || []);
                    stepsUi.setStepState('planning', 'done');
                    stepsUi.setStepState('generating', 'active');
                    state.currentStage = 'generating';
                    stepsUi.updateFlowNav();
                    closeStream();
                    await createCourseFromSession();
                    break;
                }
                case 'failed':
                    if (streamMode === 'generating') {
                        markAllTrackerActivitiesDone();
                    }
                    stepsUi.setStepState('planning', 'active');
                    closeStream();
                    if (planningSpinner) {
                        planningSpinner.classList.add('done');
                    }
                    if (pcStep) {
                        pcStep.textContent = texts.courseai_state_error;
                    }
                    if (pcSubtitle) {
                        pcSubtitle.textContent = data.message || texts.courseai_error_generic;
                    }
                    // Re-enable action controls on failure so user can interact with partial plan
                    if (typeof detailedUi.enableAllActionControls === 'function') {
                        detailedUi.enableAllActionControls();
                    }
                    // Stream failed - re-enable compact chat for retry
                    setCompactChatState(deps, 'enabled');
                    break;
                default:
                    break;
            }
        });

        state.sseSource.addEventListener('done', () => {
            closeStream();

            // Stale-done guard: if done arrived without any content events, the previous
            // stream's "done" was read before the new content arrived (race condition after
            // a Pausar + Regenerar cycle).  Retry automatically up to MAX_STALE_RETRIES times.
            if (!contentReceived && retryAttempt < MAX_STALE_RETRIES) {
                window.console.log(
                    '[STREAM] Stale done detected (attempt', retryAttempt + 1, '/', MAX_STALE_RETRIES + ').',
                    'Retrying in', STALE_RETRY_DELAY_MS, 'ms…'
                );
                setTimeout(() => openSSEStream(streamUrl, retryAttempt + 1, streamMode), STALE_RETRY_DELAY_MS);
                return;
            }

            if (typingCursor) {
                typingCursor.classList.add('hidden');
            }
            if (streamMode === 'generating') {
                markAllTrackerActivitiesDone();
            }
            if (planningSpinner) {
                planningSpinner.classList.add('done');
            }
            // Safety net: enable action controls in case review_needed didn't cover newly created controls
            if (typeof detailedUi.enableAllActionControls === 'function') {
                detailedUi.enableAllActionControls();
            }
            // Stream completed normally - keep chat disabled during generating phase.
            if (streamMode !== 'generating') {
                setCompactChatState(deps, 'enabled');
            }
        });

        state.sseSource.onerror = () => {
            if (typingCursor) {
                typingCursor.classList.add('hidden');
            }
            if (planningSpinner) {
                planningSpinner.classList.add('done');
            }
            if (pcStep) {
                pcStep.textContent = texts.courseai_state_error;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.courseai_error_connection;
            }
            // Connection error - re-enable compact chat for retry
            setCompactChatState(deps, 'enabled');
        };
    };

    return {
        closeStream,
        openSSEStream,
    };
};
