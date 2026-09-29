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
 * The template generation view's header: title, and the spinner/check that
 * says whether the run is still going.
 *
 * @module     local_coursegen/local/courseai/template/generation_header
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show the header spinning, with the given title.
 *
 * @param {string} title
 */
export const showGeneratingHeader = (title) => {
    const header = document.getElementById('tplGenHeader');
    const spinner = document.getElementById('tplGenSpinnerIcon');
    const check = document.getElementById('tplGenCheckIcon');
    const titleEl = document.getElementById('tplGenTitle');
    if (header) {
        header.hidden = false;
        header.classList.remove('prv-header--done');
    }
    if (spinner) {
        spinner.style.display = '';
    }
    if (check) {
        check.style.display = 'none';
    }
    if (titleEl) {
        titleEl.textContent = title;
    }
};

/**
 * Swap the header's spinner for its check.
 *
 * Free mode does this only on the terminal event, never when the last activity
 * lands: phases still run after that, and a check while work continues reads
 * as "finished" to someone who is waiting.
 */
export const markHeaderDone = () => {
    const header = document.getElementById('tplGenHeader');
    const spinner = document.getElementById('tplGenSpinnerIcon');
    const check = document.getElementById('tplGenCheckIcon');
    if (header) {
        header.classList.add('prv-header--done');
    }
    if (spinner) {
        spinner.style.display = 'none';
    }
    if (check) {
        check.style.display = '';
    }
};

/**
 * Hide the header, for a run that ended in a failure.
 */
export const hideHeader = () => {
    const header = document.getElementById('tplGenHeader');
    if (header) {
        header.hidden = true;
    }
};
