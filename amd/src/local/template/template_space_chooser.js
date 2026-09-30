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
import {COMPONENT, EVENT, STRING} from 'local_coursegen/local/template/constants';

/** @type {number} The archetype the web service reports for an activity. */
const ARCHETYPE_ACTIVITY = 0;
/** @type {number} The archetype the web service reports for a resource. */
const ARCHETYPE_RESOURCE = 1;

/**
 * Whether a content item is an activity or resource module this plugin supports.
 *
 * @param {string[]} supportedTypes Module names a space can be made for.
 * @param {Object} item A content item from the course chooser web service.
 * @returns {boolean}
 */
const isSupportedItem = (supportedTypes, item) => {
    const isModule = item.componentname.startsWith('mod_');
    return isModule && supportedTypes.includes(item.name);
};

/**
 * Flag items as legacy so no favourite star is drawn for them.
 *
 * @param {Array} items Content items.
 * @returns {Array}
 */
const withoutFavouriteStars = (items) => {
    const flagged = [];
    for (const item of items) {
        const copy = {...item, legacyitem: true};
        flagged.push(copy);
    }
    return flagged;
};

/**
 * The items whose module this plugin supports.
 *
 * @param {Array} items Content items from the course chooser web service.
 * @param {string[]} supportedTypes Module names a space can be made for.
 * @returns {Array}
 */
const supportedItemsOf = (items, supportedTypes) => {
    const supported = [];
    for (const item of items) {
        if (isSupportedItem(supportedTypes, item)) {
            supported.push(item);
        }
    }
    return supported;
};

/**
 * The chooser items this plugin supports, as the course page lists them.
 *
 * @param {number} courseId The base course (the chooser is permission-aware per course).
 * @param {string[]} supportedTypes Module names a space can be made for.
 * @returns {Promise<Array>} Content items, each flagged so no favourite star is drawn.
 */
const fetchSupportedItems = async(courseId, supportedTypes) => {
    const data = await Repository.activityModules(courseId, 0);
    const supported = supportedItemsOf(data.content_items, supportedTypes);
    return withoutFavouriteStars(supported);
};

/**
 * The items of one archetype.
 *
 * @param {Array} items Content items.
 * @param {number} archetype ARCHETYPE_ACTIVITY or ARCHETYPE_RESOURCE.
 * @returns {Array}
 */
const itemsOfArchetype = (items, archetype) => {
    const matching = [];
    for (const item of items) {
        if (item.archetype === archetype) {
            matching.push(item);
        }
    }
    return matching;
};

/**
 * The data core's own chooser template expects, for the All / Activities /
 * Resources tabs (no favourites or recommended tabs here).
 *
 * @param {Array} items
 * @returns {Object}
 */
const templateData = (items) => {
    const activities = itemsOfArchetype(items, ARCHETYPE_ACTIVITY);
    const resources = itemsOfArchetype(items, ARCHETYPE_RESOURCE);
    return {
        'default': items,
        showAll: true,
        activities,
        showActivities: true,
        activitiesFirst: false,
        resources,
        showResources: true,
        favourites: [],
        recommended: [],
        recommendedFirst: false,
        recommendedBeginning: false,
        favouritesFirst: false,
        fallback: true,
    };
};

/**
 * Keep the resolver a promise executor receives.
 *
 * @param {Object} deferred The object that will expose the resolver.
 * @param {Function} resolve The promise's resolve function.
 */
const captureResolver = (deferred, resolve) => {
    deferred.resolve = resolve;
};

/**
 * A promise together with the function that resolves it.
 *
 * @returns {{promise: Promise, resolve: Function}}
 */
const createDeferred = () => {
    const deferred = {};
    const executor = captureResolver.bind(null, deferred);
    deferred.promise = new Promise(executor);
    return deferred;
};

/**
 * The outcome of one chooser session: settles exactly once, with the picked
 * type or with null when the modal is closed without a pick.
 */
class ChooserSelection {
    constructor() {
        this.settled = false;
        this.deferred = createDeferred();
    }

    /**
     * @returns {Promise} Resolves with the picked type, or null.
     */
    get promise() {
        return this.deferred.promise;
    }

    /**
     * Settle the session; any settle after the first is ignored.
     *
     * @param {Object|null} value The picked type, or null.
     */
    settle(value) {
        if (this.settled) {
            return;
        }
        this.settled = true;
        this.deferred.resolve(value);
    }
}

/**
 * Favourites are not offered here, so the chooser is given a manager that
 * has nothing to update.
 *
 * @returns {Promise<null>}
 */
const keepFavouritesUnchanged = async() => null;

/**
 * Create the chooser modal, whose body is filled in once the items are loaded.
 *
 * @param {Promise<string>} bodyPromise Resolves with the rendered chooser body.
 * @returns {Promise<Object>} The modal instance.
 */
const createChooserModal = (bodyPromise) => {
    const title = getString(STRING.ADD_SPACE, COMPONENT);
    return Modal.create({
        title,
        body: bodyPromise,
        large: true,
        scrollable: false,
        templateContext: {classes: 'modchooser'},
        show: true,
    });
};

/**
 * The content item of a module, by its module name.
 *
 * @param {Array} items The content items offered.
 * @param {string} modname
 * @returns {Object|null} Null when no item has that module name.
 */
const findItemByName = (items, modname) => {
    for (const item of items) {
        if (item.name === modname) {
            return item;
        }
    }
    return null;
};

/**
 * Settle the session with the activity type behind a clicked chooser link.
 *
 * @param {Object} modal The chooser modal.
 * @param {Array} items The content items offered.
 * @param {ChooserSelection} selection The session to settle.
 * @param {MouseEvent} e The click.
 */
const pickFromClick = (modal, items, selection, e) => {
    const link = e.target.closest('a[data-action="add-chooser-option"]');
    if (!link) {
        return;
    }
    e.preventDefault();
    e.stopPropagation();
    const url = new URL(link.href, window.location.href);
    const modname = url.searchParams.get('add');
    const item = findItemByName(items, modname);
    if (!item) {
        return;
    }
    selection.settle({modname, name: item.title, icon: item.icon});
    modal.hide();
};

/**
 * Make a click on a chooser link pick its type, and closing the modal
 * without a pick settle the session with null.
 *
 * @param {Object} modal The chooser modal.
 * @param {Array} items The content items offered.
 * @param {ChooserSelection} selection The session to settle.
 */
const bindPick = (modal, items, selection) => {
    const rootList = modal.getRoot();
    const root = rootList[0];
    const onClick = pickFromClick.bind(null, modal, items, selection);
    root.addEventListener(EVENT.CLICK, onClick, true);
    const onHidden = selection.settle.bind(selection, null);
    rootList.on(ModalEvents.hidden, onHidden);
};

/**
 * Load the supported items into the chooser modal and wire up the pick.
 *
 * @param {Object} params
 * @param {number} params.courseId The base course.
 * @param {string[]} params.supportedTypes Module names a space can be made for.
 * @param {Promise<Object>} params.modalPromise The chooser modal being created.
 * @param {{resolve: Function}} params.body Resolves the modal body.
 * @param {ChooserSelection} params.selection The session to settle.
 */
const populateChooser = async({courseId, supportedTypes, modalPromise, body, selection}) => {
    const items = await fetchSupportedItems(courseId, supportedTypes);
    ChooserDialogue.displayChooser(modalPromise, items, keepFavouritesUnchanged, {footer: false});
    const data = templateData(items);
    const rendered = await Templates.render('core_course/activitychooser', data);
    body.resolve(rendered);
    const modal = await modalPromise;
    bindPick(modal, items, selection);
};

/**
 * Open the chooser and resolve with the picked activity type.
 *
 * @param {Object} options
 * @param {number} options.courseId The base course.
 * @param {string[]} options.supportedTypes Module names a space can be made for.
 * @returns {Promise<{modname: string, name: string, icon: string}|null>}
 *     Null when the modal is closed without picking anything.
 */
export const chooseActivityType = async({courseId, supportedTypes}) => {
    const body = createDeferred();
    const modalPromise = createChooserModal(body.promise);
    const selection = new ChooserSelection();
    try {
        await populateChooser({courseId, supportedTypes, modalPromise, body, selection});
    } catch (error) {
        const modal = await modalPromise;
        modal.destroy();
        throw error;
    }
    return selection.promise;
};
