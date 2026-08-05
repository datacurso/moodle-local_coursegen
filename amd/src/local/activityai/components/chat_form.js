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
 * Chat form component.
 *
 * @module     local_coursegen/local/activityai/components/chat_form
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Notification from 'core/notification';

import YUI from 'core/yui';

import * as repository from 'local_coursegen/local/activityai/repository';

export default class extends BaseComponent {
    create() {
        this.name = 'local_coursegen_activityai_chat_form';
        this.selectors = {
            FORM: "[data-region='local_coursegen/activity/form']",
            PROMPT: "[data-region='local_coursegen/activity/prompt']",
            SEND: "[data-region='local_coursegen/activity/send']",
            UPLOAD: "[data-region='local_coursegen/activity/upload']",
            REMOVE_SELECTED: "[data-region='local_coursegen/activity/selectedfile_remove']",
            RADIO: "input[name='generate_images']",
            LANG: "[data-region='local_coursegen/activity/lang']",
        };
    }

    stateReady() {
        const form = this.getElement(this.selectors.FORM);
        if (form) {
            this.addEventListener(form, 'submit', this._handleSubmit);
        }

        const textarea = this.getElement(this.selectors.PROMPT);
        if (textarea) {
            this.addEventListener(textarea, 'keydown', this._handleEnterSubmit);
            this.addEventListener(textarea, 'input', this._autoResize);
        }

        const radios = this.getElements(this.selectors.RADIO);
        if (radios) {
            radios.forEach((rb) => {
                this.addEventListener(rb, 'change', this._handleRadioChange);
            });
        }

        const langSelect = this.getElement(this.selectors.LANG);
        if (langSelect) {
            this.addEventListener(langSelect, 'change', this._handleLangChange);
        }

        const uploadBtn = this.getElement(this.selectors.UPLOAD);
        if (uploadBtn) {
            this.addEventListener(uploadBtn, 'click', this._handleUploadClick);
        }

        const removeSelected = this.getElement(this.selectors.REMOVE_SELECTED);
        if (removeSelected) {
            this.addEventListener(removeSelected, 'click', this._handleRemoveSelected);
        }
    }

    getWatchers() {
        return [
            {watch: 'session.locked:updated', handler: this._refreshLocked},
            {watch: 'session.phase:updated', handler: this._refreshPhase},
        ];
    }

    _refreshLocked({element}) {
        const locked = Boolean(element.locked);

        const textarea = this.getElement(this.selectors.PROMPT);
        const send = this.getElement(this.selectors.SEND);
        const radios = this.getElements(this.selectors.RADIO);
        const uploadBtn = this.getElement(this.selectors.UPLOAD);
        const langSelect = this.getElement(this.selectors.LANG);

        if (textarea) {
            textarea.disabled = locked;
        }
        if (send) {
            send.disabled = locked;
        }
        if (uploadBtn) {
            uploadBtn.disabled = locked;
        }
        if (radios) {
            radios.forEach((rb) => {
                rb.disabled = locked;
            });
        }
        if (langSelect) {
            langSelect.disabled = locked;
        }
    }

    _refreshPhase({element}) {
        const phase = String(element.phase || '');
        const locked = Boolean(element.locked);

        if (locked) {
            return;
        }

        if (phase !== 'planning_feedback') {
            return;
        }

        const textarea = this.getElement(this.selectors.PROMPT);
        if (textarea) {
            textarea.focus();
        }
    }

    _handleEnterSubmit(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            const form = this.getElement(this.selectors.FORM);
            if (form) {
                form.requestSubmit();
            }
        }
    }

    _autoResize(event) {
        event.target.style.height = 'auto';
        event.target.style.height = event.target.scrollHeight + 'px';
    }

    _handleRadioChange(event) {
        const value = Number(event.target.value || 0) || 0;
        this.reactive.dispatch('setGenerateImages', value);
    }

    _handleLangChange(event) {
        const value = String(event.target.value || '').toLowerCase();
        this.reactive.dispatch('setLang', value);
    }

    async _handleSubmit(event) {
        event.preventDefault();

        const textarea = this.getElement(this.selectors.PROMPT);
        const prompt = String(textarea?.value || '').trim();
        if (!prompt) {
            textarea?.focus();
            return;
        }

        if (textarea) {
            textarea.value = '';
            textarea.style.height = 'auto';
        }

        try {
            await this.reactive.dispatch('submitPrompt', {prompt});
        } catch (error) {
            Notification.exception(error);
        }
    }

    async _handleUploadClick(event) {
        event.preventDefault();

        const state = this.reactive.state;
        if (state.upload.draftitemid) {
            return;
        }

        try {
            const pickerdata = await repository.initFilepicker({courseid: state.page.courseid});
            if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
                return;
            }

            const pickerOptions = JSON.parse(pickerdata.options);
            const clientIdKey = 'client_id';
            pickerOptions[clientIdKey] = pickerdata.clientid;
            pickerOptions.itemid = pickerdata.draftitemid;

            YUI.use('core_filepicker', 'node', 'node-event-simulate', 'core_dndupload', (Y) => {
                if (pickerdata.templates) {
                    try {
                        const templates = JSON.parse(pickerdata.templates);
                        if (templates && typeof templates === 'object') {
                            M.core_filepicker.set_templates(Y, templates);
                        }
                    } catch (ex) {
                        // Ignore.
                    }
                }

                pickerOptions.formcallback = (fileinfo) => {
                    this.reactive.dispatch('setUpload', {
                        draftitemid: pickerOptions.itemid,
                        filename: fileinfo && fileinfo.file ? String(fileinfo.file) : '',
                    });
                };

                if (!M.core_filepicker.instances[pickerOptions[clientIdKey]]) {
                    M.core_filepicker.init(Y, pickerOptions);
                }

                M.core_filepicker.instances[pickerOptions[clientIdKey]].show();
            });
        } catch (error) {
            Notification.exception(error);
        }
    }

    _handleRemoveSelected(event) {
        event.preventDefault();
        this.reactive.dispatch('setUpload', {draftitemid: null, filename: ''});
    }
}
