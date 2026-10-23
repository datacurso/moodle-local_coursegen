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
import {loadActivityaiStrings} from 'local_coursegen/local/activityai/i18n';
import {closeStream, connectStream} from 'local_coursegen/local/activityai/stream-session';
import {createActivityFromJob} from 'local_coursegen/local/activityai/activity-creation';

let uiTexts = {};

const ensureUiTexts = async() => {
    if (!Object.keys(uiTexts).length) {
        uiTexts = await loadActivityaiStrings();
    }

    return uiTexts;
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
        if (!stateManager.state.session.jobid) {
            // A new conversation starts from the chooser of types.
            stateManager.state.session.activitytype = '';
            stateManager.state.session.h5ptype = '';
            stateManager.state.session.typestep = 'type';
        }
        stateManager.setReadOnly(true);
    }

    /**
     * The teacher picked a type of activity from the list.
     *
     * H5P asks for its kind next; any other type goes straight to the chat.
     *
     * @param {StateManager} stateManager
     * @param {{modname: string}} payload
     */
    chooseActivityType(stateManager, payload) {
        const modname = String(payload.modname || '');
        const offered = stateManager.state.page.activitytypes.some((type) => type.modname === modname);
        if (!modname || !offered || stateManager.state.session.jobid) {
            return;
        }

        stateManager.setReadOnly(false);
        stateManager.state.session.activitytype = modname;
        stateManager.state.session.h5ptype = '';
        stateManager.state.session.typestep = modname === 'h5pactivity' ? 'h5p' : 'prompt';
        stateManager.setReadOnly(true);
    }

    /**
     * The teacher picked the kind of H5P.
     *
     * @param {StateManager} stateManager
     * @param {{type: string}} payload
     */
    chooseH5pType(stateManager, payload) {
        const type = String(payload.type || '');
        const known = stateManager.state.page.h5ptypes.some((kind) => kind.type === type);
        if (!known || stateManager.state.session.jobid) {
            return;
        }

        stateManager.setReadOnly(false);
        stateManager.state.session.h5ptype = type;
        stateManager.state.session.typestep = 'prompt';
        stateManager.setReadOnly(true);
    }

    /**
     * One step back, while the conversation has not started: from the chat to the kinds of H5P, or to the types.
     *
     * @param {StateManager} stateManager
     */
    goBack(stateManager) {
        const session = stateManager.state.session;
        if (session.jobid) {
            return;
        }

        stateManager.setReadOnly(false);
        if (session.typestep === 'prompt' && session.activitytype === 'h5pactivity') {
            session.h5ptype = '';
            session.typestep = 'h5p';
        } else {
            session.activitytype = '';
            session.h5ptype = '';
            session.typestep = 'type';
        }
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
     * Update selected language option.
     *
     * @param {StateManager} stateManager
     * @param {string} value
     */
    setLang(stateManager, value) {
        const lang = String(value || '').trim().toLowerCase();
        if (!lang) {
            return;
        }

        stateManager.setReadOnly(false);
        stateManager.state.session.lang = lang;
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
     * Open the session of the Course Presentation editor: the job is started and the document and the pictures the
     * teacher chose are sent, but nothing is generated yet; the editor asks for the draft once the job exists.
     *
     * @param {StateManager} stateManager
     * @param {{prompt: string, slideImages: (Array<{slide: number, draftitemid: number}>|undefined)}} payload
     */
    async beginPresentation(stateManager, payload) {
        await ensureUiTexts();

        const state = stateManager.state;
        const courseid = Number(state.page.courseid) || 0;
        stateManager.setReadOnly(false);
        state.session.locked = true;
        state.session.phase = 'editing_starting';
        stateManager.setReadOnly(true);

        try {
            const response = await repository.startSession({
                courseid,
                sectionnum: state.session.sectionnum,
                beforemod: state.session.beforemod,
                prompt: String(payload.prompt || ''),
                generateimages: state.session.generateimages,
                lang: String(state.session.lang || state.page.defaultlang || 'en'),
                modname: String(state.session.activitytype || ''),
                h5ptype: String(state.session.h5ptype || ''),
            });
            const jobid = response && response.job_id ? String(response.job_id) : '';
            if (!jobid) {
                throw new Error(response && response.message ? response.message : 'Missing session response');
            }

            if (state.upload.draftitemid) {
                await repository.uploadFile({courseid, jobid, draftitemid: state.upload.draftitemid});
            }
            for (const image of payload.slideImages || []) {
                await repository.uploadSlideImage({courseid, jobid, draftitemid: image.draftitemid, slide: image.slide});
            }

            stateManager.setReadOnly(false);
            state.session.jobid = jobid;
            state.session.locked = false;
            state.session.phase = 'editing';
            stateManager.setReadOnly(true);
        } catch (error) {
            stateManager.setReadOnly(false);
            state.session.locked = false;
            state.session.phase = 'idle';
            stateManager.setReadOnly(true);
            throw error;
        }
    }

    /**
     * Submit the initial prompt (creates session) OR a feedback prompt (adjust).
     *
     * @param {StateManager} stateManager
     * @param {{prompt: string, slideImages: (Array<{slide: number, draftitemid: number}>|undefined)}} payload
     */
    async submitPrompt(stateManager, payload) {
        await ensureUiTexts();

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
            errorCode: '',
            retriable: false,
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
                    lang: String(state.session.lang || state.page.defaultlang || 'en'),
                    modname: String(state.session.activitytype || ''),
                    h5ptype: String(state.session.h5ptype || ''),
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

                // The pictures the teacher chose for the slides go to the service before the run starts, one by one.
                for (const image of payload.slideImages || []) {
                    await repository.uploadSlideImage({
                        courseid,
                        jobid,
                        draftitemid: image.draftitemid,
                        slide: image.slide,
                    });
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
                run.error = error && error.message ? String(error.message) : uiTexts.activityai_error_unknown;
                run.errorCode = 'stream_error';
                run.retriable = true;
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
        await ensureUiTexts();

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
            status: uiTexts.activityai_status_plan_accepted_generating,
            reviewneeded: false,
            completed: false,
            error: '',
            errorCode: '',
            retriable: false,
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
     * Connect to the generation stream and update state for the provided run.
     *
     * This mutation will keep pending until the stream ends.
     *
     * @param {StateManager} stateManager
     * @param {{runid: number}} payload
     * @returns {Promise<void>}
     */
    async connectStream(stateManager, payload) {
        await ensureUiTexts();

        const runid = Number(payload.runid) || 0;
        await connectStream(stateManager, runid, {
            uiTexts,
            createActivity: createActivityFromJob,
        });
    }

    /**
     * Retry a failed run when backend marks it retriable.
     *
     * @param {StateManager} stateManager
     * @param {{runid: number}} payload
     * @returns {Promise<void>}
     */
    async retryRun(stateManager, payload) {
        await ensureUiTexts();

        const runid = Number(payload?.runid) || 0;
        const state = stateManager.state;
        const run = state.runs.get(runid);
        if (!run || !run.retriable) {
            return;
        }

        stateManager.setReadOnly(false);
        run.error = '';
        run.errorCode = '';
        run.retriable = false;
        stateManager.setReadOnly(true);

        if (run.phase === 'planning') {
            // Init failures happen before a session exists (startSession threw, so there is
            // no jobid/stream to reconnect to): replay the original prompt from scratch.
            if (!state.session.jobid) {
                await this.submitPrompt(stateManager, {prompt: run.prompt || ''});
                return;
            }

            const retryRunId = Date.now();
            stateManager.setReadOnly(false);
            state.session.locked = true;
            state.session.phase = 'planning';
            state.runs.set(retryRunId, {
                id: retryRunId,
                phase: 'planning',
                prompt: run.prompt || '',
                markdown: '',
                status: uiTexts.activityai_status_retrying,
                reviewneeded: false,
                completed: false,
                error: '',
                errorCode: '',
                retriable: false,
            });
            stateManager.setReadOnly(true);

            await this.connectStream(stateManager, {runid: retryRunId});
            return;
        }

        if (run.phase === 'generation') {
            // The plan is already approved: reconnecting to the stream makes the backend
            // retry the generation on the failed thread. Re-sending an "accept" feedback
            // here would be a no-op (the approval interrupt was already consumed).
            const retryRunId = Date.now();
            stateManager.setReadOnly(false);
            state.session.locked = true;
            state.session.phase = 'generation';
            state.runs.set(retryRunId, {
                id: retryRunId,
                phase: 'generation',
                prompt: run.prompt || '',
                markdown: '',
                status: uiTexts.activityai_status_retrying,
                reviewneeded: false,
                completed: false,
                error: '',
                errorCode: '',
                retriable: false,
            });
            stateManager.setReadOnly(true);

            await this.connectStream(stateManager, {runid: retryRunId});
            return;
        }

        await this.submitPrompt(stateManager, {prompt: run.prompt || ''});
    }
}

export const mutations = new Mutations();
