/* eslint-disable */
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
 * AI Course Creation Wizard - Step 1: Context
 *
 * @module     local_coursegen/wizard
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import * as WizardRepository from 'local_coursegen/repository/wizard';
import {get_string as getString} from 'core/str';
import YUI from 'core/yui';
import * as markedModule from 'local_coursegen/marked';
import {sendPlanningFeedback, createCourse} from 'local_coursegen/repository/course';

/**
 * Initialize the wizard page
 * @param {Object} params - Initialization parameters
 */
export const init = async(params) => {
    try {
        window.console.log('Wizard initialized', params);

        // Get guidelines and languages from params or from DOM
        let guidelines = params?.guidelines || [];
        let languages = params?.languages || [];
        const defaultLang = params?.defaultlang || 'es';

        // If empty, try to get from DOM data attributes
        if (guidelines.length === 0 || languages.length === 0) {
            const dataEl = document.getElementById('wizard-data');
            if (dataEl) {
                try {
                    const guidelinesData = dataEl.getAttribute('data-guidelines');
                    const languagesData = dataEl.getAttribute('data-languages');
                    
                    if (guidelinesData) {
                        guidelines = JSON.parse(guidelinesData);
                    }
                    if (languagesData) {
                        languages = JSON.parse(languagesData);
                    }
                } catch (error) {
                    window.console.error('Error parsing wizard data:', error);
                }
            }
        }

        // DOM elements
        const promptInput = document.getElementById('promptInput');
        const btnGenerate = document.getElementById('btnGenerate');
        const btnSyllabus = document.getElementById('btnSyllabus');
        const syllabusFile = document.getElementById('syllabusFile');
        const btnDirectrices = document.getElementById('btnDirectrices');
        const guidelinesPopover = document.getElementById('guidelinesPopover');
        const guidelineSearch = document.getElementById('guidelineSearch');
        const guidelineList = document.getElementById('guidelineList');
        const langSelect = document.getElementById('langSelect');
        const btnWithImages = document.getElementById('btnWithImages');
        const imgToggleWrap = btnWithImages ? btnWithImages.closest('label') : null;
        const contextView = document.getElementById('contextView');
        const planningView = document.getElementById('planningView');
        const planningSpinner = document.getElementById('planningSpinner');
        const pcStep = document.getElementById('pcStep');
        const pcTitle = document.getElementById('pcTitle');
        const pcSubtitle = document.getElementById('pcSubtitle');
        const pcPct = document.getElementById('pcPct');
        const pcBarFill = document.getElementById('pcBarFill');
        const pcToggleRow = document.getElementById('pcToggleRow');
        const pcToggleBtn = document.getElementById('pcToggleBtn');
        const pcChevron = document.getElementById('pcChevron');
        const pcDetailsPanel = document.getElementById('pcDetailsPanel');
        const planSectionsView = document.getElementById('planSectionsView');
        const planSectionsList = document.getElementById('planSectionsList');
        const planDetailedView = document.getElementById('planDetailedView');
        const planDetailedList = document.getElementById('planDetailedList');
        const planMarkdownView = document.getElementById('planMarkdownView');
        const planMarkdown = document.getElementById('planMarkdown');
        const typingCursor = document.getElementById('typingCursor');
        const planReviewCard = document.getElementById('planReviewCard');
        const prvHeader = document.getElementById('prvHeader');
        const prvHeaderTitle = document.getElementById('prvHeaderTitle');
        const prvHeaderSub = document.getElementById('prvHeaderSub');
        const prvLiveNote = document.getElementById('prvLiveNote');
        const prvSections = document.getElementById('prvSections');
        const prvSpinnerIcon = document.getElementById('prvSpinnerIcon');
        const prvCheckIcon = document.getElementById('prvCheckIcon');
        const planActions = document.getElementById('planActions');
        const planActionsHint = document.getElementById('planActionsHint');
        const adjustPanel = document.getElementById('adjustPanel');
        const adjustInput = document.getElementById('adjustInput');
        const btnApprove = document.getElementById('btnApprove');
        const btnAdjust = document.getElementById('btnAdjust');
        const btnAdjustCancel = document.getElementById('btnAdjustCancel');
        const btnAdjustSend = document.getElementById('btnAdjustSend');
        const btnBackFlow = document.getElementById('btnBackFlow');
        const btnCancelFlow = document.getElementById('btnCancelFlow');

        // State
        const state = {
            syllabusFile: null,
            syllabusFilename: null,
            draftitemid: null,
            sessionid: 0,
            threadid: '',
            streamingurl: '',
            selectedGuidelineId: null,
            guidelinePopoverOpen: false,
            guidelineSearchQuery: '',
            lang: defaultLang,
            withImages: false,
            guidelines: guidelines,
            languages: languages,
            sseSource: null,
            planningMode: null,
            planBuffer: '',
            planDetailsOpen: false,
            totalSections: 0,
            totalActivities: 0,
            latestInitialSections: [],
            detailedTotal: 0,
            detailedCurrent: 0,
            planSectionsData: [],
            detailedActivityEls: {},
            detailedSectionMeta: {},
            currentStage: 'planning'
        };

        window.console.log('Wizard state:', state);

        // Populate language selector
        if (langSelect && languages.length > 0) {
            langSelect.innerHTML = languages.map(lang => 
                `<option value="${lang.code}" ${lang.code === defaultLang ? 'selected' : ''}>🌐 ${lang.code.toUpperCase()}</option>`
            ).join('');
        }

        // Render guidelines list
        const renderGuidelineList = () => {
            if (!guidelineList) {
                return;
            }

            const query = state.guidelineSearchQuery.toLowerCase();
            const filtered = state.guidelines.filter(g => 
                g.name.toLowerCase().includes(query) || 
                (g.category && g.category.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                guidelineList.innerHTML = '<li class="pop-empty">Sin resultados</li>';
                return;
            }

            guidelineList.innerHTML = filtered.map(g => {
                const isSelected = state.selectedGuidelineId === g.id;
                return `
                    <li class="pop-item${isSelected ? ' selected' : ''}" data-id="${g.id}">
                        <button class="pop-select-btn" data-select="${g.id}" type="button">
                            <div class="pop-radio"><div class="pop-dot"></div></div>
                            <div class="pop-item-text">
                                <span class="pop-item-name">${escapeHtml(g.name)}</span>
                                <span class="pop-item-cat">${escapeHtml(g.category || 'General')}</span>
                            </div>
                        </button>
                        <button class="pop-eye-btn" data-preview="${g.id}" type="button" title="Ver directriz">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </li>
                `;
            }).join('');

            // Attach event listeners
            guidelineList.querySelectorAll('.pop-select-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-select');
                    selectGuideline(id);
                });
            });

            guidelineList.querySelectorAll('.pop-eye-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const id = btn.getAttribute('data-preview');
                    showGuidelinePreview(id);
                });
            });
        };

        // Select guideline
        const selectGuideline = (id) => {
            if (state.selectedGuidelineId === id) {
                state.selectedGuidelineId = null;
            } else {
                state.selectedGuidelineId = id;
                closeGuidelinePopover();
            }
            refreshGuidelineChip();
            renderGuidelineList();
        };

        // Close popover
        const closeGuidelinePopover = () => {
            state.guidelinePopoverOpen = false;
            if (guidelinesPopover) {
                guidelinesPopover.classList.remove('open');
            }
        };

        // Refresh guideline chip
        const refreshGuidelineChip = () => {
            const chipGuideline = document.getElementById('chipGuideline');
            const chipGuidelineName = document.getElementById('chipGuidelineName');
            const guidelineBadge = document.getElementById('guidelineBadge');

            if (!chipGuideline) {
                return;
            }

            if (state.selectedGuidelineId) {
                const guideline = state.guidelines.find(g => g.id === state.selectedGuidelineId);
                if (guideline && chipGuidelineName) {
                    chipGuidelineName.textContent = guideline.name;
                    chipGuideline.classList.remove('hidden');
                    if (guidelineBadge) {
                        guidelineBadge.textContent = '1';
                        guidelineBadge.classList.remove('hidden');
                    }
                }
            } else {
                chipGuideline.classList.add('hidden');
                if (guidelineBadge) {
                    guidelineBadge.classList.add('hidden');
                }
            }
            refreshChipsRow();
        };

        // Refresh chips row visibility
        const refreshChipsRow = () => {
            const chipsRow = document.getElementById('chipsRow');
            const chipSyllabus = document.getElementById('chipSyllabus');
            const chipGuideline = document.getElementById('chipGuideline');
            
            if (!chipsRow) {
                return;
            }

            const hasSyllabus = chipSyllabus && !chipSyllabus.classList.contains('hidden');
            const hasGuideline = chipGuideline && !chipGuideline.classList.contains('hidden');
            chipsRow.style.display = (hasSyllabus || hasGuideline) ? 'flex' : 'none';
        };

        // Show guideline preview modal
        const showGuidelinePreview = (id) => {
            const guideline = state.guidelines.find(g => g.id === id);
            if (!guideline) {
                return;
            }

            const modalLabel = document.getElementById('previewModalLabel');
            const modalCategory = document.getElementById('previewModalCategory');
            const modalBody = document.getElementById('previewModalBody');

            if (modalLabel) {
                modalLabel.textContent = guideline.name;
            }
            if (modalCategory) {
                modalCategory.textContent = guideline.category || 'General';
            }
            if (modalBody) {
                modalBody.textContent = guideline.description || '';
            }

            // Show modal using jQuery (Bootstrap 4)
            if (window.$ && window.$('#guidelinePreviewModal').length) {
                window.$('#guidelinePreviewModal').modal('show');
            }
        };

        // HTML escape utility
        const escapeHtml = (str) => {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        // Update generate button state
        const updateGenerateButton = () => {
            if (btnGenerate && promptInput) {
                btnGenerate.disabled = promptInput.value.trim().length < 10;
            }
        };

        // Event: Directrices button click
        if (btnDirectrices && guidelinesPopover) {
            btnDirectrices.addEventListener('click', (e) => {
                e.stopPropagation();
                state.guidelinePopoverOpen = !state.guidelinePopoverOpen;
                guidelinesPopover.classList.toggle('open', state.guidelinePopoverOpen);
                
                if (state.guidelinePopoverOpen && guidelineSearch) {
                    guidelineSearch.value = '';
                    state.guidelineSearchQuery = '';
                    renderGuidelineList();
                    guidelineSearch.focus();
                }
            });
        }

        // Event: Click outside popover to close
        document.addEventListener('click', (e) => {
            if (state.guidelinePopoverOpen && 
                guidelinesPopover && 
                !guidelinesPopover.contains(e.target) && 
                e.target !== btnDirectrices) {
                closeGuidelinePopover();
            }
        });

        // Event: Guideline search input
        if (guidelineSearch) {
            guidelineSearch.addEventListener('input', () => {
                state.guidelineSearchQuery = guidelineSearch.value;
                renderGuidelineList();
            });
        }

        // Event: Syllabus button click - opens Moodle file picker
        if (btnSyllabus) {
            btnSyllabus.addEventListener('click', async() => {
                await showFilePicker();
            });
        }

        /**
         * Show Moodle file picker for syllabus upload (same pattern as chat_form.js)
         */
        const showFilePicker = async() => {
            try {
                // Initialize filepicker via webservice (same as activity filepicker)
                const pickerdata = await WizardRepository.initFilepicker();
                
                if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
                    window.console.error('Failed to initialize filepicker');
                    return;
                }

                const pickerOptions = JSON.parse(pickerdata.options);
                const clientIdKey = 'client_id';
                pickerOptions[clientIdKey] = pickerdata.clientid;
                pickerOptions.itemid = pickerdata.draftitemid;

                // Use YUI to show the file picker (legacy Moodle approach)
                YUI.use('core_filepicker', 'node', 'node-event-simulate', 'core_dndupload', (Y) => {
                    // Set templates if available
                    if (pickerdata.templates) {
                        try {
                            const templates = JSON.parse(pickerdata.templates);
                            if (templates && typeof templates === 'object') {
                                M.core_filepicker.set_templates(Y, templates);
                            }
                        } catch (ex) {
                            // Ignore template errors
                        }
                    }

                    // Set callback for when file is selected
                    pickerOptions.formcallback = (fileinfo) => {
                        if (fileinfo && fileinfo.file) {
                            const filename = String(fileinfo.file);
                            
                            // Update state
                            state.syllabusFilename = filename;
                            state.draftitemid = pickerOptions.itemid;
                            
                            // Update UI
                            const chipSyllabus = document.getElementById('chipSyllabus');
                            const chipSyllabusName = document.getElementById('chipSyllabusName');
                            
                            if (chipSyllabusName) {
                                chipSyllabusName.textContent = filename;
                            }
                            if (chipSyllabus) {
                                chipSyllabus.classList.remove('hidden');
                            }
                            refreshChipsRow();
                        }
                    };

                    // Initialize and show file picker
                    if (!M.core_filepicker.instances[pickerOptions[clientIdKey]]) {
                        M.core_filepicker.init(Y, pickerOptions);
                    }

                    M.core_filepicker.instances[pickerOptions[clientIdKey]].show();
                });

            } catch (error) {
                window.console.error('Error showing file picker:', error);
                await Notification.exception(error);
            }
        };

        // Event: Language selector change
        if (langSelect) {
            langSelect.addEventListener('change', () => {
                state.lang = langSelect.value;
            });
        }

        // Event: Images toggle
        if (btnWithImages && imgToggleWrap) {
            btnWithImages.addEventListener('change', () => {
                state.withImages = btnWithImages.checked;
                imgToggleWrap.classList.toggle('on', state.withImages);
            });
        }

        const markedParser = markedModule.parse ? markedModule : markedModule.marked;
        const activityLabels = {
            quiz: 'Quiz',
            book: 'Libro',
            assign: 'Tarea',
            forum: 'Foro',
            lesson: 'Leccion',
            url: 'Enlace',
            resource: 'Recurso',
            page: 'Pagina',
            data: 'Base de datos',
            glossary: 'Glosario'
        };

        const setProgress = (pct) => {
            const clamped = Math.min(100, Math.max(0, Math.round(pct)));
            if (pcPct) {
                pcPct.textContent = `${clamped}%`;
            }
            if (pcBarFill) {
                pcBarFill.style.width = `${clamped}%`;
            }
        };

        const setStepState = (step, stateName) => {
            const stepEl = document.querySelector(`[data-step="${step}"]`);
            if (!stepEl) {
                return;
            }
            stepEl.classList.remove('active', 'pending', 'done');
            stepEl.classList.add(stateName);
        };

        const renderGenerateButtonDefault = () => {
            if (!btnGenerate) {
                return;
            }
            btnGenerate.disabled = false;
            btnGenerate.innerHTML = `
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"/>
                </svg>
                Generar
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 19V5M5 12l7-7 7 7"/>
                </svg>
            `;
        };

        const updateFlowNav = () => {
            if (!btnBackFlow || !btnCancelFlow) {
                return;
            }
            if (state.currentStage === 'planning') {
                btnBackFlow.style.display = '';
                btnBackFlow.textContent = '← Volver al contexto';
                btnCancelFlow.textContent = 'Cancelar';
            } else if (state.currentStage === 'detailed') {
                btnBackFlow.style.display = '';
                btnBackFlow.textContent = '← Volver a planificación inicial';
                btnCancelFlow.textContent = 'Cancelar';
            } else {
                btnBackFlow.style.display = 'none';
                btnCancelFlow.textContent = 'Cancelar y salir';
            }
        };

        const backToContext = () => {
            closeStream();
            state.currentStage = 'planning';
            if (planningView) {
                planningView.style.display = 'none';
            }
            if (contextView) {
                contextView.style.display = '';
            }
            setStepState('context', 'active');
            setStepState('planning', 'pending');
            setStepState('detailed', 'pending');
            setStepState('generating', 'pending');
            resetPlanningState();
            renderGenerateButtonDefault();
            updateFlowNav();
        };

        const transitionToPlanning = () => {
            setStepState('context', 'done');
            setStepState('planning', 'active');
            state.currentStage = 'planning';
            if (contextView) {
                contextView.style.display = 'none';
            }
            if (planningView) {
                planningView.style.display = 'flex';
            }
            updateFlowNav();
        };

        const switchPlanMode = (mode) => {
            if (state.planningMode === mode) {
                return;
            }
            state.planningMode = mode;
            if (planSectionsView) {
                planSectionsView.style.display = mode === 'sections' ? 'block' : 'none';
            }
            if (planDetailedView) {
                planDetailedView.style.display = mode === 'detailed' ? 'block' : 'none';
            }
            if (planMarkdownView) {
                planMarkdownView.style.display = mode === 'markdown' ? 'block' : 'none';
            }
        };

        const resetPlanningState = () => {
            state.planBuffer = '';
            state.planningMode = null;
            state.planDetailsOpen = false;
            state.totalSections = 0;
            state.totalActivities = 0;

            if (planMarkdown) {
                planMarkdown.innerHTML = '';
            }
            if (planSectionsList) {
                planSectionsList.innerHTML = '';
            }
            if (planDetailedList) {
                planDetailedList.innerHTML = '';
            }
            if (prvSections) {
                prvSections.innerHTML = '';
            }
            if (planSectionsView) {
                planSectionsView.style.display = 'none';
            }
            if (planDetailedView) {
                planDetailedView.style.display = 'none';
            }
            if (planMarkdownView) {
                planMarkdownView.style.display = 'none';
            }
            if (planReviewCard) {
                planReviewCard.style.display = 'none';
            }
            if (planActions) {
                planActions.style.display = 'none';
            }
            if (adjustPanel) {
                adjustPanel.style.display = 'none';
            }
            if (pcDetailsPanel) {
                pcDetailsPanel.style.display = 'none';
            }
            if (pcToggleRow) {
                pcToggleRow.style.display = 'none';
            }
            if (pcChevron) {
                pcChevron.style.transform = 'rotate(0deg)';
            }
            if (prvLiveNote) {
                prvLiveNote.style.display = 'none';
                prvLiveNote.textContent = '';
            }
            if (typingCursor) {
                typingCursor.classList.remove('hidden');
            }
            if (planningSpinner) {
                planningSpinner.classList.remove('done');
            }
            if (prvHeader) {
                prvHeader.classList.remove('prv-header--done');
                prvHeader.classList.add('prv-header--stream');
            }
            if (prvHeaderTitle) {
                prvHeaderTitle.textContent = 'Diseñando la estructura del curso';
            }
            if (prvHeaderSub) {
                prvHeaderSub.textContent = 'Iniciando...';
            }
            if (prvSpinnerIcon) {
                prvSpinnerIcon.style.display = '';
            }
            if (prvCheckIcon) {
                prvCheckIcon.style.display = 'none';
            }
            if (pcStep) {
                pcStep.textContent = 'Planificando...';
            }
            if (pcTitle) {
                pcTitle.textContent = 'Disenando la estructura del curso';
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = '';
            }
            setProgress(0);

            state.latestInitialSections = [];
            state.detailedTotal = 0;
            state.detailedCurrent = 0;
            state.planSectionsData = [];
            state.detailedActivityEls = {};
            state.detailedSectionMeta = {};
        };

        const escapeHtmlString = (str) => {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const renderPlanMarkdown = () => {
            if (!planMarkdown) {
                return;
            }
            const html = markedParser.parse ? markedParser.parse(state.planBuffer || '') : '';
            planMarkdown.innerHTML = html;
            if (pcDetailsPanel && state.planDetailsOpen) {
                pcDetailsPanel.scrollTop = pcDetailsPanel.scrollHeight;
            }
        };

        const addPlanSection = (section) => {
            if (!section || !planSectionsList) {
                return;
            }

            const activities = Array.isArray(section.activities) ? section.activities : [];
            state.totalSections += 1;
            state.totalActivities += activities.length;

            if (pcStep) {
                pcStep.textContent = `SECCION ${state.totalSections} · ${state.totalActivities} ACTIVIDADES`;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = `Anadiendo: ${section.name || ''}`;
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
                        <h3 class="ps-section-name">${escapeHtmlString(section.name || '')}</h3>
                        <p class="ps-section-desc">${escapeHtmlString(section.description || '')}</p>
                    </div>
                    <span class="ps-section-count">${activities.length} actividades</span>
                </div>
                <ul class="ps-activities">
                    ${activities.map((activity) => `
                        <li class="ps-activity">
                            <span class="ps-badge ps-badge--${escapeHtmlString(activity.type || 'resource')}">${escapeHtmlString(activityLabels[activity.type] || activity.type || 'Actividad')}</span>
                            <div class="ps-activity-info">
                                <span class="ps-activity-name">${escapeHtmlString(activity.name || '')}</span>
                                <span class="ps-activity-desc">${escapeHtmlString(activity.description || '')}</span>
                            </div>
                        </li>
                    `).join('')}
                </ul>
            `;
            planSectionsList.appendChild(sectionEl);
        };

        const normalizeInitialSections = (sections) => {
            return (sections || []).map((section, sectionidx) => ({
                id: section.id || `s${sectionidx}`,
                section_index: section.section_index ?? sectionidx,
                name: section.name || `Seccion ${sectionidx + 1}`,
                description: section.description || '',
                activities: (section.activities || []).map((activity, activityidx) => ({
                    id: activity.id || `s${sectionidx}-a${activityidx}`,
                    activity_type: activity.activity_type || activity.type || 'quiz',
                    title: activity.title || activity.name || `Actividad ${activityidx + 1}`,
                    description: activity.description || ''
                }))
            }));
        };

        const createDetailedSectionRow = ({sectionIndex, renderIndex, sectionName, totalActivities}) => {
            if (!prvSections) {
                return null;
            }

            const metaEl = document.createElement('p');
            metaEl.className = 'prv-section-meta';
            metaEl.textContent = `0/${totalActivities} planificadas`;

            const bodyEl = document.createElement('div');
            bodyEl.className = 'prv-section-body';
            bodyEl.style.display = 'none';

            const chevronEl = document.createElement('span');
            chevronEl.className = 'prv-chevron';
            chevronEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'prv-section-btn';
            btn.innerHTML = `<span class="prv-section-badge">${renderIndex + 1}</span>`;

            const infoDiv = document.createElement('div');
            infoDiv.className = 'prv-section-info';

            const titleEl = document.createElement('p');
            titleEl.className = 'prv-section-title';
            titleEl.textContent = sectionName || `Seccion ${renderIndex + 1}`;

            infoDiv.appendChild(titleEl);
            infoDiv.appendChild(metaEl);
            btn.appendChild(infoDiv);
            btn.appendChild(chevronEl);

            btn.addEventListener('click', () => {
                const isOpen = bodyEl.style.display !== 'none';
                bodyEl.style.display = isOpen ? 'none' : 'flex';
                chevronEl.classList.toggle('prv-chevron--open', !isOpen);
            });

            const row = document.createElement('div');
            row.className = 'prv-section-row';
            row.appendChild(btn);
            row.appendChild(bodyEl);
            prvSections.appendChild(row);

            state.detailedSectionMeta[sectionIndex] = {
                done: 0,
                total: totalActivities,
                metaEl,
                bodyEl,
                row
            };

            return {bodyEl};
        };

        const createDetailedActivityRow = ({sectionIndex, activityIndex, activityType, activityTitle, bodyEl}) => {
            const item = document.createElement('div');
            item.className = 'prv-activity-item prv-activity-item--pending';
            item.innerHTML = `<span class="ps-badge ps-badge--${escapeHtmlString(activityType)}">${escapeHtmlString(activityLabels[activityType] || activityType)}</span><div class="prv-activity-text"><p class="prv-activity-name">${escapeHtmlString(activityTitle)}</p></div>`;

            const wrap = document.createElement('div');
            wrap.className = 'dp-activity-wrap';
            wrap.appendChild(item);
            bodyEl.appendChild(wrap);

            const textDiv = item.querySelector('.prv-activity-text');
            const progressEl = document.createElement('p');
            progressEl.className = 'prv-activity-desc';
            progressEl.textContent = 'Generando detalles...';
            textDiv.appendChild(progressEl);

            const key = `${sectionIndex}-${activityIndex}`;
            state.detailedActivityEls[key] = {
                item,
                wrap,
                textDiv,
                progressEl,
                previewDescription: '',
                chapterCount: 0,
                questionCount: 0,
                done: false
            };

            return state.detailedActivityEls[key];
        };

        const ensureDetailedSection = (sectionIndex) => {
            let meta = state.detailedSectionMeta[sectionIndex];
            if (meta) {
                return meta;
            }

            const renderIndex = Object.keys(state.detailedSectionMeta).length;
            createDetailedSectionRow({
                sectionIndex,
                renderIndex,
                sectionName: `Seccion ${sectionIndex + 1}`,
                totalActivities: 0
            });
            meta = state.detailedSectionMeta[sectionIndex];
            if (meta) {
                meta.bodyEl.style.display = 'flex';
            }
            return meta;
        };

        const ensureDetailedEntry = (data) => {
            const key = `${data.section_index}-${data.activity_index}`;
            if (state.detailedActivityEls[key]) {
                return state.detailedActivityEls[key];
            }

            const meta = ensureDetailedSection(data.section_index);
            if (!meta) {
                return null;
            }

            meta.total += 1;
            meta.metaEl.textContent = `${meta.done}/${meta.total} planificadas`;

            return createDetailedActivityRow({
                sectionIndex: data.section_index,
                activityIndex: data.activity_index,
                activityType: data.activity_type || 'quiz',
                activityTitle: data.title || `Actividad ${data.activity_index + 1}`,
                bodyEl: meta.bodyEl
            });
        };

        const initDetailedPlanView = (data) => {
            let sourceSections = normalizeInitialSections(data?.sections || []);
            if (sourceSections.length === 0) {
                sourceSections = normalizeInitialSections(state.latestInitialSections || []);
            }

            if (prvSections) {
                prvSections.innerHTML = '';
            }
            state.detailedActivityEls = {};
            state.detailedSectionMeta = {};
            state.detailedCurrent = 0;
            state.detailedTotal = data?.total_activities ?? sourceSections.reduce((acc, section) => acc + (section.activities || []).length, 0);

            switchPlanMode('detailed');
            if (planReviewCard) {
                planReviewCard.style.display = '';
            }
            if (prvLiveNote) {
                prvLiveNote.style.display = 'block';
                prvLiveNote.textContent = 'Mostrando avance en tiempo real de la planificacion detallada.';
            }
            if (prvSpinnerIcon) {
                prvSpinnerIcon.style.display = '';
            }
            if (prvCheckIcon) {
                prvCheckIcon.style.display = 'none';
            }
            if (prvHeader) {
                prvHeader.classList.remove('prv-header--done');
                prvHeader.classList.add('prv-header--stream');
            }
            if (prvHeaderTitle) {
                prvHeaderTitle.textContent = 'Planificando contenido del curso';
            }
            if (prvHeaderSub) {
                prvHeaderSub.textContent = `0 de ${state.detailedTotal} actividades planificadas`;
            }
            if (planningSpinner) {
                planningSpinner.classList.remove('done');
            }

            sourceSections.forEach((section, renderIdx) => {
                const sectionIndex = section.section_index ?? renderIdx;
                const sectionRow = createDetailedSectionRow({
                    sectionIndex,
                    renderIndex: renderIdx,
                    sectionName: section.name,
                    totalActivities: (section.activities || []).length
                });
                if (!sectionRow) {
                    return;
                }

                (section.activities || []).forEach((activity, activityIdx) => {
                    createDetailedActivityRow({
                        sectionIndex,
                        activityIndex: activityIdx,
                        activityType: activity.activity_type || activity.type || 'quiz',
                        activityTitle: activity.title || activity.name || `Actividad ${activityIdx + 1}`,
                        bodyEl: sectionRow.bodyEl
                    });
                });
            });
        };

        const handleDetailedPlanField = (data) => {
            if (state.planningMode !== 'detailed') {
                initDetailedPlanView({sections: state.latestInitialSections});
            }

            const entry = ensureDetailedEntry(data);
            if (!entry || entry.done) {
                return;
            }

            if (data.field === 'activity_description' && typeof data.value === 'string') {
                entry.previewDescription = data.value.trim();
            } else if (data.field === 'chapters' && data.item) {
                entry.chapterCount += 1;
            } else if (data.field === 'questions' && data.item) {
                entry.questionCount += 1;
            } else if (data.field === 'details' && typeof data.value === 'string' && !entry.previewDescription) {
                entry.previewDescription = data.value.trim();
            }

            const summary = [];
            if (entry.chapterCount > 0) {
                summary.push(`${entry.chapterCount} capitulos`);
            }
            if (entry.questionCount > 0) {
                summary.push(`${entry.questionCount} preguntas`);
            }
            let text = entry.previewDescription || 'Generando detalles...';
            if (summary.length > 0) {
                text = `${text} (${summary.join(' · ')})`;
            }
            if (entry.progressEl) {
                entry.progressEl.textContent = text;
            }
            if (prvHeaderSub) {
                prvHeaderSub.textContent = `Planificando: ${data.title || 'actividad'}`;
            }
        };

        const markActivityPlanned = (data) => {
            const entry = ensureDetailedEntry(data);
            if (!entry || entry.done) {
                return;
            }

            state.detailedCurrent += 1;
            entry.done = true;
            entry.item.classList.remove('prv-activity-item--pending');
            entry.item.classList.add('prv-activity-item--done');

            if (entry.progressEl) {
                entry.progressEl.remove();
                entry.progressEl = null;
            }

            const parsed = data.data || {};
            const descriptionText = parsed.activity_description || entry.previewDescription || '';
            if (descriptionText) {
                const desc = document.createElement('p');
                desc.className = 'prv-activity-desc';
                desc.textContent = descriptionText;
                entry.textDiv.appendChild(desc);
            }

            const meta = state.detailedSectionMeta[data.section_index];
            if (meta) {
                meta.done += 1;
                meta.metaEl.textContent = `${meta.done}/${meta.total} planificadas`;
            }
            if (prvHeaderSub) {
                prvHeaderSub.textContent = `${state.detailedCurrent} de ${state.detailedTotal} actividades planificadas`;
            }
        };

        const handleDetailedPlanActivity = (data) => {
            if (state.planningMode !== 'detailed') {
                initDetailedPlanView({sections: state.latestInitialSections});
            }
            markActivityPlanned(data);
        };

        const addSectionHeader = (sectionData) => {
            if (!prvSections) {
                return;
            }

            state.totalSections += 1;
            const metaEl = document.createElement('p');
            metaEl.className = 'prv-section-meta';
            metaEl.textContent = sectionData.activity_count != null
                ? `0/${sectionData.activity_count} actividades · ${sectionData.description || ''}`
                : `Actividades · ${sectionData.description || ''}`;

            const body = document.createElement('div');
            body.className = 'prv-section-body';
            body.style.display = 'flex';

            const chevronEl = document.createElement('span');
            chevronEl.className = 'prv-chevron prv-chevron--open';
            chevronEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'prv-section-btn';
            btn.innerHTML = `<span class="prv-section-badge">${state.totalSections}</span>`;

            const infoDiv = document.createElement('div');
            infoDiv.className = 'prv-section-info';
            const titleEl = document.createElement('p');
            titleEl.className = 'prv-section-title';
            titleEl.textContent = sectionData.name || '(unnamed)';
            infoDiv.appendChild(titleEl);
            infoDiv.appendChild(metaEl);
            btn.appendChild(infoDiv);
            btn.appendChild(chevronEl);
            btn.addEventListener('click', () => {
                const isOpen = body.style.display !== 'none';
                body.style.display = isOpen ? 'none' : 'flex';
                chevronEl.classList.toggle('prv-chevron--open', !isOpen);
            });

            const row = document.createElement('div');
            row.className = 'prv-section-row';
            row.appendChild(btn);
            row.appendChild(body);
            prvSections.appendChild(row);

            state.planSectionsData.push({
                sectionIndex: sectionData.section_index,
                name: sectionData.name || '(unnamed)',
                description: sectionData.description || '',
                activityCount: sectionData.activity_count,
                activities: [],
                metaEl,
                bodyEl: body,
                chevronEl
            });

            if (prvHeaderSub) {
                prvHeaderSub.textContent = `Seccion ${state.totalSections}: ${sectionData.name || '(unnamed)'}`;
            }
            if (planReviewCard) {
                planReviewCard.style.display = '';
            }
        };

        const addActivityToSection = (data) => {
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
            if (sectionEntry.activityCount != null) {
                sectionEntry.metaEl.textContent = `${done}/${sectionEntry.activityCount} actividades · ${sectionEntry.description}`;
            } else {
                sectionEntry.metaEl.textContent = `${done} actividades · ${sectionEntry.description}`;
            }

            const activityItem = document.createElement('div');
            activityItem.className = 'prv-activity-item';
            const activityType = data.activity_type || data.type || 'quiz';
            const activityName = data.title || data.name || 'Actividad';
            activityItem.innerHTML = `<span class="ps-badge ps-badge--${escapeHtmlString(activityType)}">${escapeHtmlString(activityLabels[activityType] || activityType)}</span><div class="prv-activity-text"><p class="prv-activity-name">${escapeHtmlString(activityName)}</p><p class="prv-activity-desc">${escapeHtmlString(data.description || '')}</p></div>`;
            sectionEntry.bodyEl.appendChild(activityItem);

            if (prvHeaderSub) {
                prvHeaderSub.textContent = `Anadiendo: ${activityName}`;
            }
        };

        const buildReviewCard = (sections) => {
            state.latestInitialSections = normalizeInitialSections(sections || []);
            if (state.latestInitialSections.length === 0) {
                return;
            }
            if (prvSpinnerIcon) {
                prvSpinnerIcon.style.display = 'none';
            }
            if (prvCheckIcon) {
                prvCheckIcon.style.display = '';
            }
            if (prvHeader) {
                prvHeader.classList.remove('prv-header--stream');
                prvHeader.classList.add('prv-header--done');
            }
            if (prvHeaderTitle) {
                prvHeaderTitle.textContent = 'Planificacion inicial lista';
            }

            const sectionCount = state.latestInitialSections.length;
            const activityCount = state.latestInitialSections.reduce((acc, section) => {
                return acc + (section.activities || []).length;
            }, 0);
            state.totalSections = sectionCount;
            state.totalActivities = activityCount;

            if (prvHeaderSub) {
                prvHeaderSub.textContent = `${sectionCount} secciones · ${activityCount} actividades.`;
            }
            if (planReviewCard) {
                planReviewCard.style.display = '';
            }
        };

        const showReviewActions = (mode) => {
            if (planningSpinner) {
                planningSpinner.classList.add('done');
            }
            if (typingCursor) {
                typingCursor.classList.add('hidden');
            }
            setProgress(100);

            if (mode === 'initial') {
                if (pcStep) {
                    pcStep.textContent = `${state.totalSections} SECCIONES · ${state.totalActivities} ACTIVIDADES`;
                }
                if (pcTitle) {
                    pcTitle.textContent = 'Estructura del curso lista';
                }
                if (pcSubtitle) {
                    pcSubtitle.textContent = 'Revisa las secciones y aproba para continuar.';
                }
                if (planActionsHint) {
                    planActionsHint.textContent = 'Aproba la estructura para generar el plan detallado, o solicita ajustes a la IA.';
                }
                if (planReviewCard) {
                    planReviewCard.style.display = '';
                }
            } else if (mode === 'detailed') {
                if (prvSpinnerIcon) {
                    prvSpinnerIcon.style.display = 'none';
                }
                if (prvCheckIcon) {
                    prvCheckIcon.style.display = '';
                }
                if (prvHeader) {
                    prvHeader.classList.remove('prv-header--stream');
                    prvHeader.classList.add('prv-header--done');
                }
                if (prvHeaderTitle) {
                    prvHeaderTitle.textContent = 'Plan detallado listo';
                }
                if (prvHeaderSub) {
                    prvHeaderSub.textContent = `${state.detailedTotal} actividades planificadas.`;
                }
                if (prvLiveNote) {
                    prvLiveNote.style.display = 'none';
                    prvLiveNote.textContent = '';
                }
                if (planActionsHint) {
                    planActionsHint.textContent = 'Aproba el plan para comenzar a generar el contenido del curso.';
                }
                if (planReviewCard) {
                    planReviewCard.style.display = '';
                }
            } else {
                if (pcStep) {
                    pcStep.textContent = 'PLAN DETALLADO LISTO';
                }
                if (pcTitle) {
                    pcTitle.textContent = 'Plan detallado generado';
                }
                if (pcSubtitle) {
                    pcSubtitle.textContent = 'Revisa el contenido y aproba para iniciar la generacion.';
                }
                if (planActionsHint) {
                    planActionsHint.textContent = 'Aproba el plan para comenzar a generar el contenido del curso.';
                }
                if (pcToggleRow) {
                    pcToggleRow.style.display = 'flex';
                }
            }

            if (planActions) {
                planActions.style.display = 'flex';
            }
        };

        const createCourseFromSession = async() => {
            if (!state.sessionid) {
                return;
            }
            if (pcStep) {
                pcStep.textContent = 'COMPLETADO';
            }
            if (pcTitle) {
                pcTitle.textContent = 'Plan completado. Creando el curso en Moodle...';
            }
            setProgress(100);

            const result = await createCourse({recordid: state.sessionid});
            if (!result || !result.success) {
                throw new Error(result?.message || 'Error al crear el curso.');
            }
            if (result.courseurl) {
                window.location.href = result.courseurl;
            }
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
                throw new Error('No se pudo obtener la URL de streaming.');
            }
            closeStream();
            resetPlanningState();

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
                            addSectionHeader({
                                section_index: data.section_index,
                                name: data.section_name || '(unnamed)',
                                description: '',
                                activity_count: null
                            });
                        }
                        addActivityToSection(data);
                        break;
                    case 'section':
                        addSectionHeader({
                            section_index: data.section_index ?? state.planSectionsData.length,
                            name: data.section?.name || data.name || '(unnamed)',
                            description: data.section?.description || data.description || '',
                            activity_count: (data.section?.activities || data.activities || []).length
                        });
                        (data.section?.activities || data.activities || []).forEach((activity) => {
                            addActivityToSection({
                                section_index: data.section_index ?? (state.planSectionsData.length - 1),
                                activity_type: activity.type || activity.activity_type,
                                title: activity.name || activity.title,
                                description: activity.description || ''
                            });
                        });
                        switchPlanMode('sections');
                        addPlanSection(data.section || {
                            name: data.name || '(unnamed)',
                            description: data.description || '',
                            activities: data.activities || []
                        });
                        break;
                    case 'detailed_plan_start':
                        initDetailedPlanView(data);
                        break;
                    case 'detailed_plan_field':
                        handleDetailedPlanField(data);
                        break;
                    case 'detailed_plan_activity':
                        handleDetailedPlanActivity(data);
                        break;
                    case 'token':
                        switchPlanMode('markdown');
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
                                prvLiveNote.textContent = 'Mostrando avance en tiempo real de la planificacion detallada.';
                            }
                        } else if (pcSubtitle) {
                            pcSubtitle.textContent = data.text || '';
                        }
                        break;
                    case 'review_needed_initial':
                        setStepState('planning', 'active');
                        state.currentStage = 'planning';
                        updateFlowNav();
                        switchPlanMode('sections');
                        buildReviewCard(data.sections || []);
                        if (Array.isArray(data.sections) && data.sections.length > 0 && planSectionsList && !planSectionsList.children.length) {
                            data.sections.forEach((section) => addPlanSection(section));
                        }
                        showReviewActions('initial');
                        break;
                    case 'review_needed':
                        setStepState('planning', 'done');
                        setStepState('detailed', 'active');
                        state.currentStage = 'detailed';
                        updateFlowNav();
                        if (Array.isArray(data.current_plan) && data.current_plan.length > 0) {
                            initDetailedPlanView({sections: data.current_plan});
                            data.current_plan.forEach((section, sectionIndex) => {
                                (section.activities || []).forEach((activity, activityIndex) => {
                                    handleDetailedPlanActivity({
                                        section_index: sectionIndex,
                                        activity_index: activityIndex,
                                        data: activity.detailed_plan || {}
                                    });
                                });
                            });
                        }
                        showReviewActions(state.planningMode === 'detailed' ? 'detailed' : 'markdown');
                        break;
                    case 'completed':
                        setStepState('detailed', 'done');
                        setStepState('generating', 'active');
                        state.currentStage = 'generating';
                        updateFlowNav();
                        closeStream();
                        await createCourseFromSession();
                        break;
                    case 'failed':
                        setStepState('planning', 'active');
                        closeStream();
                        if (planningSpinner) {
                            planningSpinner.classList.add('done');
                        }
                        if (pcStep) {
                            pcStep.textContent = 'ERROR';
                        }
                        if (pcSubtitle) {
                            pcSubtitle.textContent = data.message || 'No se pudo generar el curso. Intenta de nuevo.';
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
                    pcStep.textContent = 'ERROR';
                }
                if (pcSubtitle) {
                    pcSubtitle.textContent = 'Error de conexion. Intenta de nuevo.';
                }
            };
        };

        // Handle generate (MUST be declared before use)
        const handleGenerate = async() => {
            const prompt = promptInput ? promptInput.value.trim() : '';
            if (prompt.length < 10) {
                if (promptInput) {
                    promptInput.focus();
                }
                return;
            }

            // Disable button and show loading state
            if (btnGenerate) {
                btnGenerate.disabled = true;
                btnGenerate.innerHTML = `
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" class="spinner">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    </svg>
                    Iniciando...
                `;
            }

            try {
                // Get system instruction ID (remove 'si_' prefix)
                let systeminstructionid = 0;
                if (state.selectedGuidelineId) {
                    const match = state.selectedGuidelineId.match(/^si_(\d+)$/);
                    if (match) {
                        systeminstructionid = parseInt(match[1], 10);
                    }
                }

                // Call wizard_init webservice
                const initResponse = await WizardRepository.initSession({
                    prompt: prompt,
                    lang: state.lang,
                    withimages: state.withImages,
                    systeminstructionid: systeminstructionid
                });

                if (!initResponse.success) {
                    throw new Error(initResponse.message || 'Error al inicializar sesión');
                }

                const sessionid = initResponse.sessionid;
                state.sessionid = sessionid;
                state.threadid = initResponse.threadid || '';
                state.streamingurl = initResponse.streamingurl || '';

                // If there's a syllabus file, upload it before redirecting
                if (state.syllabusFilename && state.draftitemid) {
                    if (btnGenerate) {
                        btnGenerate.innerHTML = `
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" class="spinner">
                                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                            </svg>
                            Subiendo sílabo...
                        `;
                    }

                    const uploadResponse = await WizardRepository.uploadSyllabus(sessionid, state.draftitemid);
                    
                    if (!uploadResponse.success) {
                        throw new Error(uploadResponse.message || 'Error al subir sílabo');
                    }
                    
                    window.console.log('Syllabus uploaded successfully:', uploadResponse.filename);
                }

                transitionToPlanning();
                openSSEStream(state.streamingurl);

            } catch (error) {
                window.console.error('Error generating course:', error);
                await Notification.exception(error);
                
                // Re-enable button
                if (btnGenerate) {
                    btnGenerate.disabled = false;
                    btnGenerate.innerHTML = `
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"/>
                        </svg>
                        Generar
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 19V5M5 12l7-7 7 7"/>
                        </svg>
                    `;
                }
            }
        };

        // Event: Prompt input
        if (promptInput) {
            promptInput.addEventListener('input', updateGenerateButton);
            
            // Enter key to generate (without Shift)
            promptInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    handleGenerate();
                }
            });
        }

        // Event: Generate button click
        if (btnGenerate) {
            btnGenerate.addEventListener('click', handleGenerate);
        }

        if (pcToggleBtn) {
            pcToggleBtn.addEventListener('click', () => {
                state.planDetailsOpen = !state.planDetailsOpen;
                if (pcDetailsPanel) {
                    pcDetailsPanel.style.display = state.planDetailsOpen ? 'block' : 'none';
                }
                if (pcChevron) {
                    pcChevron.style.transform = state.planDetailsOpen ? 'rotate(90deg)' : 'rotate(0deg)';
                }
            });
        }

        const sendFeedbackAction = async(action) => {
            if (!state.sessionid) {
                return;
            }

            const instruction = adjustInput ? adjustInput.value.trim() : '';
            if (action === 'adjust' && !instruction) {
                if (adjustInput) {
                    adjustInput.focus();
                }
                return;
            }

            if (btnApprove) {
                btnApprove.disabled = true;
            }
            if (btnAdjust) {
                btnAdjust.disabled = true;
            }
            if (btnAdjustSend) {
                btnAdjustSend.disabled = true;
            }
            if (planActions) {
                planActions.style.display = 'none';
            }
            if (adjustPanel) {
                adjustPanel.style.display = 'none';
            }
            if (planningSpinner) {
                planningSpinner.classList.remove('done');
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = action === 'accept' ? 'Aprobando plan...' : 'Enviando ajuste...';
            }

            try {
                const feedbackResponse = await sendPlanningFeedback({
                    recordid: state.sessionid,
                    action,
                    instruction
                });

                if (!feedbackResponse || !feedbackResponse.success) {
                    throw new Error(feedbackResponse?.message || 'Error al enviar feedback.');
                }

                if (action === 'accept') {
                    if (state.planningMode === 'detailed') {
                        setStepState('detailed', 'done');
                        setStepState('generating', 'active');
                        state.currentStage = 'generating';
                    } else {
                        setStepState('planning', 'done');
                        setStepState('detailed', 'active');
                        state.currentStage = 'detailed';
                    }
                    updateFlowNav();
                }

                openSSEStream(state.streamingurl);
            } catch (error) {
                await Notification.exception(error);
            } finally {
                if (btnApprove) {
                    btnApprove.disabled = false;
                }
                if (btnAdjust) {
                    btnAdjust.disabled = false;
                }
                if (btnAdjustSend) {
                    btnAdjustSend.disabled = false;
                }
            }
        };

        if (btnApprove) {
            btnApprove.addEventListener('click', () => {
                sendFeedbackAction('accept');
            });
        }

        if (btnAdjust) {
            btnAdjust.addEventListener('click', () => {
                if (adjustPanel) {
                    adjustPanel.style.display = 'block';
                }
                if (adjustInput) {
                    adjustInput.value = '';
                    adjustInput.focus();
                }
            });
        }

        if (btnAdjustCancel) {
            btnAdjustCancel.addEventListener('click', () => {
                if (adjustPanel) {
                    adjustPanel.style.display = 'none';
                }
            });
        }

        if (btnAdjustSend) {
            btnAdjustSend.addEventListener('click', () => {
                sendFeedbackAction('adjust');
            });
        }

        if (btnBackFlow) {
            btnBackFlow.addEventListener('click', () => {
                if (state.currentStage === 'planning') {
                    backToContext();
                    return;
                }

                if (state.currentStage === 'detailed') {
                    setStepState('planning', 'active');
                    setStepState('detailed', 'pending');
                    setStepState('generating', 'pending');
                    state.currentStage = 'planning';
                    switchPlanMode('sections');
                    showReviewActions('initial');
                    updateFlowNav();
                }
            });
        }

        if (btnCancelFlow) {
            btnCancelFlow.addEventListener('click', () => {
                backToContext();
            });
        }

        // Global function for clearing syllabus (called from template)
        window.clearSyllabus = () => {
            state.syllabusFilename = null;
            // Note: We don't reset draftitemid as Moodle manages it
            const chipSyllabus = document.getElementById('chipSyllabus');
            if (chipSyllabus) {
                chipSyllabus.classList.add('hidden');
            }
            refreshChipsRow();
        };

        // Global function for clearing guideline (called from template)
        window.clearGuideline = () => {
            state.selectedGuidelineId = null;
            refreshGuidelineChip();
        };

        // Initialize
        renderGuidelineList();
        updateFlowNav();
        updateGenerateButton();

    } catch (error) {
        Notification.exception(error);
    }
};
