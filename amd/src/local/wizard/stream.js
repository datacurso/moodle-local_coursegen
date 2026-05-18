// This file is part of Moodle - http://moodle.org/

/**
 * SSE stream manager for wizard.
 *
 * @module     local_coursegen/local/wizard/stream
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
        planReviewCard,
        prvHeaderSub,
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
            throw new Error(texts.wizard_error_stream_url);
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

            switch (data.type) {
                case 'activity': {
                    contentReceived = true;
                    if (!state.planSectionsData.find((section) => section.sectionIndex === data.section_index)) {
                        planningUi.addSectionHeader({
                            section_index: data.section_index,
                            name: data.section_name || texts.wizard_plan_default_unnamed,
                            description: '',
                            activity_count: null
                        });
                    }
                    planningUi.addActivityToSection(data);
                    // Update progress based on activities received (cap at 95% to avoid reaching 100% prematurely)
                    const activityProgress = Math.min(95, (state.totalActivities / Math.max(1, state.totalActivities + 1)) * 100);
                    stepsUi.setProgress(activityProgress);
                    break;
                }
                case 'section': {
                    contentReceived = true;
                    planningUi.addSectionHeader({
                        section_index: data.section_index ?? state.planSectionsData.length,
                        name: data.section?.name || data.name || texts.wizard_plan_default_unnamed,
                        description: data.section?.description || data.description || '',
                        activity_count: (data.section?.activities || data.activities || []).length
                    });
                    (data.section?.activities || data.activities || []).forEach((activity) => {
                        planningUi.addActivityToSection({
                            section_index: data.section_index ?? (state.planSectionsData.length - 1),
                            activity_type: activity.type || activity.activity_type,
                            title: activity.name || activity.title,
                            description: activity.description || ''
                        });
                    });
                    stepsUi.switchPlanMode('sections');
                    planningUi.addPlanSection(data.section || {
                        name: data.name || texts.wizard_plan_default_unnamed,
                        description: data.description || '',
                        activities: data.activities || []
                    });
                    // Update progress based on activities received (cap at 95%)
                    const sectionProgress = Math.min(95, (state.totalActivities / Math.max(1, state.totalActivities + 5)) * 100);
                    stepsUi.setProgress(sectionProgress);
                    break;
                }
                case 'detailed_plan_start':
                    contentReceived = true;
                    detailedUi.initDetailedPlanView(data);
                    break;
                case 'detailed_plan_field':
                    contentReceived = true;
                    detailedUi.handleDetailedPlanField(data);
                    break;
                case 'detailed_plan_activity':
                    contentReceived = true;
                    detailedUi.handleDetailedPlanActivity(data);
                    break;
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

                    if (state.planningMode === 'detailed' && planReviewCard && planReviewCard.style.display !== 'none') {
                        if (prvHeaderSub) {
                            prvHeaderSub.textContent = statusText;
                        }
                        if (prvLiveNote) {
                            prvLiveNote.style.display = 'block';
                            prvLiveNote.textContent = texts.wizard_live_note_detailed;
                        }
                    } else if (pcSubtitle) {
                        pcSubtitle.textContent = statusText;
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
                        pcStep.textContent = texts.wizard_state_error;
                    }
                    if (pcSubtitle) {
                        pcSubtitle.textContent = data.message || texts.wizard_error_generic;
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
                pcStep.textContent = texts.wizard_state_error;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.wizard_error_connection;
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
