// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Context step controls for wizard page.
 *
 * @module     local_coursegen/local/wizard/context_section
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Setup context step interactions.
 *
 * @param {Object} deps
 * @returns {{
 *   updateGenerateButton: Function,
 *   refreshGuidelineChip: Function,
 *   refreshChipsRow: Function,
 *   renderGuidelineList: Function
 * }}
 */
export const setupContextSection = (deps) => {
    const {
        state,
        languages,
        defaultLang,
        elements,
        Notification,
        WizardRepository,
        YUI,
        texts,
    } = deps;

    const {
        promptInput,
        btnGenerate,
        btnSyllabus,
        btnDirectrices,
        guidelinesPopover,
        guidelineSearch,
        guidelineList,
        langSelect,
        btnWithImages,
        imgToggleWrap,
    } = elements;

    const escapeHtml = (str) => {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const updateGenerateButton = () => {
        if (btnGenerate && promptInput) {
            btnGenerate.disabled = promptInput.value.trim().length < 10;
        }
    };

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

    const closeGuidelinePopover = () => {
        state.guidelinePopoverOpen = false;
        if (guidelinesPopover) {
            guidelinesPopover.classList.remove('open');
        }
    };

    const refreshGuidelineChip = () => {
        const chipGuideline = document.getElementById('chipGuideline');
        const chipGuidelineName = document.getElementById('chipGuidelineName');
        const guidelineBadge = document.getElementById('guidelineBadge');

        if (!chipGuideline) {
            return;
        }

        if (state.selectedGuidelineId) {
            const guideline = state.guidelines.find((g) => g.id === state.selectedGuidelineId);
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

    const showGuidelinePreview = (id) => {
        const guideline = state.guidelines.find((g) => g.id === id);
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
            modalCategory.textContent = guideline.category || texts.wizard_category_general;
        }
        if (modalBody) {
            modalBody.textContent = guideline.description || '';
        }

        if (window.$ && window.$('#guidelinePreviewModal').length) {
            window.$('#guidelinePreviewModal').modal('show');
        }
    };

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

    const renderGuidelineList = () => {
        if (!guidelineList) {
            return;
        }

        const query = state.guidelineSearchQuery.toLowerCase();
        const filtered = state.guidelines.filter((g) =>
            g.name.toLowerCase().includes(query) ||
            (g.category && g.category.toLowerCase().includes(query))
        );

        if (filtered.length === 0) {
            guidelineList.innerHTML = `<li class="pop-empty">${escapeHtml(texts.wizard_no_results)}</li>`;
            return;
        }

        guidelineList.innerHTML = filtered.map((g) => {
            const isSelected = state.selectedGuidelineId === g.id;
            return `
                <li class="pop-item${isSelected ? ' selected' : ''}" data-id="${g.id}">
                    <button class="pop-select-btn" data-select="${g.id}" type="button">
                        <div class="pop-radio"><div class="pop-dot"></div></div>
                        <div class="pop-item-text">
                            <span class="pop-item-name">${escapeHtml(g.name)}</span>
                            <span class="pop-item-cat">${escapeHtml(g.category || texts.wizard_category_general)}</span>
                        </div>
                    </button>
                    <button 
                    class="pop-eye-btn" 
                    data-preview="${g.id}" type="button" title="${escapeHtml(texts.wizard_chip_view_guideline)}">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </li>
            `;
        }).join('');

        guidelineList.querySelectorAll('.pop-select-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-select');
                selectGuideline(id);
            });
        });

        guidelineList.querySelectorAll('.pop-eye-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = btn.getAttribute('data-preview');
                showGuidelinePreview(id);
            });
        });
    };

    const showFilePicker = async() => {
        try {
            const pickerdata = await WizardRepository.initFilepicker();

            if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
                window.console.error(texts.wizard_error_init_filepicker || 'Failed to initialize filepicker');
                return;
            }

            const pickerOptions = JSON.parse(pickerdata.options);
            const clientIdKey = 'client_id';
            pickerOptions[clientIdKey] = pickerdata.clientid;
            pickerOptions.itemid = pickerdata.draftitemid;

            YUI.use('core_filepicker', 'node', 'node-event-simulate', 'core_dndupload', (Y) => {
                if (pickerdata.templates) {
                    try {
                        const templates = JSON.parse(pickerdata.templates);
                        if (templates && typeof templates === 'object') {
                            M.core_filepicker.set_templates(Y, templates);
                        }
                    } catch (ex) {
                        // Ignore template errors.
                    }
                }

                pickerOptions.formcallback = (fileinfo) => {
                    if (fileinfo && fileinfo.file) {
                        const filename = String(fileinfo.file);
                        state.syllabusFilename = filename;
                        state.draftitemid = pickerOptions.itemid;

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

    if (langSelect && languages.length > 0) {
        langSelect.innerHTML = languages.map((lang) =>
            `<option value="${lang.code}" ${lang.code === defaultLang ? 'selected' : ''}>🌐 ${lang.code.toUpperCase()}</option>`
        ).join('');
    }

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

    document.addEventListener('click', (e) => {
        if (state.guidelinePopoverOpen &&
            guidelinesPopover &&
            !guidelinesPopover.contains(e.target) &&
            e.target !== btnDirectrices) {
            closeGuidelinePopover();
        }
    });

    if (guidelineSearch) {
        guidelineSearch.addEventListener('input', () => {
            state.guidelineSearchQuery = guidelineSearch.value;
            renderGuidelineList();
        });
    }

    if (btnSyllabus) {
        btnSyllabus.addEventListener('click', async() => {
            await showFilePicker();
        });
    }

    if (langSelect) {
        langSelect.addEventListener('change', () => {
            state.lang = langSelect.value;
        });
    }

    if (btnWithImages && imgToggleWrap) {
        btnWithImages.addEventListener('change', () => {
            state.withImages = btnWithImages.checked;
            imgToggleWrap.classList.toggle('on', state.withImages);
        });
    }

    return {
        updateGenerateButton,
        refreshGuidelineChip,
        refreshChipsRow,
        renderGuidelineList,
    };
};
