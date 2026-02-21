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
let eventSource = null;

/**
 * Init controller.
 *
 * @param {{recordid: number, streamingurl: string}} params
 */
export const init = async(params) => {
    try {
        const rootElement = document.querySelector(regions.root) || document;
        const outputElement = rootElement.querySelector(regions.output);
        const statusElement = rootElement.querySelector(regions.status);

        const baseStreamingUrl = String(params?.streamingurl ?? '').trim();
        let accumulatedMarkdown = '';

        const markedParser = markedModule.parse ? markedModule : markedModule.marked;

        if (eventSource) {
            eventSource.close();
        }

        outputElement.innerHTML = '';
        if (statusElement) {
            statusElement.textContent = 'Connecting...';
        }

        eventSource = new EventSource(baseStreamingUrl);

        const render = () => {
            outputElement.innerHTML = markedParser.parse(accumulatedMarkdown);
        };

        eventSource.addEventListener('token', (event) => {
            accumulatedMarkdown += event.data || '';
            render();
            if (statusElement) {
                statusElement.textContent = 'Streaming...';
            }
        });

        eventSource.addEventListener('status', (event) => {
            if (statusElement) {
                statusElement.textContent = event.data || '';
            }
        });

        eventSource.onmessage = (event) => {
            try {
                const parsedData = JSON.parse(event.data);
                accumulatedMarkdown += (parsedData && parsedData.text) ? parsedData.text : '';
            } catch (e) {
                accumulatedMarkdown += event.data || '';
            }

            render();
            if (statusElement) {
                statusElement.textContent = 'Streaming...';
            }
        };

        const closeStream = () => {
            eventSource.close();
            if (statusElement) {
                statusElement.textContent = 'Done.';
            }
        };

        eventSource.addEventListener('done', closeStream);
        eventSource.addEventListener('complete', closeStream);

        eventSource.onerror = (error) => {
            // eslint-disable-next-line no-console
            console.error('Error in SSE connection:', error);
            eventSource.close();
            if (statusElement) {
                statusElement.textContent = 'Connection terminated or error.';
            }
        };
    } catch (error) {
        Notification.exception(error);
    }
};
