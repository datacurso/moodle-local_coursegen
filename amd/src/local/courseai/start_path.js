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
 * The starting point: chosen on the page's first screen, named above the
 * workspace afterwards.
 *
 * A fresh visit opens on two cards, free creation or from a template
 * (start_chooser.mustache). Picking one hides the cards and shows that
 * path's workspace: the free hero, or the template column with its picker.
 * A bar at the top of either (start_modebar.mustache) names the choice and
 * offers the way back to the cards, until planning starts and the choice is
 * fixed. The workspace's `is-choosing` class is what shows the cards; the
 * template layout itself belongs to context/template.js.
 *
 * @module     local_coursegen/local/courseai/start_path
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** How long after the template card is picked the list opens, so the column lands first. */
const OPEN_LIST_DELAY_MS = 150;

/**
 * Wire the chooser cards and the mode bars.
 *
 * @param {Object} params
 * @param {Object} params.state Page state; gains `startPath` (null|'free'|'template').
 * @param {Object} params.contextUi Context UI handlers (setTemplateLayout, openTemplatePopover).
 * @returns {{setStartPath: Function, showChooser: Function, syncBars: Function}}
 */
export const wireStartPath = ({state, contextUi}) => {
    const workspace = document.getElementById('courseaiWorkspace');
    const bars = () => document.querySelectorAll('[data-start-modebar]');

    // Once planning has started, in either path, the starting point is fixed:
    // free creation marks the workspace, the template generation marks the body.
    const isLocked = () => (workspace?.classList.contains('is-planning') ?? false)
        || document.body.classList.contains('cg-generating');

    const syncBars = () => {
        const locked = isLocked();
        bars().forEach((bar) => {
            bar.dataset.startPath = state.startPath || '';
            bar.classList.toggle('is-locked', locked);
            const back = bar.querySelector('[data-action="start-back"]');
            if (back) {
                back.disabled = locked;
                back.title = locked ? (back.dataset.titleLocked || '') : (back.dataset.titleUnlocked || '');
            }
        });
    };

    /**
     * Open one of the two paths.
     *
     * @param {'free'|'template'} path
     * @param {Object} [options]
     * @param {boolean} [options.openList=true] On the template path, open the list of templates.
     */
    const setStartPath = (path, {openList = true} = {}) => {
        state.startPath = path;
        workspace?.classList.remove('is-choosing');
        contextUi.setTemplateLayout(path === 'template');
        syncBars();
        if (path === 'free') {
            document.getElementById('promptInput')?.focus();
            return;
        }
        if (openList && !state.selectedTemplateId) {
            setTimeout(() => {
                contextUi.openTemplatePopover('templatesPopoverTpl', document.getElementById('tplPickBtn'));
            }, OPEN_LIST_DELAY_MS);
        }
    };

    /**
     * Back to the cards. Refused once planning has started.
     */
    const showChooser = () => {
        if (isLocked()) {
            return;
        }
        state.startPath = null;
        contextUi.closeTemplatePopovers?.();
        contextUi.setTemplateLayout(false);
        workspace?.classList.add('is-choosing');
        syncBars();
    };

    document.querySelectorAll('[data-start-path]').forEach((card) => {
        card.addEventListener('click', () => setStartPath(card.dataset.startPath));
    });
    bars().forEach((bar) => {
        bar.querySelectorAll('[data-action="start-back"], [data-action="start-change"]').forEach((btn) => {
            btn.addEventListener('click', showChooser);
        });
    });

    // The lock is a class other modules set; follow it rather than asking them to call back.
    if (workspace && typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(syncBars);
        observer.observe(workspace, {attributes: true, attributeFilter: ['class']});
        observer.observe(document.body, {attributes: true, attributeFilter: ['class']});
    }

    // What the server rendered: the cards, or (on a resumed session) the free planning column.
    state.startPath = workspace?.classList.contains('is-choosing') ? null : 'free';
    syncBars();

    return {setStartPath, showChooser, syncBars};
};
