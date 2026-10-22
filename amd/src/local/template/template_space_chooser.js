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
import Selectors from 'local_coursegen/local/template/selectors';
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
 * @param {boolean} showTabs Whether the Activities and Resources tabs are offered next to All.
 * @returns {Object}
 */
const templateData = (items, showTabs = true) => {
    const activities = itemsOfArchetype(items, ARCHETYPE_ACTIVITY);
    const resources = itemsOfArchetype(items, ARCHETYPE_RESOURCE);
    return {
        'default': items,
        showAll: true,
        activities,
        showActivities: showTabs,
        activitiesFirst: false,
        resources,
        showResources: showTabs,
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

/** @type {string} The region of the link that lets the caller go on without picking a type. */
const FREE_LINK_REGION = 'local_coursegen/chooser/free';

/**
 * The chooser body with the link to go on without picking a type at the end of the list, when the caller asked for one.
 *
 * The link goes inside the list and not in the footer of the modal: with a footer, the help panel of an item swaps it
 * and its Back button stops working. Inside the list, it hides with the list when the help panel opens.
 *
 * @param {string} rendered The chooser body HTML.
 * @param {string|null} freeLabel The text of the link, or null for no link.
 * @returns {string} The body HTML.
 */
const withFreeLink = (rendered, freeLabel) => {
    if (!freeLabel) {
        return rendered;
    }
    const body = document.createElement('div');
    body.innerHTML = rendered;
    const list = body.querySelector('.modchoosercontainer');
    if (!list) {
        return rendered;
    }
    const link = document.createElement('a');
    link.href = '#';
    link.className = 'mt-3 align-self-start';
    link.dataset.region = FREE_LINK_REGION;
    link.textContent = freeLabel;
    list.appendChild(link);
    return body.innerHTML;
};

/**
 * Create the chooser modal, whose body is filled in once the items are loaded.
 *
 * @param {Promise<string>} bodyPromise Resolves with the rendered chooser body.
 * @param {Promise<string>|string} title The title of the modal.
 * @returns {Promise<Object>} The modal instance.
 */
const createChooserModal = (bodyPromise, title) => {
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
    const link = e.target.closest(Selectors.actions.coreChooserOption);
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
 * Settle the session without a type, when the link to go on without one is clicked.
 *
 * @param {Object} modal The chooser modal.
 * @param {ChooserSelection} selection The session to settle.
 * @param {MouseEvent} e The click.
 */
const pickFree = (modal, selection, e) => {
    const link = e.target.closest('[data-region="' + FREE_LINK_REGION + '"]');
    if (!link) {
        return;
    }
    e.preventDefault();
    selection.settle({modname: '', name: '', icon: '', free: true});
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
    const onFreeClick = pickFree.bind(null, modal, selection);
    root.addEventListener(EVENT.CLICK, onFreeClick);
    const onHidden = selection.settle.bind(selection, null);
    rootList.on(ModalEvents.hidden, onHidden);
};

/**
 * Show the items in the chooser modal and wire up the pick.
 *
 * @param {Object} params
 * @param {Array} params.items The content items to offer.
 * @param {Promise<Object>} params.modalPromise The chooser modal being created.
 * @param {{resolve: Function}} params.body Resolves the modal body.
 * @param {ChooserSelection} params.selection The session to settle.
 * @param {string|null} params.linkLabel The text of the link under the list, or null for no link.
 * @param {boolean} params.showTabs Whether the Activities and Resources tabs are offered.
 */
const showItems = async({items, modalPromise, body, selection, linkLabel, showTabs}) => {
    ChooserDialogue.displayChooser(modalPromise, items, keepFavouritesUnchanged, {footer: false});
    const data = templateData(items, showTabs);
    const rendered = await Templates.render('core_course/activitychooser', data);
    body.resolve(withFreeLink(rendered, linkLabel));
    const modal = await modalPromise;
    bindPick(modal, items, selection);
};

/**
 * Open the chooser with the given items and resolve with the picked one.
 *
 * @param {Object} options
 * @param {Promise<string>|string} options.title The title of the modal.
 * @param {Function} options.loadItems Returns the items, or a promise of them.
 * @param {string|null} options.linkLabel The text of a link under the list, or null for no link.
 * @param {boolean} options.showTabs Whether the Activities and Resources tabs are offered.
 * @returns {Promise<Object|null>} The pick, null when the modal is closed without one.
 */
const openChooser = async({title, loadItems, linkLabel, showTabs}) => {
    const body = createDeferred();
    const modalPromise = createChooserModal(body.promise, title);
    const selection = new ChooserSelection();
    try {
        const items = await loadItems();
        await showItems({items, modalPromise, body, selection, linkLabel, showTabs});
    } catch (error) {
        const modal = await modalPromise;
        modal.destroy();
        throw error;
    }
    return selection.promise;
};

/**
 * Open the chooser and resolve with the picked activity type.
 *
 * @param {Object} options
 * @param {number} options.courseId The base course.
 * @param {string[]} options.supportedTypes Module names a space can be made for.
 * @param {string} [options.title] The title of the modal; "Add a space for an activity" when omitted.
 * @param {string} [options.freeLabel] The text of a link that goes on without picking a type; no link when omitted.
 * @returns {Promise<{modname: string, name: string, icon: string, free: (boolean|undefined)}|null>}
 *     Null when the modal is closed without picking anything; with free set when the link was used.
 */
export const chooseActivityType = ({courseId, supportedTypes, title = null, freeLabel = null}) => {
    return openChooser({
        title: title || getString(STRING.ADD_SPACE, COMPONENT),
        loadItems: () => fetchSupportedItems(courseId, supportedTypes),
        linkLabel: freeLabel,
        showTabs: true,
    });
};

/**
 * Open the same chooser with items of our own, for the kinds inside a type such as H5P.
 *
 * @param {Object} options
 * @param {string} options.title The title of the modal.
 * @param {Array} options.kinds What to offer: type, name, description, iconurl and usesimages of each.
 * @param {string} options.usesImagesLabel The note shown for the kinds that use images.
 * @param {string} options.backLabel The text of the link that goes back, under the list.
 * @returns {Promise<{modname: string, name: string, icon: string, free: (boolean|undefined)}|null>}
 *     The kind picked (its type in modname); with free set when the link to go back was used; null when closed.
 */
export const chooseKind = ({title, kinds, usesImagesLabel, backLabel}) => {
    return openChooser({
        title,
        loadItems: () => kindItems(kinds, usesImagesLabel),
        linkLabel: backLabel,
        showTabs: false,
    });
};

/**
 * Plain text as HTML.
 *
 * @param {string} text
 * @returns {string}
 */
const escapeHtml = (text) => {
    const holder = document.createElement('div');
    holder.textContent = text;
    return holder.innerHTML;
};

/**
 * The kinds as the items the chooser draws: a tile with the picture, the name and, behind the info button, the description.
 *
 * @param {Array} kinds What to offer.
 * @param {string} usesImagesLabel The note shown for the kinds that use images.
 * @returns {Array}
 */
const kindItems = (kinds, usesImagesLabel) => {
    return kinds.map((kind) => {
        const note = kind.usesimages ? ' ' + escapeHtml('(' + usesImagesLabel + ')') : '';
        return {
            name: kind.type,
            title: kind.name,
            link: '?add=' + encodeURIComponent(kind.type),
            icon: '<img class="icon" alt="" style="width: 64px; height: 40px; max-width: none; object-fit: contain;" src="' +
                escapeHtml(kind.iconurl) + '">',
            help: '<p>' + escapeHtml(kind.description) + note + '</p>',
            purpose: '',
            branded: 1,
            componentname: 'mod_h5pactivity',
            archetype: ARCHETYPE_ACTIVITY,
            legacyitem: true,
        };
    });
};
