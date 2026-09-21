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
 * Wires the institutional guidelines popover's trigger, its search box, its
 * own close (X), and the document-level listeners that close it on an
 * outside click or Escape. Opening and closing the panel's own state stays
 * in context_section.js (createGuidelineHandlers needs it too); this module
 * only wires the DOM interactions that drive that state.
 *
 * @module     local_coursegen/local/courseai/context/guideline_popover
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {Object} params
 * @param {Object} params.state
 * @param {HTMLElement} params.btnDirectrices
 * @param {HTMLElement} params.guidelinesPopover
 * @param {HTMLElement} params.guidelineSearch
 * @param {Function} params.closeGuidelinePopover
 * @param {Function} params.renderGuidelineList
 */
export const wireGuidelinePopover = ({
    state, btnDirectrices, guidelinesPopover, guidelineSearch, closeGuidelinePopover, renderGuidelineList,
}) => {
    if (btnDirectrices && guidelinesPopover) {
        btnDirectrices.addEventListener('click', (e) => {
            e.stopPropagation();
            // Base the toggle on the panel's real visible state, not on the shared
            // flag: a sibling popover (compact) can leave the flag out of sync.
            const willOpen = !guidelinesPopover.classList.contains('open');
            state.guidelinePopoverOpen = willOpen;
            guidelinesPopover.classList.toggle('open', willOpen);
            btnDirectrices.setAttribute('aria-expanded', String(willOpen));

            if (willOpen && guidelineSearch) {
                guidelineSearch.value = '';
                state.guidelineSearchQuery = '';
                renderGuidelineList();
                guidelineSearch.focus();
            }
        });
    }

    // Close on outside click. Guard on THIS panel's own .open class rather than the
    // shared state flag, so the compact popover's document listener can't clobber it.
    document.addEventListener('click', (e) => {
        if (guidelinesPopover &&
            guidelinesPopover.classList.contains('open') &&
            !guidelinesPopover.contains(e.target) &&
            e.target !== btnDirectrices) {
            closeGuidelinePopover();
        }
    });

    // Close on Escape and return focus to the trigger (accessibility).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && guidelinesPopover && guidelinesPopover.classList.contains('open')) {
            closeGuidelinePopover({returnFocus: true});
        }
    });

    if (guidelineSearch) {
        guidelineSearch.addEventListener('input', () => {
            state.guidelineSearchQuery = guidelineSearch.value;
            renderGuidelineList();
        });
    }

    // Explicit close (X) for the guidelines popover.
    const guidelinesPopoverClose = document.getElementById('guidelinesPopoverClose');
    if (guidelinesPopoverClose) {
        guidelinesPopoverClose.addEventListener('click', (event) => {
            event.stopPropagation();
            closeGuidelinePopover();
        });
    }
};
