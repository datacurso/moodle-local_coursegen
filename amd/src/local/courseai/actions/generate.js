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
 * handleGenerate helper — initialises a planning session and opens the SSE stream.
 *
 * @module     local_coursegen/local/courseai/actions/generate
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Emit a log entry if emitLog is wired.
 *
 * @param {Object}   params
 * @param {Function} emitLog
 */
const log = (params, emitLog) => {
    if (typeof emitLog === 'function') {
        emitLog(params);
    }
};

/**
 * Validate the prompt, init a planning session, upload syllabus when present,
 * then transition the UI to the planning phase and open the SSE stream.
 *
 * ctx must contain:
 *   state, elements, texts,
 *   CourseaiRepository, stepsUi, planningUi, streamManager, Notification,
 *   renderInitialPromptHistory, emitLog
 *
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const handleGenerate = async(ctx) => {
    const {
        state,
        elements,
        texts,
        CourseaiRepository,
        stepsUi,
        planningUi,
        streamManager,
        Notification,
        renderInitialPromptHistory,
        emitLog,
    } = ctx;

    const {promptInput, btnGenerate} = elements;

    const prompt = promptInput ? promptInput.value.trim() : '';
    if (prompt.length < 10) {
        if (promptInput) {
            promptInput.focus();
        }
        return;
    }

    state.initialPrompt = prompt;
    renderInitialPromptHistory(prompt);

    const truncated = prompt.length > 80 ? prompt.slice(0, 80) + '…' : prompt;
    const userRequestMsg = (texts.courseai_log_user_request || 'You: {$a}').replace('{$a}', truncated);
    log({actor: 'user', kind: 'user', message: userRequestMsg}, emitLog);

    if (btnGenerate) {
        btnGenerate.disabled = true;
        btnGenerate.innerHTML = `
            <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                aria-hidden="true"
                class="spinner"
            >
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
            </svg>
            ${texts.courseai_generate_starting}
        `;
    }

    try {
        let systeminstructionid = 0;
        if (state.selectedGuidelineId) {
            const match = state.selectedGuidelineId.match(/^si_(\d+)$/);
            if (match) {
                systeminstructionid = parseInt(match[1], 10);
            }
        }

        const initResponse = await CourseaiRepository.initSession({
            prompt,
            lang: state.lang,
            withimages: state.withImages,
            systeminstructionid,
        });

        if (!initResponse.success) {
            throw new Error(initResponse.message || texts.courseai_error_init_session);
        }

        const sessionid = initResponse.sessionid;
        state.sessionid = sessionid;
        state.threadid = initResponse.threadid || '';
        state.streamingurl = initResponse.streamingurl || '';

        // Persist the session id in the URL so a page reload rehydrates from the
        // snapshot (resume) instead of starting over — progress is never lost.
        if (sessionid) {
            const sessionUrl = new URL(window.location.href);
            sessionUrl.searchParams.set('sessionid', sessionid);
            window.history.replaceState({}, '', sessionUrl);
        }

        if (state.syllabusFilename && state.draftitemid) {
            if (btnGenerate) {
                btnGenerate.innerHTML = `
                    <svg
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        aria-hidden="true"
                        class="spinner"
                    >
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    </svg>
                    ${texts.courseai_generate_uploading_syllabus}
                `;
            }

            const uploadResponse = await CourseaiRepository.uploadSyllabus(sessionid, state.draftitemid);
            if (!uploadResponse.success) {
                throw new Error(uploadResponse.message || texts.courseai_error_upload_syllabus);
            }
        }

        stepsUi.transitionToPlanning();
        // Sync chips (syllabus, guideline, images, lang) to the compact chat immediately
        // so they are visible from the moment phase 2 streaming begins.
        planningUi.syncCompactChatState();
        streamManager.openSSEStream(state.streamingurl);
    } catch (error) {
        await Notification.exception(error);
        stepsUi.renderGenerateButtonDefault();
    }
};
