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
 * Entry point for Activity AI reactive UI.
 *
 * @module     local_coursegen/activityai
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

import {reactiveInstance, ensureInitialState} from 'local_coursegen/local/activityai/reactive';
import AddActivityAiButton from 'local_coursegen/local/activityai/components/add_activity_ai_button';
import ModalController from 'local_coursegen/local/activityai/components/modal_controller';

let ismoodle45 = false;
let modalControllerRegistered = false;

/**
 * Initialise Activity AI.
 *
 * This method injects the AI buttons into each activity chooser placeholder and wires the
 * modal flow via reactive components.
 *
 * @param {number} courseid The current course id.
 * @param {boolean} isMoodle45 Whether the Moodle version is 4.5.
 * @param {Array} languages Available languages.
 * @param {string} defaultlang Default language code.
 */
export const init = async(courseid, isMoodle45 = false, languages = [], defaultlang = 'en') => {
    ismoodle45 = Boolean(isMoodle45);

    const languageList = Array.isArray(languages) ? languages : [];
    const normalisedDefaultLang = String(defaultlang || 'en').toLowerCase();
    const resolvedDefaultLang = languageList.some((lang) => String(lang.code || '') === normalisedDefaultLang)
        ? normalisedDefaultLang
        : String((languageList[0] && languageList[0].code) || 'en').toLowerCase();

    ensureInitialState({
        page: {
            courseid: Number(courseid) || 0,
            ismoodle45,
            languages: languageList,
            defaultlang: resolvedDefaultLang,
        },
        session: {
            sectionnum: null,
            beforemod: null,
            generateimages: 0,
            lang: resolvedDefaultLang,
            jobid: '',
            streamingurl: '',
            phase: 'idle',
            locked: false,
        },
        upload: {
            draftitemid: null,
            filename: '',
        },
        modal: {
            open: false,
        },
        runs: [],
    });

    if (!modalControllerRegistered) {
        modalControllerRegistered = true;
        new ModalController({
            element: document.body,
            reactive: reactiveInstance,
        });
    }

    const containers = document.querySelectorAll('.divider-content:has([data-action="open-chooser"])');
    await Promise.all(Array.from(containers).map(async(container) => {
        await injectButton(container, Number(courseid) || 0);
    }));
};

/**
 * Inject the add activity AI button into a chooser container.
 *
 * @param {HTMLElement} container Container element.
 * @param {number} courseid Course id.
 */
export const injectButton = async(container, courseid) => {
    const openChooserButton = container.querySelector('[data-action="open-chooser"]');
    if (!openChooserButton) {
        return;
    }

    const sectionnum = openChooserButton.dataset.sectionnum;
    const beforemod = openChooserButton.dataset.beforemod;
    const arialabel = openChooserButton.getAttribute('aria-label');
    const hasBeforeMod = Boolean(beforemod);
    const showFullText = !hasBeforeMod && ismoodle45;

    const {html} = await Templates.renderForPromise('local_coursegen/add_activity_ai_button', {
        sectionnum,
        beforemod,
        arialabel,
        showfulltext: showFullText,
    });

    container.insertAdjacentHTML('beforeend', html);

    const addActivityAiButton = container.querySelector('.local_coursegen-add-activity-ai-button');
    if (!addActivityAiButton) {
        return;
    }

    // Register a reactive component on the button so click behaviour stays consistent.
    new AddActivityAiButton({
        element: addActivityAiButton,
        reactive: reactiveInstance,
        courseid,
    });
};
