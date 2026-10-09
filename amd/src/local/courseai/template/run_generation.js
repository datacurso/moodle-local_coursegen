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
 * Runs one course generation from a template, from the click on Generate to the finished course.
 *
 * @module     local_coursegen/local/courseai/template/run_generation
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {startTemplateGeneration} from './repository';
import {runGenerationStream} from './generation_stream';
import {usePreviewSession} from './preview';
import {reviewAndCreate} from './finish';
import {forgetRefusedSyllabus} from './syllabus_refusal';
import {refreshSyllabusChip} from './input_bar';
import {hasMaterial, refreshGenerateButton} from './generate_gate';

/**
 * Generate the course from the picked template, with the input bar's own
 * values (prompt and syllabus) and the files of its spaces, and watch it happen.
 *
 * Nothing runs until the stream is opened: start_template_generation only
 * exports the template, attaches the syllabus and hands back the stream whose
 * consumption drives the run. The professor therefore sees each activity being
 * generated as it happens, instead of a spinner over a job nobody can see.
 *
 * @param {Object} tplState
 * @param {HTMLSelectElement|null} tplSelect
 * @param {HTMLElement} genBtn
 * @param {Object} state Page state.
 * @param {Object} host Holds the page's actions once they exist.
 */
export const runGeneration = async(tplState, tplSelect, genBtn, state, host) => {
    const templateId = parseInt(tplSelect?.value || '0', 10);
    if (!templateId) {
        return;
    }
    // The button doubles as the send button of the review's change request, so
    // a click while a run is on screen belongs to that review, not to a new run.
    if (document.body.classList.contains('cg-generating')) {
        return;
    }
    if (!hasMaterial(tplState)) {
        return;
    }
    genBtn.disabled = true;
    try {
        const started = await startTemplateGeneration(
            templateId,
            tplState.prompt || '',
            parseInt(tplState.syllabusdraftitemid || 0, 10) || 0
        );
        usePreviewSession(started.sessionid);
        await runGenerationStream(
            started.streamurl,
            () => reviewAndCreate(host, state, tplState, started.sessionid),
            started.sessionid,
            {
                prompt: tplState.prompt || '',
                templateName: tplSelect?.options[tplSelect.selectedIndex]?.text || '',
            }
        );
    } catch (e) {
        if (forgetRefusedSyllabus(tplState, e)) {
            refreshSyllabusChip(tplState);
        }
        refreshGenerateButton(tplState, genBtn, false);
        Notification.exception(e);
    }
};
