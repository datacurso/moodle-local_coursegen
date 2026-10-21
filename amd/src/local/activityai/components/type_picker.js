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
 * The steps of the activity modal that come after the chooser of types: the kinds of H5P and the bar with the type.
 *
 * @module     local_coursegen/local/activityai/components/type_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import {get_string as getString} from 'core/str';

export default class extends BaseComponent {
    create(descriptor) {
        this.name = 'local_coursegen_activityai_type_picker';
        this.footer = descriptor.footer || null;
        this.selectors = {
            H5PLIST: "[data-region='local_coursegen/activity/h5plist']",
            CHOSEN: "[data-region='local_coursegen/activity/chosen']",
            CHOSEN_LABEL: "[data-region='local_coursegen/activity/chosen_label']",
            CHANGE: "[data-action='local_coursegen/activity/change-type']",
            WELCOME: "[data-region='local_coursegen/activity/welcome']",
        };
    }

    stateReady() {
        this.addEventListener(this.element, 'click', this._handleClick);
        this._refresh();
    }

    getWatchers() {
        return [
            {watch: 'session.typestep:updated', handler: this._refresh},
            {watch: 'session.activitytype:updated', handler: this._refresh},
            {watch: 'session.h5ptype:updated', handler: this._refresh},
            {watch: 'session.jobid:updated', handler: this._refresh},
        ];
    }

    _handleClick(event) {
        const target = event.target.closest('[data-action]');
        if (!target || !this.element.contains(target)) {
            return;
        }

        const action = target.dataset.action;
        if (action === 'local_coursegen/activity/pick-h5ptype') {
            event.preventDefault();
            this.reactive.dispatch('chooseH5pType', {type: target.dataset.type || ''});
        } else if (action === 'local_coursegen/activity/change-type') {
            event.preventDefault();
            this._askForTheTypeAgain();
        }
    }

    /**
     * Back to the chooser of types: the modal closes and opens again where it was, which starts from the chooser.
     */
    _askForTheTypeAgain() {
        const session = this.reactive.state.session;
        if (session.jobid) {
            return;
        }

        const position = {
            courseid: this.reactive.state.page.courseid,
            sectionnum: session.sectionnum,
            beforemod: session.beforemod,
        };
        this.reactive.dispatch('closeModal');
        this.reactive.dispatch('openModal', position);
    }

    async _refresh() {
        const session = this.reactive.state.session;
        const step = String(session.typestep || 'type');

        this._show(this.getElement(this.selectors.H5PLIST), step === 'h5p');

        // The chat, with its footer, only shows once the type is decided (or the teacher chose to describe it freely).
        const chatVisible = step === 'prompt';
        if (this.footer) {
            this.footer.classList.toggle('d-none', !chatVisible);
        }
        const welcome = this.element.closest("[data-region='local_coursegen/activity/root']")
            ?.querySelector(this.selectors.WELCOME);
        if (welcome) {
            welcome.classList.toggle('d-none', !chatVisible);
        }

        const chosen = this.getElement(this.selectors.CHOSEN);
        const label = await this._label(session);
        this._show(chosen, chatVisible && label !== '', 'd-flex');
        const labelElement = this.getElement(this.selectors.CHOSEN_LABEL);
        if (labelElement) {
            labelElement.textContent = label;
        }

        // The type cannot change once the conversation started.
        const change = this.getElement(this.selectors.CHANGE);
        if (change && chosen) {
            chosen.querySelector(this.selectors.CHANGE)?.classList.toggle('d-none', Boolean(session.jobid));
        }
    }

    async _label(session) {
        const type = this.reactive.state.page.activitytypes.find((item) => item.modname === session.activitytype);
        if (!type) {
            return '';
        }

        let name = type.name;
        if (type.modname === 'h5pactivity' && session.h5ptype) {
            const kind = this.reactive.state.page.h5ptypes.find((item) => item.type === session.h5ptype);
            if (kind) {
                name = type.name + ' · ' + kind.name;
            }
        }

        return getString('activitytype_chosen', 'local_coursegen', name);
    }

    _show(element, visible, displayClass = 'd-block') {
        if (!element) {
            return;
        }
        element.classList.toggle('d-none', !visible);
        element.classList.toggle(displayClass, visible);
    }
}
