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
 * Page controller for course planning & creation streaming.
 *
 * @module     local_coursegen/aicoursecreation_page
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This file is part of Moodle - http://moodle.org/

import Notification from 'core/notification';
import * as markedModule from 'local_coursegen/marked';
import {regions} from 'local_coursegen/selectors';
import {sendPlanningFeedback, createCourse} from 'local_coursegen/repository/course';
let eventSource = null;

export const init = async(params) => {
    try {
        const rootElement = document.querySelector(regions.root) || document;
        const recordIdAttr = rootElement.getAttribute('data-recordid');
        const recordId = recordIdAttr ? Number(recordIdAttr) : 0;
        const outputElement = rootElement.querySelector(regions.output);
        const feedbackPanelElement = rootElement.querySelector(regions.feedbackPanel);
        const feedbackTextElement = rootElement.querySelector(regions.feedbackText);
        const btnAcceptElement = rootElement.querySelector(regions.btnAccept);
        const btnReviseElement = rootElement.querySelector(regions.btnRevise);
        
        const btnShowAdjust = rootElement.querySelector('#btn-show-adjust');
        const adjustInputContainer = rootElement.querySelector('#adjust-input-container');

        const baseStreamingUrl = String(params?.streamingurl ?? '').trim();

        if (!outputElement) throw new Error('Missing output container element.');

        const escapeHtml = (str) => {
            return String(str || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        };

        let lastStatusText = '';
        let currentStatusIconContainer = null; 
        let currentStreamingBlock = null; 
        let accumulatedMarkdown = '';

        const setStatus = (text, isWorking = true, appendAtEnd = false) => {
            if (!text || text === lastStatusText) return;
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
            
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        };

        const safeJsonParse = (text) => {
            try { return JSON.parse(text); } 
            catch (e) { return null; }
        };

        const markedParser = markedModule.parse ? markedModule : markedModule.marked;
        const streamUrl = baseStreamingUrl;

        const showFeedbackPanel = () => {
            if (feedbackPanelElement) {
                feedbackPanelElement.classList.remove('d-none');
                feedbackPanelElement.scrollIntoView({ behavior: 'smooth', block: 'end' });
            }
        };

        const hideFeedbackPanel = () => {
            if (feedbackPanelElement) {
                feedbackPanelElement.classList.add('d-none');
            }
            if (adjustInputContainer) {
                adjustInputContainer.classList.add('d-none');
            }
        };

        const addMessage = (sender, text, type = 'system') => {
            const div = document.createElement('div');
            
            if (type === 'user') {
                div.className = 'd-flex justify-content-end my-3 border-top pt-3 mt-4';
                div.innerHTML = `<span class="badge badge-light border border-secondary text-muted p-2" style="font-size: 0.9rem;">
                                    <i class="fa fa-user mr-1"></i> Tú pediste: ${escapeHtml(text)}
                                 </span>`;
                outputElement.appendChild(div);
                window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
            }
            return div;
        };

        if (eventSource) eventSource.close();

        outputElement.innerHTML = '';
        lastStatusText = ''; 
        currentStatusIconContainer = null;

        setStatus('Conectando al servidor AI...', true);

        if (!streamUrl) {
            setStatus('Error: URL de conexión faltante', false, true);
            return;
        }

        const render = () => {
            if (!accumulatedMarkdown && !currentStreamingBlock) return;

            if (!currentStreamingBlock) {
                const msgDiv = document.createElement('div');
                msgDiv.className = 'text-content mb-4 mt-3'; 
                outputElement.appendChild(msgDiv);
                currentStreamingBlock = msgDiv;
            }

            const html = markedParser.parse(accumulatedMarkdown || '');
            currentStreamingBlock.innerHTML = html;
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        };

        const handleCourseCreation = async() => {
            if (!recordId) return;

            try {
                setStatus('Plan completado. Creando el curso en Moodle...', true, true);
                const result = await createCourse({recordid: recordId});

                if (!result || !result.success) {
                    setStatus(result?.message || 'Error al crear el curso.', false, true);
                    return;
                }

                setStatus('Curso creado con éxito. Redirigiendo...', false, true);
                if (result.courseurl) window.location.href = result.courseurl;

            } catch (error) {
                window.console.error(error);
                setStatus('Error crítico al crear el curso.', false, true);
            }
        };

        const connectToStream = () => {
            if (eventSource) {
                try { eventSource.close(); } catch (e) {}
            }

            eventSource = new EventSource(streamUrl);

            eventSource.onmessage = async(event) => {
                const data = safeJsonParse(event.data);

                if (data && data.type === 'token') {
                    setStatus('Generando contenido...', true, false);
                    accumulatedMarkdown += data.text || '';
                } else if (data && data.type === 'status') {
                    setStatus(data.text || '', true, false);
                } else if (data && data.type === 'completed') {
                    if (eventSource) eventSource.close();
                    await handleCourseCreation();
                } else if (data && data.type === 'review_needed') {
                    if (eventSource) eventSource.close();
                    
                    if (currentStatusIconContainer) {
                        currentStatusIconContainer.innerHTML = '<i class="fa fa-check text-success mr-2"></i>';
                        currentStatusIconContainer = null;
                    }
                    setStatus('Esperando tu revisión', false, true);
                    showFeedbackPanel();
                } else {
                    accumulatedMarkdown += event ? event.data || '' : '';
                }

                render();
            };

            eventSource.addEventListener('done', () => {
                if (eventSource) eventSource.close();
                if (currentStatusIconContainer) {
                    currentStatusIconContainer.innerHTML = '<i class="fa fa-check text-success mr-2"></i>';
                    currentStatusIconContainer = null;
                }
            });

            eventSource.onerror = (error) => {
                window.console.error('Error in SSE connection:', error);
                try { eventSource.close(); } catch (e) {}
                setStatus('Desconectado del servidor.', false, true);
            };
        };

        const sendFeedback = async(action) => {
            if (!recordId) return;

            const feedbackText = feedbackTextElement ? String(feedbackTextElement.value || '').trim() : '';
            
            if (action === 'adjust' && !feedbackText) {
                if (feedbackTextElement) {
                    feedbackTextElement.focus();
                    feedbackTextElement.parentNode.style.borderColor = '#dc3545'; // Borde rojo en el contenedor
                    setTimeout(() => feedbackTextElement.parentNode.style.borderColor = '#d1d5db', 2000);
                }
                return; 
            }

            if (action === 'accept') {
                addMessage('Tú', 'Plan aceptado.', 'user');
            } else {
                addMessage('Tú', feedbackText, 'user');
            }

            hideFeedbackPanel();
            if (feedbackTextElement) {
                feedbackTextElement.value = '';
                feedbackTextElement.style.height = 'auto'; // Reseteamos la altura al enviar
            }
            
            currentStreamingBlock = null; 
            accumulatedMarkdown = ''; 
            lastStatusText = ''; 

            try {
                setStatus('Procesando tus instrucciones...', true, true);
                const data = await sendPlanningFeedback({ recordid: recordId, action, instruction: feedbackText });

                if (!data.success) {
                    setStatus('Error al enviar instrucciones al servidor.', false, true);
                    return;
                }

                connectToStream();
            } catch (error) {
                window.console.error(error);
                setStatus('Error al enviar instrucciones.', false, true);
            }
        };

        if (btnAcceptElement) btnAcceptElement.addEventListener('click', () => sendFeedback('accept'));
        if (btnReviseElement) btnReviseElement.addEventListener('click', () => sendFeedback('adjust'));
        
        if (btnShowAdjust && adjustInputContainer) {
            btnShowAdjust.addEventListener('click', () => {
                adjustInputContainer.classList.toggle('d-none');
                if (!adjustInputContainer.classList.contains('d-none') && feedbackTextElement) {
                    feedbackTextElement.focus();
                    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                }
            });
        }

        if (feedbackTextElement) {
            // Lógica para que el textarea crezca automáticamente
            feedbackTextElement.addEventListener('input', function() {
                this.style.height = 'auto'; // Resetea para recalcular
                this.style.height = (this.scrollHeight) + 'px'; // Ajusta al nuevo tamaño
            });

            feedbackTextElement.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendFeedback('adjust');
                }
            });
        }

        connectToStream();
    } catch (error) {
        Notification.exception(error);
    }
};
