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
 * What the course preview adds to the page its format drew.
 *
 * The page is a real course page, rendered by the course's own format, and it
 * is left exactly as the format drew it. Two things still have to happen to
 * it: the activities the run is going to write have to appear in the sections
 * they will be written into, and following a link has to stay inside the
 * preview instead of landing on the template's real course.
 *
 * Both are done here rather than to the markup on the way out. A rendered
 * course is a document, and a document knows where its own elements are and
 * where they end; deciding that again by reading the markup as text means
 * counting tags by hand, and getting it wrong shows up as a page that is
 * subtly not the page.
 *
 * @module     local_coursegen/local/courseai/template/course_preview
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {get_string as getString} from 'core/str';
import {exception as displayException} from 'core/notification';

/**
 * Put the planned activities in their sections and keep links in the preview.
 *
 * @param {number} sessionId The run being previewed.
 * @param {number} courseId The template's own course, whose links are rewritten.
 * @param {Array} planned One entry per section that has activities to add.
 */
export const init = (sessionId, courseId, planned) => {
    keepLinksInsidePreview(sessionId, courseId);
    planned.forEach((section) => addPlannedActivities(section));
    openSectionsThatShowOnlyTheirSummary(sessionId);
};

/**
 * Give a way into a section the course page only summarises.
 *
 * A course whose sections live on their own pages does not list a section's
 * activities on the front page: it shows how many there are, and the section
 * is opened to see them. A format whose sections are tiles can put that
 * summary in a dialog, and the dialog is then a dead end, because the tile it
 * came from was the way through.
 *
 * That is a dead end everywhere, and on a real course the teacher can turn
 * editing on to see the lists in place. A preview is never in editing, so the
 * sections holding what the run is going to write would be the ones that
 * could not be opened. The summary gets a link to the section, which is where
 * the tile would have gone had it not opened a dialog.
 *
 * @param {number} sessionId
 */
const openSectionsThatShowOnlyTheirSummary = (sessionId) => {
    document.querySelectorAll('[data-section][data-sectiontitle]').forEach((block) => {
        if (block.querySelector('[data-for="cmlist"]') || block.querySelector('.local-coursegen-open-section')) {
            return;
        }

        const url = new URL(M.cfg.wwwroot + '/local/coursegen/course_preview.php');
        url.searchParams.set('sessionid', sessionId);
        url.searchParams.set('section', block.dataset.section);

        const link = document.createElement('a');
        link.className = 'btn btn-secondary local-coursegen-open-section';
        link.href = url.toString();
        block.appendChild(link);

        getString('courseai_preview_open_section', 'local_coursegen')
            .then((label) => {
                link.textContent = label;
                return null;
            })
            .catch(displayException);
    });
};

/**
 * Point every link to this course at the preview of it.
 *
 * A format draws links to its own sections, and a section page draws a menu of
 * everything around it. Following one leaves the preview for the course the
 * template is built on, which is not what the teacher asked to look at.
 *
 * @param {number} sessionId
 * @param {number} courseId
 */
const keepLinksInsidePreview = (sessionId, courseId) => {
    document.querySelectorAll('a[href], option[value]').forEach((element) => {
        const attribute = element.tagName === 'OPTION' ? 'value' : 'href';
        const target = previewUrl(element.getAttribute(attribute), sessionId, courseId);
        if (target !== null) {
            element.setAttribute(attribute, target);
        }
    });
};

/**
 * Where a link into this course should go instead, or null to leave it alone.
 *
 * @param {string} href
 * @param {number} sessionId
 * @param {number} courseId
 * @returns {?string}
 */
const previewUrl = (href, sessionId, courseId) => {
    if (!href) {
        return null;
    }

    let url;
    try {
        url = new URL(href, window.location.origin);
    } catch (error) {
        return null;
    }

    const preview = new URL(M.cfg.wwwroot + '/local/coursegen/course_preview.php');
    preview.searchParams.set('sessionid', sessionId);

    // The course page itself, which names the section it opens, if any.
    if (url.pathname.endsWith('/course/view.php')) {
        if (Number(url.searchParams.get('id')) !== courseId) {
            return null;
        }
        const section = url.searchParams.get('section');
        if (section !== null) {
            preview.searchParams.set('section', section);
        }
        return preview.toString() + url.hash;
    }

    // A section's own page, which is how a format whose sections are tiles
    // opens one. It names the section by its record rather than its number,
    // and the sections carry both, so the page is asked which is which.
    if (url.pathname.endsWith('/course/section.php')) {
        const number = sectionNumberOf(url.searchParams.get('id'));
        if (number === null) {
            return null;
        }
        preview.searchParams.set('section', number);
        return preview.toString();
    }

    return null;
};

/**
 * Which section of the course a section record is, as the page has it.
 *
 * @param {?string} sectionId
 * @returns {?string}
 */
const sectionNumberOf = (sectionId) => {
    if (!sectionId) {
        return null;
    }
    const section = document.querySelector(`[data-id="${CSS.escape(sectionId)}"][data-sectionid]`);
    return section ? section.dataset.sectionid : null;
};

/**
 * Add one section's planned activities to the list the format drew for it.
 *
 * A format can draw a section more than once: the sections of a grid are laid
 * out as tiles and again inside the dialog that opens one, and an activity
 * planned for that section belongs in both. So every list the section has is
 * filled, not the first one found.
 *
 * @param {Object} section
 */
const addPlannedActivities = (section) => {
    const lists = document.querySelectorAll(
        `[data-id="${CSS.escape(section.sectionid)}"] [data-for="cmlist"]`
    );
    if (!lists.length) {
        return;
    }

    section.activities.forEach((activity) => {
        Templates.renderForPromise('local_coursegen/preview_activity_row', activity)
            .then(({html}) => {
                lists.forEach((list) => Templates.appendNodeContents(list, html, ''));
                return null;
            })
            .catch(displayException);
    });
};
