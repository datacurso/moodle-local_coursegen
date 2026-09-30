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
 * The activity chooser for "Add a space for an activity": Moodle's own
 * course-page chooser (its data, template, search, tabs and help panel),
 * limited to the activity types this plugin supports. Picking one resolves
 * with that type instead of sending the browser off to create an activity.
 *
 * @module     local_coursegen/local/template/template_space_chooser
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';
import * as Repository from 'core_course/local/activitychooser/repository';
import * as ChooserDialogue from 'core_course/local/activitychooser/dialogue';

/** @type {number} The archetype the web service reports for an activity. */
const ARCHETYPE_ACTIVITY = 0;
/** @type {number} The archetype the web service reports for a resource. */
const ARCHETYPE_RESOURCE = 1;

/**
 * The chooser items this plugin supports, as the course page lists them.
 *
 * @param {number} courseId The base course (the chooser is permission-aware per course).
 * @param {string[]} supportedTypes Module names a space can be made for.
 * @returns {Promise<Array>} Content items, each flagged so no favourite star is drawn.
 */
const fetchSupportedItems = async(courseId, supportedTypes) => {
    const data = await Repository.activityModules(courseId, 0);
    return data.content_items
        .filter(item => item.componentname.startsWith('mod_') && supportedTypes.includes(item.name))
        .map(item => ({...item, legacyitem: true}));
};

/**
 * The data core's own chooser template expects, for the All / Activities /
 * Resources tabs (no favourites or recommended tabs here).
 *
 * @param {Array} items
 * @returns {Object}
 */
const templateData = (items) => ({
    'default': items,
    showAll: true,
    activities: items.filter(item => item.archetype === ARCHETYPE_ACTIVITY),
    showActivities: true,
    activitiesFirst: false,
    resources: items.filter(item => item.archetype === ARCHETYPE_RESOURCE),
    showResources: true,
    favourites: [],
    recommended: [],
    recommendedFirst: false,
    recommendedBeginning: false,
    favouritesFirst: false,
    fallback: true,
});

/**
 * The icon URL inside a content item's icon markup.
 *
 * @param {string} iconHtml
 * @returns {string}
 */
const iconUrlOf = (iconHtml) => {
    const holder = document.createElement('div');
    holder.innerHTML = iconHtml;
    return holder.querySelector('img')?.getAttribute('src') || '';
};

/**
 * Open the chooser and resolve with the picked activity type.
 *
 * @param {Object} options
 * @param {number} options.courseId The base course.
 * @param {string[]} options.supportedTypes Module names a space can be made for.
 * @returns {Promise<{modname: string, name: string, iconurl: string}|null>}
 *     Null when the modal is closed without picking anything.
 */
export const chooseActivityType = ({courseId, supportedTypes}) => new Promise((resolve, reject) => {
    let bodyResolver;
    const bodyPromise = new Promise(res => {
        bodyResolver = res;
    });
    const modalPromise = Modal.create({
        title: getString('template_add_space', 'local_coursegen'),
        body: bodyPromise,
        large: true,
        scrollable: false,
        templateContext: {classes: 'modchooser'},
        show: true,
    });

    let settled = false;
    const settle = (value) => {
        if (!settled) {
            settled = true;
            resolve(value);
        }
    };

    fetchSupportedItems(courseId, supportedTypes).then(async(items) => {
        // Favourites are not offered here, so the chooser is given a manager
        // that has nothing to update.
        ChooserDialogue.displayChooser(modalPromise, items, async() => null, {footer: false});
        bodyResolver(await Templates.render('core_course/activitychooser', templateData(items)));

        const modal = await modalPromise;
        modal.getRoot()[0].addEventListener('click', (e) => {
            const link = e.target.closest('a[data-action="add-chooser-option"]');
            if (!link) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            const modname = new URL(link.href, window.location.href).searchParams.get('add');
            const item = items.find(candidate => candidate.name === modname);
            if (!item) {
                return;
            }
            settle({modname, name: item.title, iconurl: iconUrlOf(item.icon)});
            modal.hide();
        }, true);
        modal.getRoot().on(ModalEvents.hidden, () => settle(null));
        return null;
    }).catch(async(error) => {
        const modal = await modalPromise;
        modal.destroy();
        reject(error);
    });
});
