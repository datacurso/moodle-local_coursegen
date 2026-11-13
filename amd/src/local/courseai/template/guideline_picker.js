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
 * The institutional guideline picker of the template input bar.
 *
 * Same behaviour and markup classes as free mode's: the popover lists the guidelines the page carries, one
 * can be chosen per course (choosing another replaces it), and a chip shows it with view and remove actions.
 * The list and preview rendering is free mode's own createGuidelineHandlers, driven here through a small
 * adapter over tplState so the free-mode state and elements are never touched.
 *
 * @module     local_coursegen/local/courseai/template/guideline_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createGuidelineHandlers} from '../context/guideline';
import {loadCourseaiStrings} from '../i18n';
import {refreshChipsRow} from './chips_row';
import {guidelineUnitsOf, setGuideline} from './state';

/**
 * The page id ('si_12') of the chosen guideline, null for none.
 *
 * @param {Object} tplState
 * @returns {string|null}
 */
const pageIdOf = (tplState) => (tplState.guidelineid > 0 ? `si_${tplState.guidelineid}` : null);

/**
 * Show the chosen guideline on the chip, or hide the chip.
 *
 * @param {Object} tplState
 * @param {Object[]} guidelines
 */
const refreshChip = (tplState, guidelines) => {
    const chip = document.getElementById('tplChipGuideline');
    const chipName = document.getElementById('tplChipGuidelineName');
    const chosen = guidelines.find((guideline) => guideline.id === pageIdOf(tplState));
    if (chipName) {
        chipName.textContent = chosen ? chosen.name : '';
    }
    if (chip) {
        chip.classList.toggle('hidden', !chosen);
    }
    refreshChipsRow();
};

/**
 * Wire the Directrices button, its popover and its chip.
 *
 * @param {Object} tplState
 * @param {Object} state - Page state carrying the guidelines the page lists.
 * @param {Function} [onChange] Called after the chosen guideline changes (and with it, maybe the units field).
 */
export const wireGuidelinePicker = (tplState, state, onChange = () => {}) => {
    const button = document.getElementById('tplBtnGuidelines');
    const popover = document.getElementById('tplGuidelinesPopover');
    const search = document.getElementById('tplGuidelineSearch');
    const list = document.getElementById('tplGuidelineList');
    if (!button || !popover) {
        return;
    }
    const guidelines = state.guidelines || [];
    let query = '';

    // Free mode's handlers read and write these three fields: here they land on tplState.
    const adapter = {
        guidelines,
        get selectedGuidelineId() {
            return pageIdOf(tplState);
        },
        set selectedGuidelineId(value) {
            setGuideline(tplState, value, guidelineUnitsOf(guidelines.find((guideline) => guideline.id === value)));
        },
        get guidelineSearchQuery() {
            return query;
        },
    };

    let handlers = null;
    const getHandlers = async() => {
        if (!handlers) {
            const texts = await loadCourseaiStrings();
            handlers = createGuidelineHandlers({
                state: adapter,
                elements: {guidelineList: list},
                texts,
                refreshGuidelineChip: () => {
                    refreshChip(tplState, guidelines);
                    onChange();
                },
                refreshChipsRow,
                refreshCompactChipsRow: () => {},
            });
        }
        return handlers;
    };

    const close = ({returnFocus = false} = {}) => {
        popover.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
        if (returnFocus) {
            button.focus();
        }
    };

    button.addEventListener('click', async(e) => {
        e.stopPropagation();
        const willOpen = !popover.classList.contains('open');
        popover.classList.toggle('open', willOpen);
        button.setAttribute('aria-expanded', String(willOpen));
        if (willOpen) {
            query = '';
            if (search) {
                search.value = '';
            }
            (await getHandlers()).renderGuidelineList();
            if (search) {
                search.focus();
            }
        }
    });

    document.addEventListener('click', (e) => {
        if (popover.classList.contains('open') && !popover.contains(e.target) && e.target !== button) {
            close();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && popover.classList.contains('open')) {
            close({returnFocus: true});
        }
    });

    const closeBtn = document.getElementById('tplGuidelinesPopoverClose');
    if (closeBtn) {
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            close({returnFocus: true});
        });
    }

    if (search) {
        search.addEventListener('input', async() => {
            query = search.value;
            (await getHandlers()).renderGuidelineList();
        });
    }

    const viewBtn = document.getElementById('tplChipGuidelineView');
    if (viewBtn) {
        viewBtn.addEventListener('click', async() => {
            const id = pageIdOf(tplState);
            if (id) {
                (await getHandlers()).showGuidelinePreview(id);
            }
        });
    }

    const removeBtn = document.getElementById('tplChipGuidelineRemove');
    if (removeBtn) {
        removeBtn.addEventListener('click', () => {
            setGuideline(tplState, null);
            refreshChip(tplState, guidelines);
            onChange();
            if (popover.classList.contains('open') && handlers) {
                handlers.renderGuidelineList();
            }
        });
    }

    // A guideline carried over (re-wiring) shows its chip right away; an id the page no longer lists is dropped.
    if (!guidelines.some((guideline) => guideline.id === pageIdOf(tplState))) {
        setGuideline(tplState, null);
    }
    refreshChip(tplState, guidelines);
};
