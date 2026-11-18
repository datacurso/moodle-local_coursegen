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
 * The top bar's path crumb: it names the starting point chosen on the first
 * screen and leads back to the cards. Once planning has started, in either
 * path, the starting point is fixed: the crumb is disabled and loses its
 * chevron. The cards themselves are plain links, so nothing else is wired.
 *
 * @module     local_coursegen/local/courseai/start_path
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Selectors from './start/selectors';

const LOCKED_CLASS = 'is-locked';
const PLANNING_CLASS = 'is-planning';
const GENERATING_CLASS = 'cg-generating';

/**
 * The crumb and the lock that follows the workspace.
 */
class StartPathBar {
    /**
     * @param {HTMLButtonElement} crumb The crumb in the top bar.
     * @param {HTMLElement} workspace The workspace the planning classes land on.
     */
    constructor(crumb, workspace) {
        this.crumb = crumb;
        this.workspace = workspace;
        this.sync = this.sync.bind(this);
        this.goBack = this.goBack.bind(this);
    }

    /**
     * Planning marks the workspace (free creation) or the body (template
     * generation); either one fixes the starting point.
     *
     * @returns {boolean}
     */
    isLocked() {
        const planning = this.workspace.classList.contains(PLANNING_CLASS);
        const generating = document.body.classList.contains(GENERATING_CLASS);
        return planning || generating;
    }

    /**
     * Make the crumb's look, state and tooltip follow the lock.
     */
    sync() {
        const locked = this.isLocked();
        this.crumb.classList.toggle(LOCKED_CLASS, locked);
        this.crumb.disabled = locked;
        let title = this.crumb.dataset.titleUnlocked;
        if (locked) {
            title = this.crumb.dataset.titleLocked;
        }
        this.crumb.title = title;
    }

    /**
     * Back to the cards. Refused once planning has started.
     */
    goBack() {
        if (this.isLocked()) {
            return;
        }
        window.location.href = this.crumb.dataset.url;
    }

    /**
     * The lock is a class other modules set; follow it rather than asking
     * them to call back.
     */
    watch() {
        const observer = new MutationObserver(this.sync);
        const options = {attributes: true, attributeFilter: ['class']};
        observer.observe(this.workspace, options);
        observer.observe(document.body, options);
    }
}

/**
 * Wire the crumb, when the page renders one.
 */
export const init = () => {
    const crumb = document.querySelector(Selectors.regions.pathBack);
    const workspace = document.querySelector(Selectors.regions.workspace);
    if (!crumb || !workspace) {
        return;
    }
    const bar = new StartPathBar(crumb, workspace);
    crumb.addEventListener('click', bar.goBack);
    bar.watch();
    bar.sync();
};
