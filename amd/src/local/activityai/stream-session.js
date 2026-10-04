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
 * Reads the generation stream of an Activity AI session and applies its events to the reactive state.
 *
 * @module     local_coursegen/local/activityai/stream-session
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import RelaySource from 'local_coursegen/local/courseai/stream/relay-source';

let eventSource = null;

const formatTemplate = (template, data = {}) => {
    if (!template) {
        return '';
    }

    return String(template).replace(/\{(\w+)\}/g, (match, key) => {
        return Object.prototype.hasOwnProperty.call(data, key) ? String(data[key]) : match;
    });
};

const safeJsonParse = (text) => {
    try {
        return JSON.parse(text);
    } catch (e) {
        return null;
    }
};

export const closeStream = () => {
    if (!eventSource) {
        return;
    }

    try {
        eventSource.close();
    } catch (e) {
        // Ignore.
    }

    eventSource = null;
};

/**
 * Connect to the generation stream and update state for the provided run.
 *
 * The promise stays pending until the stream ends.
 *
 * @param {StateManager} stateManager
 * @param {number} runid Run the events belong to.
 * @param {{uiTexts: Object, createActivity: Function}} deps Texts, and the action that creates the activity from
 *     the state manager and the texts.
 * @returns {Promise<void>}
 */
export const connectStream = async(stateManager, runid, deps) => {
    const {uiTexts, createActivity} = deps;
    const state = stateManager.state;
    const run = state.runs.get(runid);
    const streamUrl = String(state.session.streamingurl || '').trim();

    if (!run || !streamUrl) {
        return;
    }

    closeStream();

    await new Promise((resolve) => {
        eventSource = new RelaySource(streamUrl);

        const markDone = () => {
            stateManager.setReadOnly(false);
            state.session.locked = false;
            state.session.phase = 'review';
            stateManager.setReadOnly(true);
            closeStream();
            resolve();
        };

        eventSource.onmessage = (event) => {
            const data = safeJsonParse(event.data);

            stateManager.setReadOnly(false);

            const currentRun = state.runs.get(runid);
            if (!currentRun) {
                stateManager.setReadOnly(true);
                return;
            }

            if (data && data.type === 'token') {
                currentRun.status = uiTexts.activityai_status_generating_content;
                currentRun.markdown += data.text || '';
            } else if (data && data.type === 'status') {
                currentRun.status = String(data.text || '');
            } else if (data && data.type === 'image_progress_init') {
                const totalImages = Number(data.total_images || 0);
                if (totalImages > 0) {
                    currentRun.status = formatTemplate(uiTexts.activityai_status_generating_images_progress, {
                        done: 0,
                        total: totalImages,
                    });
                } else {
                    currentRun.status = uiTexts.activityai_status_generating_images_simple;
                }
            } else if (data && data.type === 'image_progress_tick') {
                const done = Math.max(0, Number(data.done || 0));
                const total = Math.max(done, Number(data.total || 0));
                currentRun.status = formatTemplate(uiTexts.activityai_status_generating_images_progress, {
                    done,
                    total,
                });
            } else if (data && data.type === 'image_progress_done') {
                currentRun.status = uiTexts.activityai_status_images_generated;
            } else if (data && data.type === 'done') {
                // Ignore.
            } else if (data && data.type === 'review_needed') {
                currentRun.reviewneeded = true;
                currentRun.status = uiTexts.activityai_status_waiting_review;
                state.session.locked = false;
                state.session.phase = 'review';
                stateManager.setReadOnly(true);
                closeStream();
                resolve();
                return;
            } else if (data && data.type === 'completed') {
                const result = data.result || {};
                const hasResult = Boolean(result && (result.resource_type || Object.keys(result).length));

                // A "completed" without content means the generation did not actually
                // produce anything (stale terminal state): surface it as a failure
                // instead of trying to create an empty activity (404 on /result).
                if (currentRun.phase === 'generation' && !hasResult) {
                    currentRun.error = String(uiTexts.activityai_error_unknown);
                    currentRun.errorCode = 'generation_failed';
                    currentRun.retriable = true;
                    currentRun.status = '';
                    currentRun.reviewneeded = false;
                    currentRun.completed = false;

                    state.session.locked = false;
                    state.session.phase = 'idle';

                    stateManager.setReadOnly(true);
                    closeStream();
                    resolve();
                    return;
                }

                currentRun.completed = true;
                currentRun.status = uiTexts.activityai_status_completed;

                const shouldCreateActivity = currentRun.phase === 'generation';

                state.session.locked = false;
                state.session.phase = 'review';

                stateManager.setReadOnly(true);
                closeStream();

                // Only create the Moodle activity when generation is complete.
                if (shouldCreateActivity) {
                    (async() => {
                        await createActivity(stateManager, uiTexts);
                        resolve();
                    })();
                    return;
                }

                resolve();
                return;
            } else if (data && data.type === 'failed') {
                currentRun.error = String(
                    data.message || uiTexts.activityai_error_unknown
                );
                currentRun.errorCode = String(data.code || 'stream_error');
                currentRun.retriable = Boolean(data.retriable);
                currentRun.status = '';
                currentRun.reviewneeded = false;
                currentRun.completed = false;

                state.session.locked = false;
                state.session.phase = 'idle';

                stateManager.setReadOnly(true);
                closeStream();
                resolve();
                return;
            } else {
                currentRun.markdown += event ? event.data || '' : '';
            }

            stateManager.setReadOnly(true);
        };

        eventSource.addEventListener('done', () => {
            markDone();
        });

        eventSource.onerror = () => {
            stateManager.setReadOnly(false);
            const currentRun = state.runs.get(runid);
            if (currentRun) {
                currentRun.error = uiTexts.activityai_error_disconnected;
                currentRun.errorCode = 'stream_error';
                currentRun.retriable = false;
            }
            state.session.locked = false;
            state.session.phase = 'review';
            stateManager.setReadOnly(true);
            closeStream();
            resolve();
        };
    });
};
