// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Context step controls for courseai page.
 *
 * @module     local_coursegen/local/courseai/context_section
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
        CourseaiRepository,
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

    const refreshCompactChipsRow = () => {
        const compactChipsRow = document.getElementById('compactChipsRow');
        const compactChipSyllabus = document.getElementById('compactChipSyllabus');
        const compactChipGuideline = document.getElementById('compactChipGuideline');
        if (!compactChipsRow) {
            return;
        }
        const hasSyllabus = compactChipSyllabus && !compactChipSyllabus.classList.contains('hidden');
        const hasGuideline = compactChipGuideline && !compactChipGuideline.classList.contains('hidden');
        compactChipsRow.style.display = (hasSyllabus || hasGuideline) ? 'flex' : 'none';
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
        // Compact counterparts
        const compactChipGuideline = document.getElementById('compactChipGuideline');
        const compactChipGuidelineName = document.getElementById('compactChipGuidelineName');
        const compactGuidelineBadge = document.getElementById('compactGuidelineBadge');

        if (state.selectedGuidelineId) {
            const guideline = state.guidelines.find((g) => g.id === state.selectedGuidelineId);
            if (guideline) {
                // Main chip
                if (chipGuideline && chipGuidelineName) {
                    chipGuidelineName.textContent = guideline.name;
                    chipGuideline.classList.remove('hidden');
                    if (guidelineBadge) {
                        guidelineBadge.textContent = '1';
                        guidelineBadge.classList.remove('hidden');
                    }
                }
                // Compact chip
                if (compactChipGuideline && compactChipGuidelineName) {
                    compactChipGuidelineName.textContent = guideline.name;
                    compactChipGuideline.classList.remove('hidden');
                    if (compactGuidelineBadge) {
                        compactGuidelineBadge.textContent = '1';
                        compactGuidelineBadge.classList.remove('hidden');
                    }
                }
            }
        } else {
            if (chipGuideline) {
                chipGuideline.classList.add('hidden');
                if (guidelineBadge) {
                    guidelineBadge.classList.add('hidden');
                }
            }
            if (compactChipGuideline) {
                compactChipGuideline.classList.add('hidden');
                if (compactGuidelineBadge) {
                    compactGuidelineBadge.classList.add('hidden');
                }
            }
        }
        refreshChipsRow();
        refreshCompactChipsRow();
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
            modalCategory.textContent = guideline.category || texts.courseai_category_general;
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
            guidelineList.innerHTML = `<li class="pop-empty">${escapeHtml(texts.courseai_no_results)}</li>`;
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
                            <span class="pop-item-cat">${escapeHtml(g.category || texts.courseai_category_general)}</span>
                        </div>
                    </button>
                    <button 
                    class="pop-eye-btn" 
                    data-preview="${g.id}" type="button" title="${escapeHtml(texts.courseai_chip_view_guideline)}">
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
            const pickerdata = await CourseaiRepository.initFilepicker();

            if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
                window.console.error(texts.courseai_error_init_filepicker || 'Failed to initialize filepicker');
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

                        // Update main chip
                        const chipSyllabus = document.getElementById('chipSyllabus');
                        const chipSyllabusName = document.getElementById('chipSyllabusName');
                        if (chipSyllabusName) {
                            chipSyllabusName.textContent = filename;
                        }
                        if (chipSyllabus) {
                            chipSyllabus.classList.remove('hidden');
                        }
                        refreshChipsRow();

                        // Also update compact chip so it reflects the change immediately
                        const compactChipSyllabus = document.getElementById('compactChipSyllabus');
                        const compactChipSyllabusName = document.getElementById('compactChipSyllabusName');
                        if (compactChipSyllabusName) {
                            compactChipSyllabusName.textContent = filename;
                        }
                        if (compactChipSyllabus) {
                            compactChipSyllabus.classList.remove('hidden');
                        }
                        refreshCompactChipsRow();
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

    const optionsHtml = languages.length > 0
        ? languages.map((lang) =>
            `<option value="${lang.code}" ${lang.code === defaultLang ? 'selected' : ''}>🌐 ${lang.code.toUpperCase()}</option>`
        ).join('')
        : null;

    // Populate main and compact lang selects with the full languages list
    if (optionsHtml) {
        if (langSelect) {
            langSelect.innerHTML = optionsHtml;
        }
        const compactLangSelect = document.getElementById('compactLangSelect');
        if (compactLangSelect) {
            compactLangSelect.innerHTML = optionsHtml;
        }
    }

    // ─── Main context controls ───────────────────────────────────────────────

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
            // Keep compact in sync
            const compactLangSelect = document.getElementById('compactLangSelect');
            if (compactLangSelect) {
                compactLangSelect.value = langSelect.value;
            }
        });
    }

    if (btnWithImages && imgToggleWrap) {
        btnWithImages.addEventListener('change', () => {
            state.withImages = btnWithImages.checked;
            imgToggleWrap.classList.toggle('on', state.withImages);
            // Keep compact in sync
            if (elements.btnCompactWithImages) {
                elements.btnCompactWithImages.checked = state.withImages;
            }
            if (elements.compactImgToggleWrap) {
                elements.compactImgToggleWrap.classList.toggle('on', state.withImages);
            }
        });
    }

    // ─── Compact chat toolbar mirroring ─────────────────────────────────────
    // Wire compact controls so they remain functional in phases 2 and 3.

    const btnCompactSyllabus = document.getElementById('btnCompactSyllabus');
    if (btnCompactSyllabus) {
        btnCompactSyllabus.addEventListener('click', async() => {
            await showFilePicker();
        });
    }

    const btnCompactDirectrices = document.getElementById('btnCompactDirectrices');
    const compactGuidelinesPopover = document.getElementById('guidelinesPopoverCompact');
    const compactGuidelineSearch = document.getElementById('guidelineSearchCompact');
    const compactGuidelineList = document.getElementById('guidelineListCompact');

    const renderCompactGuidelineList = () => {
        if (!compactGuidelineList) {
            return;
        }
        const query = (state.guidelineSearchQuery || '').toLowerCase();
        const filtered = state.guidelines.filter((g) =>
            !query || (g.name || '').toLowerCase().includes(query)
        );
        compactGuidelineList.innerHTML = filtered.map((g) =>
            `<li class="pop-item${g.id === state.selectedGuidelineId ? ' active' : ''}"
                 role="option" data-id="${g.id}" tabindex="-1">
               <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                 <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                 <polyline points="9 12 11 14 15 10"/>
               </svg>
               <span class="pop-item-name">${g.name}</span>
               ${g.id === state.selectedGuidelineId ? '<span class="pop-item-check">✓</span>' : ''}
             </li>`
        ).join('');
    };

    if (btnCompactDirectrices && compactGuidelinesPopover) {
        btnCompactDirectrices.addEventListener('click', (e) => {
            e.stopPropagation();
            state.guidelinePopoverOpen = !state.guidelinePopoverOpen;
            compactGuidelinesPopover.classList.toggle('open', state.guidelinePopoverOpen);
            if (state.guidelinePopoverOpen && compactGuidelineSearch) {
                compactGuidelineSearch.value = '';
                state.guidelineSearchQuery = '';
                renderCompactGuidelineList();
                compactGuidelineSearch.focus();
            }
        });
    }

    if (compactGuidelineSearch) {
        compactGuidelineSearch.addEventListener('input', () => {
            state.guidelineSearchQuery = compactGuidelineSearch.value;
            renderCompactGuidelineList();
        });
    }

    if (compactGuidelineList) {
        compactGuidelineList.addEventListener('click', (e) => {
            const item = e.target.closest('.pop-item');
            if (!item) {
                return;
            }
            const id = item.getAttribute('data-id');
            // Close the compact popover
            compactGuidelinesPopover.classList.remove('open');
            state.guidelinePopoverOpen = false;
            // Handle selection via the same shared state
            const guideline = state.guidelines.find((g) => g.id === id);
            if (guideline) {
                state.selectedGuidelineId = id;
                state.selectedGuidelineName = guideline.name;
                refreshGuidelineChip();
                refreshChipsRow();
            }
        });
    }

    // Close compact popover on outside click
    if (document.body && compactGuidelinesPopover) {
        document.body.addEventListener('click', (e) => {
            if (!state.guidelinePopoverOpen) {
                return;
            }
            if (
                compactGuidelinesPopover &&
                !compactGuidelinesPopover.contains(e.target) &&
                e.target !== btnCompactDirectrices &&
                !btnCompactDirectrices?.contains(e.target)
            ) {
                compactGuidelinesPopover.classList.remove('open');
                state.guidelinePopoverOpen = false;
            }
        });
    }

    const compactLangSelectEl = document.getElementById('compactLangSelect');
    if (compactLangSelectEl) {
        compactLangSelectEl.addEventListener('change', () => {
            state.lang = compactLangSelectEl.value;
            // Keep main in sync
            if (langSelect) {
                langSelect.value = compactLangSelectEl.value;
            }
        });
    }

    if (elements.btnCompactWithImages) {
        elements.btnCompactWithImages.addEventListener('change', () => {
            state.withImages = elements.btnCompactWithImages.checked;
            if (elements.compactImgToggleWrap) {
                elements.compactImgToggleWrap.classList.toggle('on', state.withImages);
            }
            // Keep main in sync
            if (btnWithImages) {
                btnWithImages.checked = state.withImages;
            }
            if (imgToggleWrap) {
                imgToggleWrap.classList.toggle('on', state.withImages);
            }
        });
    }

    // Compact guideline eye button: preview the currently selected guideline
    const compactChipGuidelineEyeBtn = document.getElementById('compactChipGuidelineEyeBtn');
    if (compactChipGuidelineEyeBtn) {
        compactChipGuidelineEyeBtn.addEventListener('click', () => {
            if (state.selectedGuidelineId) {
                showGuidelinePreview(state.selectedGuidelineId);
            }
        });
    }

    return {
        updateGenerateButton,
        refreshGuidelineChip,
        refreshChipsRow,
        renderGuidelineList,
    };
};
