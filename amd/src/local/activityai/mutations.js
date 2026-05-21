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
 * Mutations for Activity AI.
 *
 * @module     local_coursegen/local/activityai/mutations
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import notification from 'core/notification';
import * as repository from 'local_coursegen/local/activityai/repository';

let eventSource = null;

const safeJsonParse = (text) => {
    try {
        return JSON.parse(text);
    } catch (e) {
        return null;
    }
};

const closeStream = () => {
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

class Mutations {
    /**
     * Open modal and set current target position.
     *
     * @param {StateManager} stateManager
     * @param {{courseid: number, sectionnum: number, beforemod: (number|null)}} payload
     */
    openModal(stateManager, payload) {
        stateManager.setReadOnly(false);
        stateManager.state.session.sectionnum = Number(payload.sectionnum);
        stateManager.state.session.beforemod = payload.beforemod === null ? null : Number(payload.beforemod);
        stateManager.state.modal.open = true;
        stateManager.setReadOnly(true);
    }

    /**
     * Close modal.
     *
     * @param {StateManager} stateManager
     */
    closeModal(stateManager) {
        closeStream();
        stateManager.setReadOnly(false);
        stateManager.state.modal.open = false;
        stateManager.state.session.locked = false;
        stateManager.state.session.phase = 'idle';
        stateManager.setReadOnly(true);
    }

    /**
     * Enable the planning feedback phase.
     *
     * This mutation does not contact the backend. It just unlocks the chat so the user can type
     * the adjustment prompt. The next submitPrompt will send the feedback.
     *
     * @param {StateManager} stateManager
     */
    requestAdjust(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.session.locked = false;
        stateManager.state.session.phase = 'planning_feedback';
        stateManager.setReadOnly(true);
    }

    /**
     * Update generate images option.
     *
     * @param {StateManager} stateManager
     * @param {number} value
     */
    setGenerateImages(stateManager, value) {
        stateManager.setReadOnly(false);
        stateManager.state.session.generateimages = Number(value) || 0;
        stateManager.setReadOnly(true);
    }

    /**
     * Store selected upload info.
     *
     * @param {StateManager} stateManager
     * @param {{draftitemid: (number|null), filename: string}} file
     */
    setUpload(stateManager, file) {
        stateManager.setReadOnly(false);
        stateManager.state.upload.draftitemid = file.draftitemid === null ? null : Number(file.draftitemid) || null;
        stateManager.state.upload.filename = String(file.filename || '');
        stateManager.setReadOnly(true);
    }

    /**
     * Submit the initial prompt (creates session) OR a feedback prompt (adjust).
     *
     * @param {StateManager} stateManager
     * @param {{prompt: string}} payload
     */
    async submitPrompt(stateManager, payload) {
        const prompt = String(payload.prompt || '').trim();
        if (!prompt) {
            return;
        }

        const state = stateManager.state;
        const courseid = Number(state.page.courseid) || 0;
        const sectionnum = state.session.sectionnum;
        const beforemod = state.session.beforemod;

        const runid = Date.now();

        stateManager.setReadOnly(false);
        state.session.locked = true;
        state.session.phase = state.session.jobid ? 'planning_feedback_streaming' : 'planning';
        state.runs.set(runid, {
            id: runid,
            phase: state.session.phase,
            prompt,
            markdown: '',
            status: '',
            reviewneeded: false,
            completed: false,
            error: '',
        });
        stateManager.setReadOnly(true);

        try {
            if (!state.session.jobid) {
                const response = await repository.startSession({
                    courseid,
                    sectionnum,
                    beforemod,
                    prompt,
                    generateimages: state.session.generateimages,
                });

                const jobid = response && response.job_id ? String(response.job_id) : '';
                const streamingurl = response && response.streamingurl ? String(response.streamingurl) : '';

                if (!jobid || !streamingurl) {
                    throw new Error(response && response.message ? response.message : 'Missing streaming session response');
                }

                if (state.upload.draftitemid) {
                    try {
                        await repository.uploadFile({
                            courseid,
                            jobid,
                            draftitemid: state.upload.draftitemid,
                        });
                    } catch (uploadError) {
                        notification.exception(uploadError);
                    } finally {
                        stateManager.setReadOnly(false);
                        state.upload.draftitemid = null;
                        state.upload.filename = '';
                        stateManager.setReadOnly(true);
                    }
                }

                stateManager.setReadOnly(false);
                state.session.jobid = jobid;
                state.session.streamingurl = streamingurl;
                stateManager.setReadOnly(true);

            } else {
                await repository.sendFeedback({
                    courseid,
                    jobid: state.session.jobid,
                    approvalstatus: 'adjust',
                    instruction: prompt,
                });
            }

            await this.connectStream(stateManager, {runid});
        } catch (error) {
            stateManager.setReadOnly(false);
            const run = state.runs.get(runid);
            if (run) {
                run.error = error && error.message ? String(error.message) : 'Unknown error';
            }
            state.session.locked = false;
            state.session.phase = 'idle';
            stateManager.setReadOnly(true);
            throw error;
        }
    }

    /**
     * Accept planning and trigger generation.
     *
     * @param {StateManager} stateManager
     */
    async acceptAndGenerate(stateManager) {
        const state = stateManager.state;
        const courseid = Number(state.page.courseid) || 0;

        if (!state.session.jobid) {
            return;
        }

        const runid = Date.now();

        stateManager.setReadOnly(false);
        state.session.locked = true;
        state.session.phase = 'generation';
        state.runs.set(runid, {
            id: runid,
            phase: 'generation',
            prompt: '',
            markdown: '',
            status: 'Plan accepted. Generating...',
            reviewneeded: false,
            completed: false,
            error: '',
        });
        stateManager.setReadOnly(true);

        await repository.sendFeedback({
            courseid,
            jobid: state.session.jobid,
            approvalstatus: 'accept',
            instruction: '',
        });

        await this.connectStream(stateManager, {runid});
    }

    /**
     * Connect to SSE stream and update state for the provided run.
     *
     * This mutation will keep pending until the stream ends.
     *
     * @param {StateManager} stateManager
     * @param {{runid: number}} payload
     * @returns {Promise<void>}
     */
    async connectStream(stateManager, payload) {
        const state = stateManager.state;
        const runid = Number(payload.runid) || 0;
        const run = state.runs.get(runid);
        const streamUrl = String(state.session.streamingurl || '').trim();

        if (!run || !streamUrl) {
            return;
        }

        closeStream();

        await new Promise((resolve) => {
            eventSource = new EventSource(streamUrl);

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
                    currentRun.status = 'Generating content...';
                    currentRun.markdown += data.text || '';
                } else if (data && data.type === 'status') {
                    currentRun.status = String(data.text || '');
                } else if (data && data.type === 'done') {
                    // Ignore.
                } else if (data && data.type === 'review_needed') {
                    currentRun.reviewneeded = true;
                    currentRun.status = 'Waiting for your review.';
                    state.session.locked = false;
                    state.session.phase = 'review';
                    stateManager.setReadOnly(true);
                    closeStream();
                    resolve();
                    return;
                } else if (data && data.type === 'completed') {
                    currentRun.completed = true;
                    currentRun.status = 'Completed.';

                    const shouldCreateActivity = currentRun.phase === 'generation';

                    state.session.locked = false;
                    state.session.phase = 'review';

                    stateManager.setReadOnly(true);
                    closeStream();

                    // Only create the Moodle activity when generation is complete.
                    if (shouldCreateActivity) {
                        (async() => {
                            await this._createActivityFromJob(stateManager);
                            resolve();
                        })();
                        return;
                    }

                    resolve();
                    return;
                } else if (data && data.type === 'failed') {
                    currentRun.error = String(
                        data.message || 'The AI service is currently experiencing high demand. Please try again later.'
                    );
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
                    currentRun.error = 'Disconnected from server.';
                }
                state.session.locked = false;
                state.session.phase = 'review';
                stateManager.setReadOnly(true);
                closeStream();
                resolve();
            };
        });
    }

    /**
     * Create Moodle activity from job id.
     *
     * @param {StateManager} stateManager
     * @returns {Promise<void>}
     */
    async _createActivityFromJob(stateManager) {
        const state = stateManager.state;
        const courseid = Number(state.page.courseid) || 0;
        const jobid = String(state.session.jobid || '');

        if (!courseid || !jobid) {
            return;
        }

        const sectionnum = state.session.sectionnum;
        const beforemod = state.session.beforemod;

        try {
            const result = await repository.createActivity({
                courseid,
                sectionnum,
                jobid,
                beforemod,
            });

            if (!result || !result.ok) {
                notification.alert('', result?.message || 'Error al crear la actividad.', 'close');
                return;
            }

            const activityUrl = result?.data?.activityurl || null;
            if (activityUrl) {
                window.location.href = activityUrl;
            } else {
                window.location.reload();
            }
        } catch (error) {
            notification.exception(error);
        }
    }
}

export const mutations = new Mutations();
