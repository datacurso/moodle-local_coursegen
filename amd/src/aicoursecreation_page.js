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

import Notification from 'core/notification';
import * as markedModule from 'local_coursegen/marked';
import {regions} from 'local_coursegen/selectors';
import {sendPlanningFeedback, createCourse} from 'local_coursegen/repository/course';
let eventSource = null;

/**
 * Init controller.
 *
 * @param {{recordid: number, streamingurl: string}} params
 */
export const init = async(params) => {
    try {
        const rootElement = document.querySelector(regions.root) || document;
        const recordIdAttr = rootElement.getAttribute('data-recordid');
        const recordId = recordIdAttr ? Number(recordIdAttr) : 0;
        const statusElement = rootElement.querySelector(regions.status);
        const outputElement = rootElement.querySelector(regions.output);
        const feedbackPanelElement = rootElement.querySelector(regions.feedbackPanel);
        const feedbackTextElement = rootElement.querySelector(regions.feedbackText);
        const btnAcceptElement = rootElement.querySelector(regions.btnAccept);
        const btnReviseElement = rootElement.querySelector(regions.btnRevise);

        const baseStreamingUrl = String(params?.streamingurl ?? '').trim();

        if (!outputElement) {
            throw new Error('Missing output container element.');
        }

        const setStatus = (text) => {
            if (statusElement) {
                statusElement.textContent = text || '';
            }
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

        const showFeedbackPanel = () => {
            if (feedbackPanelElement) {
                feedbackPanelElement.classList.remove('d-none');
            }
        };

        const hideFeedbackPanel = () => {
            if (feedbackPanelElement) {
                feedbackPanelElement.classList.add('d-none');
            }
        };

        const escapeHtml = (str) => {
            return String(str || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        };

        const addMessage = (sender, text, type = 'system') => {
            const div = document.createElement('div');
            div.style.marginBottom = '12px';
            div.style.padding = '10px';
            div.style.borderRadius = '6px';
            div.style.whiteSpace = 'pre-wrap';

            if (type === 'user') {
                div.style.background = '#e8f5e9';
                div.style.borderLeft = '4px solid #4caf50';
            } else if (type === 'ai') {
                div.style.background = '#fff3e0';
                div.style.borderLeft = '4px solid #ff9800';
            } else {
                div.style.background = '#e3f2fd';
                div.style.borderLeft = '4px solid #2196f3';
            }

            div.innerHTML = `<strong>${escapeHtml(sender)}:</strong><br>${escapeHtml(text)}`;
            outputElement.appendChild(div);
            outputElement.scrollTop = outputElement.scrollHeight;
            return div;
        };

        let currentStreamingBlock = null;
        let accumulatedMarkdown = '';

        if (eventSource) {
            eventSource.close();
        }

        outputElement.innerHTML = '';
        setStatus('Connecting...');

        if (!streamUrl) {
            setStatus('Missing streaming URL.');
            return;
        }

        const render = () => {
            if (!currentStreamingBlock) {
                const msgDiv = document.createElement('div');
                msgDiv.style.marginBottom = '12px';
                msgDiv.style.padding = '10px';
                msgDiv.style.borderRadius = '6px';
                msgDiv.style.whiteSpace = 'pre-wrap';
                msgDiv.style.background = '#fff3e0';
                msgDiv.style.borderLeft = '4px solid #ff9800';
                msgDiv.innerHTML = '<strong>IA (Planificador):</strong><br><span class="text-content"></span>';
                outputElement.appendChild(msgDiv);
                currentStreamingBlock = msgDiv.querySelector('.text-content');
            }

            // Render accumulated markdown inside the AI planner bubble.
            const html = markedParser.parse(accumulatedMarkdown || '');
            currentStreamingBlock.innerHTML = html;
            outputElement.scrollTop = outputElement.scrollHeight;
        };

        const handleCourseCreation = async() => {
            if (!recordId) {
                setStatus('Course plan completed. No planning session found.');
                return;
            }

            try {
                setStatus('Course plan completed. Creating course...');
                const result = await createCourse({recordid: recordId});

                if (!result || !result.success) {
                    setStatus(result && result.message ? result.message : 'Error creating course.');
                    return;
                }

                setStatus(result.message || 'Course created successfully.');

                if (result.courseurl) {
                    window.location.href = result.courseurl;
                }
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error(error);
                setStatus('Error creating course.');
            }
        };

        const connectToStream = () => {
            if (eventSource) {
                try {
                    eventSource.close();
                } catch (e) {
                    // Ignore
                }
            }

            setStatus('Connecting...');

            eventSource = new EventSource(streamUrl);

            eventSource.onmessage = async(event) => {
                const data = safeJsonParse(event.data);

                if (data && data.type === 'token') {
                    accumulatedMarkdown += data.text || '';
                } else if (data && data.type === 'status') {
                    setStatus(data.text || '');
                    addMessage('Sistema', data.text || '', 'system');
                } else if (data && data.type === 'completed') {
                    if (eventSource) {
                        eventSource.close();
                    }
                    await handleCourseCreation();
                } else if (data && data.type === 'review_needed') {
                    if (eventSource) {
                        eventSource.close();
                    }
                    showFeedbackPanel();
                    setStatus('Review required');
                } else {
                    accumulatedMarkdown += event ? event.data || '' : '';
                }

                if (statusElement && !statusElement.textContent) {
                    setStatus('Streaming...');
                }
                render();
            };

            eventSource.addEventListener('done', () => {
                if (eventSource) {
                    eventSource.close();
                }
                setStatus('Done.');
            });

            eventSource.onerror = (error) => {
                // eslint-disable-next-line no-console
                console.error('Error in SSE connection:', error);
                try {
                    eventSource.close();
                } catch (e) {
                    // Ignore
                }
                setStatus('Connection terminated or error.');
            };
        };

        const sendFeedback = async(action) => {
            if (!recordId) {
                return;
            }

            const feedbackText = feedbackTextElement ? String(feedbackTextElement.value || '').trim() : '';
            if (action === 'adjust' && !feedbackText) {
                // Simple validation, do not show any technical detail.
                setStatus('Please enter the changes you want.');
                return;
            }

            if (action === 'accept') {
                addMessage('You', 'Plan accepted. Proceed to build.', 'user');
            } else {
                addMessage('You', `Requested correction: ${feedbackText}`, 'user');
            }

            hideFeedbackPanel();
            if (feedbackTextElement) {
                feedbackTextElement.value = '';
            }

            try {
                const data = await sendPlanningFeedback({
                    recordid: recordId,
                    action,
                    instruction: feedbackText,
                });
                window.console.log(data);

                if (!data.success) {
                    setStatus('Error sending feedback.');
                    return;
                }

                if (data.action === 'approve') {
                    setStatus('Feedback saved. Continuing generation.');
                } else {
                    setStatus('Adjustments sent. Replanning.');
                }

                connectToStream();
            } catch (error) {
                window.console.error(error);
                setStatus('Error sending feedback.');
            }
        };

        if (btnAcceptElement) {
            btnAcceptElement.addEventListener('click', () => sendFeedback('accept'));
        }

        if (btnReviseElement) {
            btnReviseElement.addEventListener('click', () => sendFeedback('adjust'));
        }

        connectToStream();
    } catch (error) {
        Notification.exception(error);
    }
};
