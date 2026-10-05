// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Collects the answer to a checkpointed ask_user pause.
 *
 * @module     local_coursegen/local/courseai/template/generation_question
 * @copyright  2026 Wilber Narvaez
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Selectors from 'local_coursegen/local/courseai/template/selectors';
import {turn} from 'local_coursegen/local/courseai/template/thread';

/**
 * Show one question and wait for a non-empty answer in the existing composer.
 *
 * @param {Object} question
 * @returns {Promise<Object>}
 */
export const askForAnswer = (question) => new Promise((resolve, reject) => {
    const composer = document.querySelector(Selectors.regions.templateComposer);
    const input = document.querySelector(Selectors.regions.toolQuestionInput);
    const send = document.querySelector(Selectors.actions.submitToolAnswer);
    if (!composer || !input || !send || !question.call_id) {
        reject(new Error('The template question controls are unavailable. Reload the page and retry.'));
        return;
    }

    const originalPlaceholder = input.placeholder;
    const originalValue = input.value;
    const composerWasHidden = composer.hidden;
    const sendWasHidden = send.hidden;
    const controller = new AbortController();
    composer.hidden = false;
    send.hidden = false;
    input.placeholder = question.question;
    input.value = '';
    send.disabled = false;
    turn('ai', 'assistant', question.question);
    input.focus();

    const cleanup = () => {
        controller.abort();
        composer.hidden = composerWasHidden;
        send.hidden = sendWasHidden;
        input.placeholder = originalPlaceholder;
        input.value = originalValue;
        send.disabled = true;
    };

    const submit = (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        const answer = input.value.trim();
        if (!answer) {
            input.focus();
            return;
        }
        cleanup();
        resolve({callId: question.call_id, answer});
    };

    send.addEventListener('click', submit, {signal: controller.signal});
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            submit(event);
        }
    }, {signal: controller.signal});
});
