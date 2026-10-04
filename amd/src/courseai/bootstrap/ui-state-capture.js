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
 * What the user left open and where the user scrolled on the course page.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-state-capture
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Pixels short of its end at which a panel still counts as scrolled to its end. */
const END_TOLERANCE_PX = 4;

/** Ids of the panels that scroll, by the name the saved state uses. */
export const SCROLL_PANELS = {chat: 'courseaiChatScroll', plan: 'planningView'};

/** Ids of the fields the user types in and may leave unsent, by the name the saved state uses. */
export const DRAFT_FIELDS = {compact: 'compactPromptInput'};

/**
 * Whether a toggle that publishes its state in aria-expanded is open.
 *
 * @param {Object} element
 * @returns {boolean}
 */
const isExpanded = (element) => {
    const value = element.getAttribute('aria-expanded');
    return value === 'true';
};

/**
 * Whether an activity card has its detail open: its chevron is turned.
 *
 * @param {Object} element
 * @returns {boolean}
 */
const hasOpenDetail = (element) => {
    const openChevron = element.querySelector('.cg-activity-chevron--open');
    return openChevron !== null;
};

/**
 * The kinds of toggle the page has. The position of a toggle in document order identifies it, because
 * a page rebuilt from the same plan draws them in the same order.
 */
export const TOGGLE_KINDS = [
    {name: 'group', selector: '.cg-group-head', isOpen: isExpanded},
    {name: 'detail', selector: '.cg-detail-toggle', isOpen: isExpanded},
    {name: 'progress', selector: '#pcToggleBtn', isOpen: isExpanded},
    {name: 'section', selector: '#prvSections a.icons-collapse-expand', isOpen: isExpanded},
    {name: 'activity', selector: '#prvSections .activity-item.cg-activity--has-detail', isOpen: hasOpenDetail},
];

/**
 * Read the open state of every toggle of one kind.
 *
 * @param {Object} root Where the toggles are looked up, normally the document.
 * @param {Object} kind One of TOGGLE_KINDS.
 * @returns {Array<boolean>} The open state of each toggle, in document order.
 */
const readToggles = (root, kind) => {
    const found = root.querySelectorAll(kind.selector);
    const elements = Array.from(found);
    return elements.map(kind.isOpen);
};

/**
 * Read where one panel is scrolled.
 *
 * @param {Object} panel
 * @returns {{top: number, atEnd: boolean}}
 */
const readScroll = (panel) => {
    const remaining = panel.scrollHeight - (panel.scrollTop + panel.clientHeight);
    const top = Math.round(panel.scrollTop);
    const atEnd = remaining <= END_TOLERANCE_PX;
    return {top, atEnd};
};

/**
 * Read the open state of every kind of toggle.
 *
 * @param {Object} root Where the toggles are looked up, normally the document.
 * @returns {Object} The open states of each kind, by the name of the kind.
 */
const captureToggles = (root) => {
    const toggles = {};
    TOGGLE_KINDS.forEach((kind) => {
        toggles[kind.name] = readToggles(root, kind);
    });
    return toggles;
};

/**
 * Read where every panel that is on the page is scrolled.
 *
 * @param {Object} root Where the panels are looked up, normally the document.
 * @returns {Object} The scroll of each panel, by the name of the panel.
 */
const captureScroll = (root) => {
    const scroll = {};
    Object.entries(SCROLL_PANELS).forEach(([name, id]) => {
        const panel = root.getElementById(id);
        if (panel) {
            scroll[name] = readScroll(panel);
        }
    });
    return scroll;
};

/**
 * Read the text the user typed in the fields and did not send.
 *
 * @param {Object} root Where the fields are looked up, normally the document.
 * @returns {Object} The text of each field that has some, by the name of the field.
 */
const captureDrafts = (root) => {
    const drafts = {};
    Object.entries(DRAFT_FIELDS).forEach(([name, id]) => {
        const field = root.getElementById(id);
        if (!field) {
            return;
        }
        const text = String(field.value || '');
        if (text.trim() !== '') {
            drafts[name] = text;
        }
    });
    return drafts;
};

/**
 * Read the state of the page that only the user changes: what is open, where each panel is scrolled
 * and what was typed and not sent.
 *
 * @param {Object} root Where the page elements are looked up, normally the document.
 * @returns {{toggles: Object, scroll: Object, drafts: Object}} The state, ready to be saved as JSON.
 */
export const captureUiState = (root) => {
    const toggles = captureToggles(root);
    const scroll = captureScroll(root);
    const drafts = captureDrafts(root);
    return {toggles, scroll, drafts};
};
