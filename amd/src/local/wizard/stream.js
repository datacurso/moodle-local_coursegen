// This file is part of Moodle - http://moodle.org/

/**
 * SSE stream manager for wizard.
 *
 * @module     local_coursegen/local/wizard/stream
 */

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
        planSectionsList,
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

    const openSSEStream = (streamUrl) => {
        if (!streamUrl) {
            throw new Error(texts.wizard_error_stream_url);
        }
        closeStream();
        stepsUi.resetPlanningState();

        state.sseSource = new EventSource(streamUrl);
        state.sseSource.addEventListener('message', async(event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            switch (data.type) {
                case 'activity':
                    if (!state.planSectionsData.find((section) => section.sectionIndex === data.section_index)) {
                        planningUi.addSectionHeader({
                            section_index: data.section_index,
                            name: data.section_name || texts.wizard_plan_default_unnamed,
                            description: '',
                            activity_count: null
                        });
                    }
                    planningUi.addActivityToSection(data);
                    break;
                case 'section':
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
                    break;
                case 'detailed_plan_start':
                    detailedUi.initDetailedPlanView(data);
                    break;
                case 'detailed_plan_field':
                    detailedUi.handleDetailedPlanField(data);
                    break;
                case 'detailed_plan_activity':
                    detailedUi.handleDetailedPlanActivity(data);
                    break;
                case 'token':
                    stepsUi.switchPlanMode('markdown');
                    state.planBuffer += data.text || '';
                    renderPlanMarkdown();
                    break;
                case 'status':
                    if (state.planningMode === 'detailed' && planReviewCard && planReviewCard.style.display !== 'none') {
                        if (prvHeaderSub) {
                            prvHeaderSub.textContent = data.text || '';
                        }
                        if (prvLiveNote) {
                            prvLiveNote.style.display = 'block';
                            prvLiveNote.textContent = texts.wizard_live_note_detailed;
                        }
                    } else if (pcSubtitle) {
                        pcSubtitle.textContent = data.text || '';
                    }
                    break;
                case 'review_needed_initial':
                    stepsUi.setStepState('planning', 'active');
                    state.currentStage = 'planning';
                    stepsUi.updateFlowNav();
                    stepsUi.switchPlanMode('sections');
                    planningUi.buildReviewCard(data.sections || [], detailedUi.normalizeInitialSections);
                    if (
                        Array.isArray(data.sections) &&
                        data.sections.length > 0 &&
                        planSectionsList &&
                        !planSectionsList.children.length
                    ) {
                        data.sections.forEach((section) => planningUi.addPlanSection(section));
                    }
                    planningUi.showReviewActions('initial');
                    break;
                case 'review_needed':
                    stepsUi.setStepState('planning', 'done');
                    stepsUi.setStepState('detailed', 'active');
                    state.currentStage = 'detailed';
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
                    break;
                case 'completed':
                    setCompletionStatsFromGeneratedResult(data.result || []);
                    stepsUi.setStepState('detailed', 'done');
                    stepsUi.setStepState('generating', 'active');
                    state.currentStage = 'generating';
                    stepsUi.updateFlowNav();
                    closeStream();
                    await createCourseFromSession();
                    break;
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
                    break;
                default:
                    break;
            }
        });

        state.sseSource.addEventListener('done', () => {
            closeStream();
            if (typingCursor) {
                typingCursor.classList.add('hidden');
            }
            if (planningSpinner) {
                planningSpinner.classList.add('done');
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
                pcStep.textContent = texts.wizard_state_error;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.wizard_error_connection;
            }
        };
    };

    return {
        closeStream,
        openSSEStream,
    };
};
