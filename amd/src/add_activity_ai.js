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
 * TODO describe module add_activity_ai
 *
 * @module     local_coursegen/add_activity_ai
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';
import Modal from 'core/modal';
import CustomEvents from 'core/custom_interaction_events';
import {get_string as getString} from 'core/str';
import {createModStream, initActivityFilepicker, uploadActivityFile} from 'local_coursegen/repository/activity';
import * as activityStreamingPage from 'local_coursegen/activity_creation_page';
import {regions, activityRegions} from 'local_coursegen/selectors';
import YUI from 'core/yui';

const LINK_SELECTOR = '[data-action="local_coursegen/add_activity_ai"]';

let modal = null;
let initialized = false;

let pendingDraftItemId = null;
let pendingFileName = '';

/**
 * Update the selected file indicator UI.
 *
 * @param {HTMLElement} rootElement
 */
const renderSelectedFile = (rootElement) => {
    const selectedFileElement = rootElement.querySelector(activityRegions.selectedFile);
    const selectedFileNameElement = rootElement.querySelector(activityRegions.selectedFileName);
    const removeSelectedFileButton = rootElement.querySelector(activityRegions.removeSelectedFileButton);
    const uploadButtonElement = rootElement.querySelector(activityRegions.uploadButton);

    if (!selectedFileElement || !selectedFileNameElement || !removeSelectedFileButton) {
        return;
    }

    if (!pendingDraftItemId) {
        selectedFileElement.style.display = 'none';
        selectedFileNameElement.textContent = '';
        if (uploadButtonElement) {
            uploadButtonElement.disabled = false;
        }
        return;
    }

    selectedFileNameElement.textContent = pendingFileName || '';
    selectedFileElement.style.display = 'block';
    if (uploadButtonElement) {
        uploadButtonElement.disabled = true;
    }
};

/**
 * Escape HTML for safe text insertion.
 *
 * @param {string} value
 * @returns {string}
 */
const escapeHtml = (value) => {
    return String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
};

/**
 * Append a small "you asked" badge message to the user messages container.
 *
 * @param {HTMLElement|null} container
 * @param {string} prompt
 */
const appendUserPromptMessage = (container, prompt) => {
    if (!container) {
        return;
    }

    container.style.display = 'block';

    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex justify-content-end my-3 border-top pt-3 mt-4';

    const safePrompt = escapeHtml(prompt);
    wrapper.innerHTML = '' +
        '<span class="badge badge-light border border-secondary text-muted p-2" ' +
        'style="font-size: 1rem; line-height: 1.4; font-weight: normal; max-width: 100%; ' +
        'white-space: normal; word-break: break-word; text-align: left; display: inline-block;">' +
        '<i class="fa fa-user mr-1"></i> Tú pediste: ' + safePrompt +
        '</span>';

    container.appendChild(wrapper);
    window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
};

/**
 * Wire chat form handlers inside the modal.
 *
 * @param {HTMLElement} rootElement
 * @param {{courseid: number, sectionnum: number, beforemod: (number|null)}} payload
 */
const wireChatHandlers = (rootElement, payload) => {
    const formElement = rootElement.querySelector(activityRegions.form);
    const textareaElement = rootElement.querySelector(activityRegions.promptTextarea);
    const sendButtonElement = rootElement.querySelector(activityRegions.sendButton);
    const uploadButtonElement = rootElement.querySelector(activityRegions.uploadButton);
    const removeSelectedFileButton = rootElement.querySelector(activityRegions.removeSelectedFileButton);
    const chatRadios = rootElement.querySelectorAll("input[name='generate_images']");
    const streamingSectionElement = rootElement.querySelector(activityRegions.streamingSection);

    if (!formElement) {
        return;
    }

    formElement.addEventListener('submit', (e) => {
        e.preventDefault();

        if (textareaElement) {
            textareaElement.disabled = true;
        }

        if (sendButtonElement) {
            sendButtonElement.disabled = true;
        }

        if (chatRadios && chatRadios.length) {
            chatRadios.forEach((rb) => {
                rb.disabled = true;
            });
        }

        submitActivityPrompt(formElement, streamingSectionElement, rootElement, payload);
    });

    if (textareaElement) {
        textareaElement.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                formElement.requestSubmit();
            }
        });

        textareaElement.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    }

    // Upload button: open filepicker and store selected draft item.
    if (uploadButtonElement) {
        uploadButtonElement.addEventListener('click', async(e) => {
            e.preventDefault();

            if (pendingDraftItemId) {
                renderSelectedFile(rootElement);
                return;
            }

            try {
                const pickerdata = await initActivityFilepicker({courseid: payload.courseid});
                if (!pickerdata || !pickerdata.clientid || !pickerdata.draftitemid || !pickerdata.options) {
                    return;
                }

                const pickerOptions = JSON.parse(pickerdata.options);
                pickerOptions.client_id = pickerdata.clientid;
                pickerOptions.itemid = pickerdata.draftitemid;

                YUI.use('core_filepicker', 'node', 'node-event-simulate', 'core_dndupload', function(Y) {
                    if (pickerdata.templates) {
                        try {
                            const templates = JSON.parse(pickerdata.templates);
                            if (templates && typeof templates === 'object') {
                                M.core_filepicker.set_templates(Y, templates);
                            }
                        } catch (ex) {
                            // Ignore invalid templates.
                        }
                    }

                    pickerOptions.formcallback = function(fileinfo) {
                        pendingDraftItemId = pickerOptions.itemid;
                        pendingFileName = fileinfo && fileinfo.file ? String(fileinfo.file) : '';
                        renderSelectedFile(rootElement);
                    };

                    if (!M.core_filepicker.instances[pickerOptions.client_id]) {
                        M.core_filepicker.init(Y, pickerOptions);
                    }

                    M.core_filepicker.instances[pickerOptions.client_id].show();
                });
            } catch (err) {
                window.console.error(err);
            }
        });
    }

    if (removeSelectedFileButton) {
        removeSelectedFileButton.addEventListener('click', (e) => {
            e.preventDefault();
            pendingDraftItemId = null;
            pendingFileName = '';
            renderSelectedFile(rootElement);
        });
    }

    renderSelectedFile(rootElement);
};

/**
 * Handle activity chat form submission.
 *
 * @param {HTMLFormElement} formElement
 * @param {HTMLElement|null} streamingSectionElement
 * @param {HTMLElement} rootElement
 * @param {{courseid: number, sectionnum: number, beforemod: (number|null)}} payload
 */
async function submitActivityPrompt(formElement, streamingSectionElement, rootElement, payload) {
    const textarea = formElement.querySelector(activityRegions.promptTextarea);
    const sendButton = formElement.querySelector(activityRegions.sendButton);
    const chatRadios = formElement.querySelectorAll("input[name='generate_images']");
    const prompt = String(textarea && textarea.value ? textarea.value : '').trim();
    if (!prompt) {
        if (textarea) {
            textarea.focus();
        }
        return;
    }

    const selectedRadio = formElement.querySelector('input[name="generate_images"]:checked');
    const generateimages = selectedRadio ? Number(selectedRadio.value || 0) || 0 : 0;

    const courseid = payload.courseid;
    const sectionnum = payload.sectionnum;
    const beforemod = payload.beforemod;

    const reviewActionsContainer = rootElement.querySelector('.local-coursegen-review-actions');
    if (reviewActionsContainer) {
        reviewActionsContainer.remove();
    }

    if (textarea) {
        textarea.value = '';
        textarea.style.height = 'auto';
    }

    if (sendButton) {
        sendButton.disabled = true;
    }

    if (chatRadios && chatRadios.length) {
        chatRadios.forEach((rb) => {
            rb.disabled = true;
        });
    }

    if (streamingSectionElement) {
        streamingSectionElement.style.display = 'block';
    }

    const outputElement = rootElement.querySelector(regions.output);
    appendUserPromptMessage(outputElement || null, prompt);

    try {
        const response = await createModStream({
            courseid,
            sectionnum,
            beforemod,
            prompt,
            generateimages,
        });

        const jobid = response && response.job_id ? String(response.job_id) : '';
        if (pendingDraftItemId && jobid) {
            try {
                await uploadActivityFile({
                    courseid,
                    jobid,
                    draftitemid: pendingDraftItemId,
                });
            } catch (uploadError) {
                // Do not block streaming; just notify.
                Notification.exception(uploadError);
            } finally {
                pendingDraftItemId = null;
                pendingFileName = '';
                renderSelectedFile(rootElement);
            }
        }

        await handleStreamingResponse(response, streamingSectionElement, rootElement, {
            courseid,
            sectionnum,
            beforemod,
        });
    } catch (err) {
        Notification.exception(err);
    }
}

/**
 * Handle the response from the createModStream webservice call.
 *
 * @param {Object} response
 * @param {HTMLElement|null} streamingSectionElement
 * @param {HTMLElement} rootElement
 * @param {{courseid: number, sectionnum: (number|null), beforemod: (number|null)}} ctx
 */
async function handleStreamingResponse(response, streamingSectionElement, rootElement, ctx) {
    if (!response || !response.ok || !response.streamingurl) {
        Notification.alert(
            '',
            response && response.message
                ? response.message
                : 'Error al iniciar la generación de la actividad.',
            'close'
        );
        return;
    }

    if (streamingSectionElement) {
        streamingSectionElement.style.display = 'block';
    }

    await activityStreamingPage.init({
        streamingurl: response.streamingurl,
        courseid: ctx.courseid,
        sectionnum: ctx.sectionnum,
        beforemod: ctx.beforemod,
        jobid: response.job_id || '',
        root: rootElement,
    });
}

/**
 * Initialize the click handler for the add activity AI trigger.
 */
export const init = () => {
    if (initialized) {
        return;
    }
    initialized = true;

    const events = ['click', CustomEvents.events.activate, CustomEvents.events.keyboardActivate];
    CustomEvents.define(document, events);

    events.forEach((event) => {
        document.addEventListener(event, async(e) => {
            const link = e.target.closest(LINK_SELECTOR);
            if (!link) {
                return;
            }
            e.preventDefault();
            const payload = readDataset(link);
            await openChatModal(payload);
        });
    });
};

/**
 * Read expected dataset values.
 *
 * @param {HTMLElement} el
 * @returns {{sectionnum: number, beforemod: (number|null), courseid: (number|null)}}
 */
const readDataset = (el) => {
    const {sectionnum, beforemod, courseid} = el.dataset;
    return {
        sectionnum: Number(sectionnum),
        beforemod: beforemod ? Number(beforemod) : null,
        courseid: courseid ? Number(courseid) : null,
    };
};

/**
 * Open the activity AI modal.
 *
 * @param {{courseid: number, sectionnum: number, beforemod: (number|null)}} payload
 */
export const openChatModal = async(payload) => {
    try {
        if (modal) {
            await modal.destroy();
            modal = null;
        }

        const [bodyHTML, footerHTML] = await Promise.all([
            Templates.render('local_coursegen/add_activity_ai_modal', {}),
            Templates.render('local_coursegen/activity_chat_footer', {}),
        ]);

        const title = await getString('addactivityai_modaltitle', 'local_coursegen');

        modal = await Modal.create({
            title,
            body: bodyHTML,
            footer: footerHTML,
            large: true,
            scrollable: true,
            removeOnClose: true,
        });

        modal.getRoot().addClass('local_coursegen_course_ai_modal');
        modal.show();

        const rootElement = modal.getRoot()[0];
        if (rootElement) {
            wireChatHandlers(rootElement, payload);
        }

        modal.getRoot().on('hidden.bs.modal', () => {
            if (modal) {
                modal.destroy();
                modal = null;
            }
        });
    } catch (err) {
        Notification.exception(err);
    }
};
