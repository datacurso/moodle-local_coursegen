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
 * The first steps of the chat to create an activity, inside the chat: the types, the kinds of H5P and what was chosen.
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
        this.typeItems = descriptor.typeItems || [];
        this.kindItems = descriptor.kindItems || [];
        this.selectors = {
            CHOICES: "[data-region='local_coursegen/activity/choices']",
            TYPESTEP: "[data-region='local_coursegen/activity/typestep']",
            KINDSTEP: "[data-region='local_coursegen/activity/kindstep']",
            OPTION: "[data-region='chooser-option-container']",
            PROMPT: "[data-region='local_coursegen/activity/prompt']",
        };
    }

    stateReady() {
        this.addEventListener(this.element, 'click', this._handleClick);
        this._describeKinds();
        this._refresh();
    }

    getWatchers() {
        return [
            {watch: 'session.typestep:updated', handler: this._refresh},
            {watch: 'session.activitytype:updated', handler: this._refresh},
            {watch: 'session.h5ptype:updated', handler: this._refresh},
        ];
    }

    /**
     * The description of each kind of H5P is the hint of its tile.
     */
    _describeKinds() {
        const tiles = this.getElement(this.selectors.KINDSTEP)?.querySelectorAll(this.selectors.OPTION) || [];
        tiles.forEach((tile) => {
            const kind = this.reactive.state.page.h5ptypes.find((item) => item.type === tile.dataset.internal);
            if (kind) {
                tile.title = kind.description;
            }
        });
    }

    _handleClick(event) {
        const link = event.target.closest('a[data-action="add-chooser-option"]');
        if (!link || !this.element.contains(link)) {
            return;
        }

        event.preventDefault();
        const name = link.closest(this.selectors.OPTION)?.dataset.internal || '';
        const step = this.reactive.state.session.typestep;
        if (step === 'type') {
            this.reactive.dispatch('chooseActivityType', {modname: name});
        } else if (step === 'h5p') {
            this.reactive.dispatch('chooseH5pType', {type: name});
        }
    }

    async _refresh() {
        const session = this.reactive.state.session;
        const step = String(session.typestep || 'type');

        this._show(this.getElement(this.selectors.TYPESTEP), step === 'type');
        this._show(this.getElement(this.selectors.KINDSTEP), step === 'h5p');

        // The field of the chat only shows once everything is chosen.
        const chatVisible = step === 'prompt';
        if (this.footer) {
            this.footer.classList.toggle('d-none', !chatVisible);
        }

        this._drawChoices(session);
        await this._placeholder(session, chatVisible);
    }

    /**
     * What was chosen so far, as the teacher's side of the conversation.
     *
     * @param {Object} session
     */
    _drawChoices(session) {
        const container = this.getElement(this.selectors.CHOICES);
        if (!container) {
            return;
        }

        container.replaceChildren();
        const type = this.typeItems.find((item) => item.name === session.activitytype);
        if (type) {
            container.append(this._bubble(type));
        }
        const kind = session.activitytype === 'h5pactivity' && this.kindItems.find((item) => item.name === session.h5ptype);
        if (kind) {
            container.append(this._bubble(kind));
        }
    }

    /**
     * A choice of the teacher: its picture and its name.
     *
     * @param {Object} item The chooser item that was chosen.
     * @returns {HTMLElement}
     */
    _bubble(item) {
        const bubble = document.createElement('div');
        bubble.className = 'd-inline-flex align-items-center bg-primary text-white rounded-pill px-3 py-2 mb-2';
        bubble.style.gap = '.5rem';
        const icon = document.createElement('span');
        icon.className = 'bg-white rounded d-inline-flex align-items-center justify-content-center';
        icon.style.padding = '2px';
        icon.innerHTML = item.icon;
        const image = icon.querySelector('img');
        if (image) {
            image.style.width = '24px';
            image.style.height = '24px';
            image.style.objectFit = 'contain';
        }
        const label = document.createElement('span');
        label.textContent = item.title;
        bubble.append(icon, label);
        return bubble;
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

        const name = chatVisible ? this._name(session) : '';
        prompt.setAttribute(
            'placeholder',
            name ? await getString('activitytype_placeholder', 'local_coursegen', name) : this.defaultPlaceholder
        );
    }

    /**
     * The name of what is going to be created: the type, and for H5P its kind.
     *
     * @param {Object} session
     * @returns {string} Empty when the type is not decided.
     */
    _name(session) {
        const type = this.typeItems.find((item) => item.name === session.activitytype);
        if (!type) {
            return '';
        }

        const kind = session.activitytype === 'h5pactivity' && this.kindItems.find((item) => item.name === session.h5ptype);
        return kind ? type.title + ' · ' + kind.title : type.title;
    }

    _show(element, visible) {
        element?.classList.toggle('d-none', !visible);
    }
}
