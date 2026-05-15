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
        typingCursor,
        planningSpinner,
        pcStep,
    } = elements;

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

    const openSSEStream = (streamUrl, retryAttempt = 0) => {
        // When the user pauses a stream mid-way and then regenerates, the AI backend
        // continues running and writes the remaining events + "done" to the stream buffer.
        // When the new EventSource connects, it may read that stale "done" before the
        // new content arrives.  We detect this as a "stale done" (done with no preceding
        // content events) and retry automatically.
        const MAX_STALE_RETRIES = 3;
        const STALE_RETRY_DELAY_MS = 2000;

        // Per-attempt flag: set to true when any structural content event arrives.
        let contentReceived = false;

        if (!streamUrl) {
            throw new Error(texts.courseai_error_stream_url);
        }
        closeStream();

        // Only reset planning UI on the first attempt (not on stale-done retries).
        if (retryAttempt === 0) {
            // PRESERVE phase4TotalActivities BEFORE reset
            const savedPhase4Total = state.phase4TotalActivities || 0;
            window.console.log('[PHASE4-DEBUG] BEFORE-RESET - Saving phase4TotalActivities:', savedPhase4Total);

            stepsUi.resetPlanningState();

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

            // Helper: create a new checklist container below the adjustment messages
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
                if (els.adjustmentHistory && els.adjustmentHistory.parentNode) {
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
                    planningUi.showReviewActions(state.planningMode === 'detailed' ? 'detailed' : 'markdown');
                    // Re-enable compact chat now that review is ready
                    setCompactChatState(deps, 'enabled');
                    break;
                case 'completed': {
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
                setTimeout(() => openSSEStream(streamUrl, retryAttempt + 1), STALE_RETRY_DELAY_MS);
                return;
            }

            if (typingCursor) {
                typingCursor.classList.add('hidden');
            }
            if (planningSpinner) {
                planningSpinner.classList.add('done');
            }
            // Stream completed normally - re-enable compact chat
            setCompactChatState(deps, 'enabled');
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
