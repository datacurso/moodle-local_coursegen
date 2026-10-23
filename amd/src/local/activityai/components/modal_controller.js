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
 * Modal controller component.
 *
 * It reacts to `modal.open` state changes and is responsible for creating/destroying the Moodle modal.
 *
 * @module     local_coursegen/local/activityai/components/modal_controller
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

import ChatForm from 'local_coursegen/local/activityai/components/chat_form';
import StreamOutput from 'local_coursegen/local/activityai/components/stream_output';
import TypePicker from 'local_coursegen/local/activityai/components/type_picker';
import CoursePresentationBuilder from 'local_coursegen/local/activityai/components/course_presentation_builder';
import {fetchSupportedItems, kindItems} from 'local_coursegen/local/template/template_space_chooser';

export default class extends BaseComponent {
    create() {
        this.name = 'local_coursegen_activityai_modal_controller';
        this.modal = null;
    }

    async _handleOpenChange({element}) {
        try {
            if (element.open) {
                await this._open();
            } else {
                await this._close();
            }
        } catch (error) {
            Notification.exception(error);
        }
    }

    async _open() {
        if (this.modal) {
            await this.modal.destroy();
            this.modal = null;
        }

        const bodyHTML = await Templates.render('local_coursegen/add_activity_ai_modal', {});
        const typeItems = await fetchSupportedItems(
            this.reactive.state.page.courseid,
            this.reactive.state.page.activitytypes.map((type) => type.modname)
        );
        const kindList = kindItems(
            this.reactive.state.page.h5ptypes,
            await getString('h5ptype_usesimages', 'local_coursegen')
        );
        const pickerHTML = await Templates.render('local_coursegen/activity_type_picker', {
            typeitems: typeItems,
            kinditems: kindList,
        });

        const state = this.reactive.state;
        const selectedlang = String(state?.session?.lang || state?.page?.defaultlang || 'en').toLowerCase();
        const languageItems = (state?.page?.languages || []).map((language) => {
                const code = String(language.code || '').toLowerCase();
                return {
                    code,
                    name: String(language.name || code.toUpperCase()),
                    selected: code === selectedlang,
                };
            });
        if (languageItems.length === 0) {
            languageItems.push({
                code: selectedlang,
                name: selectedlang.toUpperCase(),
                selected: true,
            });
        }

        const footercontext = {languages: languageItems, canimages: state.page.canimages};
        const footerHTML = await Templates.render('local_coursegen/activity_chat_footer', footercontext);

        const title = await getString('addactivityai_modaltitle', 'local_coursegen');

        this.modal = await Modal.create({
            title,
            body: bodyHTML,
            footer: footerHTML,
            large: true,
            scrollable: true,
            removeOnClose: true,
        });

        this.modal.getRoot().addClass('local_coursegen_course_ai_modal');
        this.modal.show();
        this._addHeaderBack(await getString('activitytype_back', 'local_coursegen'));

        const rootElement = this.modal.getRoot()[0];
        if (!rootElement) {
            return;
        }

        const slot = rootElement.querySelector("[data-region='local_coursegen/activity/typepicker_slot']");
        if (slot) {
            slot.innerHTML = pickerHTML;
            new TypePicker({
                element: slot,
                reactive: this.reactive,
                footer: rootElement.querySelector('.modal-footer'),
                typeItems,
                kindItems: kindList,
            });

            const builderSlot = document.createElement('div');
            slot.append(builderSlot);
            new CoursePresentationBuilder({
                element: builderSlot,
                reactive: this.reactive,
                footer: rootElement.querySelector('.modal-footer'),
            });
        }

        new StreamOutput({
            element: rootElement,
            reactive: this.reactive,
        });

        new ChatForm({
            element: rootElement,
            reactive: this.reactive,
        });

        this.modal.getRoot().on(ModalEvents.hidden, () => {
            this.reactive.dispatch('closeModal');
        });
    }

    getWatchers() {
        return [
            {watch: 'modal.open:updated', handler: this._handleOpenChange},
            {watch: 'session.typestep:updated', handler: this._syncBack},
            {watch: 'session.jobid:updated', handler: this._syncBack},
        ];
    }

    /**
     * The Back button is for before the conversation starts and after the first step: there is nothing before the
     * types, and once the conversation has started the type cannot change.
     */
    _syncBack() {
        const session = this.reactive.state.session;
        const button = this.modal?.getRoot()[0].querySelector('[data-region="local_coursegen/activity/back"]');
        button?.classList.toggle('d-none', Boolean(session.jobid) || session.typestep === 'type');
    }

    /**
     * A Back button at the start of the header of the chat, in sight while the conversation has not started.
     *
     * @param {string} label The text of the button.
     */
    _addHeaderBack(label) {
        const header = this.modal.getRoot()[0].querySelector('.modal-header');
        if (!header) {
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link p-0 mr-3 text-nowrap';
        button.dataset.region = 'local_coursegen/activity/back';
        const arrow = document.createElement('span');
        arrow.className = 'fa fa-arrow-left mr-1';
        arrow.setAttribute('aria-hidden', 'true');
        button.append(arrow, document.createTextNode(label));
        button.addEventListener('click', () => this.reactive.dispatch('goBack'));
        header.prepend(button);
        this._syncBack();
    }

    async _close() {
        if (!this.modal) {
            return;
        }

        await this.modal.destroy();
        this.modal = null;
    }

    destroy() {
        if (this.modal) {
            this.modal.destroy();
            this.modal = null;
        }
    }
}
