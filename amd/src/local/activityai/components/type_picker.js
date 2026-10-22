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
 * The bar of the activity modal that shows the type picked, and the field that names it.
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
            CHOSEN: "[data-region='local_coursegen/activity/chosen']",
            CHOSEN_LABEL: "[data-region='local_coursegen/activity/chosen_label']",
            PROMPT: "[data-region='local_coursegen/activity/prompt']",
        };
    }

    stateReady() {
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

    async _refresh() {
        const session = this.reactive.state.session;
        const step = String(session.typestep || 'type');

        // The chat, with its footer, only shows once the type is decided.
        const chatVisible = step === 'prompt';
        if (this.footer) {
            this.footer.classList.toggle('d-none', !chatVisible);
        }

        const chosen = this.getElement(this.selectors.CHOSEN);
        const label = await this._label(session);
        this._show(chosen, chatVisible && label !== '', 'd-flex');
        const labelElement = this.getElement(this.selectors.CHOSEN_LABEL);
        if (labelElement) {
            labelElement.textContent = label;
        }

        await this._placeholder(session, chatVisible);
    }

    /**
     * The field of the chat says what is going to be created, with the type that was picked.
     *
     * @param {Object} session
     * @param {boolean} chatVisible
     */
    async _placeholder(session, chatVisible) {
        const prompt = this.footer?.querySelector(this.selectors.PROMPT);
        if (!prompt) {
            return;
        }

        if (this.defaultPlaceholder === undefined) {
            this.defaultPlaceholder = prompt.getAttribute('placeholder') || '';
        }

        const name = chatVisible ? await this._name(session) : '';
        prompt.setAttribute(
            'placeholder',
            name ? await getString('activitytype_placeholder', 'local_coursegen', name) : this.defaultPlaceholder
        );
    }

    async _label(session) {
        const name = await this._name(session);
        return name ? getString('activitytype_chosen', 'local_coursegen', name) : '';
    }

    /**
     * The name of what is going to be created: the type, and for H5P its kind.
     *
     * @param {Object} session
     * @returns {Promise<string>} Empty when the type is not decided.
     */
    async _name(session) {
        const type = this.reactive.state.page.activitytypes.find((item) => item.modname === session.activitytype);
        if (!type) {
            return '';
        }

        if (type.modname === 'h5pactivity' && session.h5ptype) {
            const kind = this.reactive.state.page.h5ptypes.find((item) => item.type === session.h5ptype);
            if (kind) {
                return type.name + ' · ' + kind.name;
            }
        }

        return type.name;
    }

    _show(element, visible, displayClass = 'd-block') {
        if (!element) {
            return;
        }
        element.classList.toggle('d-none', !visible);
        element.classList.toggle(displayClass, visible);
    }
}
