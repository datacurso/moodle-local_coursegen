/* eslint-disable */
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
 * Controller for activity creation streaming inside the modal.
 * Mirrors the behaviour of aicoursecreation_page but for activities.
 *
 * @module     local_coursegen/activity_creation_page
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import * as markedModule from 'local_coursegen/marked';
import {regions, activityRegions} from 'local_coursegen/selectors';
import {createMod, sendActivityFeedback} from 'local_coursegen/repository/activity';

let eventSource = null;

/**
 * Initialise activity streaming UI inside the modal.
 *
 * @param {{
 *     streamingurl: string,
 *     courseid: number,
 *     sectionnum: (number|null),
 *     beforemod: (number|null),
 *     jobid: string,
 *     root?: HTMLElement
 * }} params
 */
export const init = async(params) => {
    try {
        const baseStreamingUrl = params.streamingurl;
        const courseid = params.courseid;
        const sectionnum = params.sectionnum;
        const beforemod = params.beforemod;
        const jobid = params.jobid;

        const explicitRoot = params?.root || null;
        const rootElement = explicitRoot || document.querySelector(regions.root) || document;

        const outputElement = rootElement.querySelector(regions.output);

        const chatFormElement = rootElement.querySelector(activityRegions.form);
        const chatPromptElement = rootElement.querySelector(activityRegions.promptTextarea);
        const chatSendButtonElement = rootElement.querySelector(activityRegions.sendButton);
        const chatRadios = rootElement.querySelectorAll("input[name='generate_images']");

        if (!outputElement) {
            throw new Error('Missing output container element for activity streaming.');
        }

        let lastStatusText = '';
        let currentStatusIconContainer = null;
        let currentStreamingBlock = null;
        let accumulatedMarkdown = '';

        const setChatEnabled = (enabled) => {
            const disabled = !enabled;

            if (chatPromptElement) {
                chatPromptElement.disabled = disabled;
            }

            if (chatSendButtonElement) {
                chatSendButtonElement.disabled = disabled;
            }

            if (chatRadios && chatRadios.length) {
                chatRadios.forEach((rb) => {
                    rb.disabled = disabled;
                });
            }
        };

        const setStatus = (text, isWorking = true, appendAtEnd = false) => {
            if (!text || text === lastStatusText) {
                return;
            }
            lastStatusText = text;

            if (currentStatusIconContainer) {
                currentStatusIconContainer.innerHTML = '<i class="fa fa-check text-success mr-2"></i>';
                currentStatusIconContainer = null;
            }

            const div = document.createElement('div');
            div.className = 'd-flex align-items-center text-muted my-2 small font-weight-bold text-uppercase tracking-wide';

            const iconContainer = document.createElement('span');
            if (isWorking) {
                iconContainer.innerHTML = '<div class="spinner-border spinner-border-sm mr-2 text-primary" role="status"></div>';
                currentStatusIconContainer = iconContainer;
            } else {
                iconContainer.innerHTML = '<i class="fa fa-info-circle mr-2 text-info"></i>';
            }

            const textSpan = document.createElement('span');
            textSpan.textContent = text;

            div.appendChild(iconContainer);
            div.appendChild(textSpan);

            if (currentStreamingBlock && currentStreamingBlock.parentNode === outputElement && !appendAtEnd) {
                outputElement.insertBefore(div, currentStreamingBlock);
            } else {
                outputElement.appendChild(div);
            }

            window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
        };

        const safeJsonParse = (text) => {
            try {
                return JSON.parse(text);
            } catch (e) {
                return null;
            }
        };

        const markedParser = markedModule.parse ? markedModule : markedModule.marked;
        const streamUrl = baseStreamingUrl;

        if (eventSource) {
            try {
                eventSource.close();
            } catch (e) {
                // Ignore.
            }
        }

        // Do not clear outputElement so previous runs remain visible in the UI.
        // Just reset the state for the new streaming block.
        lastStatusText = '';
        currentStatusIconContainer = null;
        accumulatedMarkdown = '';
        currentStreamingBlock = null;

        setChatEnabled(false);

        setStatus('Conectando al servidor AI...', true);

        if (!streamUrl) {
            setStatus('Error: URL de conexión faltante', false, true);
            return;
        }

        const render = () => {
            if (!accumulatedMarkdown && !currentStreamingBlock) {
                return;
            }

            if (!currentStreamingBlock) {
                const msgDiv = document.createElement('div');
                msgDiv.className = 'text-content mb-4 mt-3';
                outputElement.appendChild(msgDiv);
                currentStreamingBlock = msgDiv;
            }

            const html = markedParser.parse(accumulatedMarkdown || '');
            currentStreamingBlock.innerHTML = html;
            window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
        };

        const showReviewButtons = () => {
            const container = document.createElement('div');
            container.className = 'local-coursegen-review-actions mt-4 mb-3 d-flex align-items-center';

            const btnAccept = document.createElement('button');
            btnAccept.type = 'button';
            btnAccept.className = 'btn btn-primary mr-2 shadow-sm';
            btnAccept.textContent = 'Aceptar y crear actividad';

            const btnAdjust = document.createElement('button');
            btnAdjust.type = 'button';
            btnAdjust.className = 'btn btn-outline-secondary shadow-sm';
            btnAdjust.textContent = 'Ajustar planificación';

            container.appendChild(btnAccept);
            container.appendChild(btnAdjust);
            outputElement.appendChild(container);
            
            const sendFeedback = async(action) => {
                if (!jobid) {
                    return;
                }

                const instruction = chatPromptElement ? String(chatPromptElement.value || '').trim() : '';

                if (action === 'adjust' && !instruction) {
                    if (chatPromptElement) {
                        chatPromptElement.focus();
                    }
                    return;
                }

                container.remove();

                if (chatPromptElement) {
                    chatPromptElement.value = '';
                }

                setChatEnabled(false);

                try {
                    setStatus(
                        action === 'accept'
                            ? 'Plan aceptado. Generando la actividad en el servidor...'
                            : 'Ajuste enviado. Reintentando planificación de la actividad...',
                        true,
                        true
                    );

                    await sendActivityFeedback({
                        courseid,
                        jobid,
                        approvalstatus: action,
                        instruction,
                    });

                    // Reiniciar estado para el nuevo streaming de construcción
                    accumulatedMarkdown = '';
                    currentStreamingBlock = null;
                    lastStatusText = '';

                    connectToStream();
                } catch (error) {
                    window.console.error(error);
                    setStatus('Error al enviar instrucciones para la actividad.', false, true);
                    setChatEnabled(true);
                }
            };

            btnAccept.addEventListener('click', () => {
                sendFeedback('accept');
            });

            btnAdjust.addEventListener('click', () => {
                // Solo habilitar el chat y enfocar el campo.
                setChatEnabled(true);
                if (chatPromptElement) {
                    chatPromptElement.focus();
                }
            });
        };

        const handleActivityCreation = async() => {
            if (!courseid || !jobid) {
                return;
            }

            try {
                setStatus('Contenido generado. Creando la actividad en Moodle...', true, true);
                const result = await createMod({courseid, sectionnum, jobid, beforemod});

                if (!result || !result.ok) {
                    setStatus(result?.message || 'Error al crear la actividad.', false, true);
                    return;
                }

                const activityUrl = result?.data?.activityurl || null;
                setStatus('Actividad creada con éxito. Abriendo la actividad...', false, true);

                if (activityUrl) {
                    window.location.href = activityUrl;
                } else {
                    window.location.reload();
                }
            } catch (error) {
                window.console.error(error);
                setStatus('Error crítico al crear la actividad.', false, true);
            }
        };

        const connectToStream = () => {
            if (eventSource) {
                try {
                    eventSource.close();
                } catch (e) {
                    // Ignore.
                }
            }

            eventSource = new EventSource(streamUrl);

            eventSource.onmessage = async(event) => {
                const data = safeJsonParse(event.data);

                if (data && data.type === 'token') {
                    setStatus('Generando contenido...', true, false);
                    accumulatedMarkdown += data.text || '';
                } else if (data && data.type === 'status') {
                    setStatus(data.text || '', true, false);
                } else if (data && data.type === 'done') {
                    // Ignore it so it does not appear in the rendered markdown.
                    return;
                } else if (data && data.type === 'completed') {
                    if (eventSource) {
                        eventSource.close();
                    }
                    await handleActivityCreation();
                } else if (data && data.type === 'review_needed') {
                    if (eventSource) {
                        eventSource.close();
                    }
                    setStatus('Esperando tu revisión para la actividad.', false, true);
                    showReviewButtons();
                } else {
                    accumulatedMarkdown += event ? event.data || '' : '';
                }

                render();
            };

            eventSource.addEventListener('done', () => {
                if (eventSource) {
                    eventSource.close();
                }
                if (currentStatusIconContainer) {
                    currentStatusIconContainer.innerHTML = '<i class="fa fa-check text-success mr-2"></i>';
                    currentStatusIconContainer = null;
                }
            });

            eventSource.onerror = (error) => {
                window.console.error('Error in SSE connection (activity):', error);
                try {
                    eventSource.close();
                } catch (e) {
                    // Ignore.
                }
                setStatus('Desconectado del servidor.', false, true);
            };
        };

        connectToStream();
    } catch (error) {
        Notification.exception(error);
    }
};
