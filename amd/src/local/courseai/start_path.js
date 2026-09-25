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
 * path's workspace: the free hero, or the template column with its field.
 * The top bar then carries one quiet crumb (#courseaiPathBack) that names
 * the choice and leads back to the cards, until planning starts and the
 * choice is fixed: the crumb loses its chevron and its action and stays as
 * the path's name. The workspace's `is-choosing` class is what shows the
 * cards; the template layout itself belongs to context/template.js.
 *
 * @module     local_coursegen/local/courseai/start_path
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Write which path is showing into the address bar, without navigating.
 *
 * A reload has to land back on the path the professor was on: the chooser
 * itself already honours ?mode=template on load, so picking a card here
 * only has to keep the address in step with it. History is replaced, not
 * pushed - "back" leading out of the tool by one step matters more than a
 * card click growing the browser history.
 *
 * @param {'free'|'template'|null} path
 */
const writePathToUrl = (path) => {
    const url = new URL(window.location.href);
    if (path) {
        // The server tells "nothing chosen yet" apart from "free creation
        // was chosen" by whether this parameter is present at all, so free
        // needs its own value here rather than the parameter being dropped.
        url.searchParams.set('mode', path);
    } else {
        // Back to the chooser: the parameter's absence is what shows it again.
        url.searchParams.delete('mode');
    }
    if (path !== 'template') {
        // A template chosen for a path that's being left no longer applies.
        url.searchParams.delete('templateid');
    }
    window.history.replaceState(window.history.state, '', url);
};

/**
 * Wire the chooser cards and the top bar's path crumb.
 *
 * @param {Object} params
 * @param {Object} params.state Page state; gains `startPath` (null|'free'|'template').
 * @param {Object} params.contextUi Context UI handlers (setTemplateLayout, openTemplatePopover, closeTemplatePopovers).
 * @returns {{setStartPath: Function, showChooser: Function, syncBars: Function}}
 */
export const wireStartPath = ({state, contextUi}) => {
    const workspace = document.getElementById('courseaiWorkspace');
    const crumb = document.getElementById('courseaiPathBack');

    // Once planning has started, in either path, the starting point is fixed:
    // free creation marks the workspace, the template generation marks the body.
    const isLocked = () => (workspace?.classList.contains('is-planning') ?? false)
        || document.body.classList.contains('cg-generating');

    const syncBars = () => {
        if (!crumb) {
            return;
        }
        const locked = isLocked();
        crumb.hidden = !state.startPath;
        crumb.dataset.startPath = state.startPath || '';
        crumb.classList.toggle('is-locked', locked);
        crumb.disabled = locked;
        let title = crumb.dataset.titleUnlocked || '';
        if (locked) {
            title = crumb.dataset.titleLocked || '';
        }
        crumb.title = title;
    };

    /**
     * Open one of the two paths. The template list itself never opens from
     * here - only the professor's own click on its button opens it
     * (context_section.js); this only shows the column and its picker.
     *
     * @param {'free'|'template'} path
     * @param {Object} [options]
     * @param {boolean} [options.fromCard=false] A card was clicked, as opposed to the address
     *                  bar already saying which path to open: only then is the address updated,
     *                  so restoring it on load never rewrites what the professor typed.
     */
    const setStartPath = (path, {fromCard = false} = {}) => {
        state.startPath = path;
        workspace?.classList.remove('is-choosing');
        contextUi.setTemplateLayout(path === 'template');
        syncBars();
        if (fromCard) {
            writePathToUrl(path);
        }
        if (path === 'free') {
            document.getElementById('promptInput')?.focus();
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
        contextUi.closeTemplatePopovers();
        contextUi.setTemplateLayout(false);
        workspace?.classList.add('is-choosing');
        syncBars();
        writePathToUrl(null);
    };

    document.querySelectorAll('[data-start-path]').forEach((card) => {
        card.addEventListener('click', () => setStartPath(card.dataset.startPath, {fromCard: true}));
    });
    crumb?.addEventListener('click', showChooser);

    // The lock is a class other modules set; follow it rather than asking them to call back.
    if (workspace && typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(syncBars);
        observer.observe(workspace, {attributes: true, attributeFilter: ['class']});
        observer.observe(document.body, {attributes: true, attributeFilter: ['class']});
    }

    // What the server already decided and rendered: the cards, the template
    // column, or the free hero - never a guess this module makes on its own.
    if (workspace?.classList.contains('is-choosing')) {
        state.startPath = null;
    } else if (workspace?.classList.contains('is-template')) {
        state.startPath = 'template';
    } else {
        state.startPath = 'free';
    }
    syncBars();

    return {setStartPath, showChooser, syncBars};
};
