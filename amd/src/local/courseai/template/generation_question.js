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

/**
 * The question the AI pauses a template generation on: draws the card, collects the answer (a file, a text or
 * an option), sends it and gives the control back when the service has stored it.
 *
 * @module     local_coursegen/local/courseai/template/generation_question
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Templates from 'core/templates';
import YUI from 'core/yui';
import {getString} from 'core/str';
import {initFilepicker} from 'local_coursegen/repository/courseai';
import {showFilePicker} from 'local_coursegen/local/courseai/context/filepicker';
import {answerTemplateQuestion} from 'local_coursegen/local/courseai/template/repository';
import {failureText} from 'local_coursegen/local/courseai/template/failure_text';
import {turn} from 'local_coursegen/local/courseai/template/thread';
import {
    questionActivity,
    questionKind,
    questionOptions,
    questionTitleKey,
} from 'local_coursegen/local/courseai/template/agent_events';
import {allowsNoFile, answerWithoutFile, canSendAnswer} from 'local_coursegen/local/courseai/template/question_answer';

const CARD_TEMPLATE = 'local_coursegen/template_agent_question';
const RETRY_TEMPLATE = 'local_coursegen/template_agent_retry';
const SELECT = {
    log: 'cgLog',
    card: '[data-region="local_coursegen/template-agent/question"]',
    retry: '[data-region="local_coursegen/template-agent/retry"]',
    fileName: '[data-region="file-name"]',
    fileChip: '[data-region="file-chip"]',
    textInput: '[data-region="text-input"]',
    choiceChecked: '[data-region="choice-input"]:checked',
    choiceInputs: '[data-region="choice-input"]',
    error: '[data-region="error"]',
    pick: '[data-action="local_coursegen/template-agent/pick-file"]',
    noFile: '[data-action="local_coursegen/template-agent/no-file"]',
    removeFile: '[data-action="local_coursegen/template-agent/remove-file"]',
    send: '[data-action="local_coursegen/template-agent/send-answer"]',
    retryButton: '[data-action="local_coursegen/template-agent/retry"]',
};

const cardContext = (question, title, about) => {
    const kind = questionKind(question);
    const options = questionOptions(question).map((value, index) => ({value, index}));
    return {
        title,
        about,
        hasabout: about !== '',
        callid: String(question.call_id || ''),
        question: String(question.question || ''),
        askfile: kind === 'file',
        nofile: allowsNoFile(kind),
        asktext: kind === 'text',
        askchoice: kind === 'choice',
        options,
    };
};

/**
 * The line of the card that names the activity the question is about.
 *
 * @param {Object} question The question event, for example {activity_name: "Weekly guide"}.
 * @returns {Promise<string>} The line, or an empty text when the question is about no activity.
 */
const aboutLine = async(question) => {
    const activity = questionActivity(question);
    if (activity === '') {
        return '';
    }
    return getString('template_agent_question_about', 'local_coursegen', activity);
};

const appendCard = async(template, context) => {
    const log = document.getElementById(SELECT.log);
    const {html, js} = await Templates.renderForPromise(template, context);
    Templates.appendNodeContents(log, html, js);
    log.scrollTop = log.scrollHeight;
};

const readAnswer = (card, kind, picked) => {
    if (kind === 'file') {
        return {draftItemId: picked.draftItemId};
    }
    if (kind === 'choice') {
        const checked = card.querySelector(SELECT.choiceChecked);
        if (checked === null) {
            return {choice: ''};
        }
        return {choice: checked.value};
    }
    return {text: card.querySelector(SELECT.textInput).value};
};

const showError = (card, message) => {
    const alert = card.querySelector(SELECT.error);
    alert.textContent = message;
    alert.hidden = false;
};

const clearError = (card) => {
    const alert = card.querySelector(SELECT.error);
    alert.textContent = '';
    alert.hidden = true;
};

// The send button works only while there is an answer to send, so an empty card never reaches the service.
const refreshSend = (card, kind, picked) => {
    const send = card.querySelector(SELECT.send);
    const answer = readAnswer(card, kind, picked);
    send.disabled = !canSendAnswer(kind, answer);
};

const setBusy = (card, busy) => {
    card.querySelectorAll('button, textarea, input').forEach((control) => {
        control.disabled = busy;
    });
};

// After a failed send every control comes back, the send button only if there is still an answer to send.
const restoreControls = (card, kind, picked) => {
    setBusy(card, false);
    refreshSend(card, kind, picked);
};

const summaryOf = async(kind, answer, picked) => {
    if (kind === 'file') {
        return picked.filename;
    }
    if (kind === 'choice') {
        return answer.choice;
    }
    return answer.text;
};

// The chip of the picked file: its name and the way to drop it, hidden while there is no file.
const showPicked = (card, picked) => {
    const chip = card.querySelector(SELECT.fileChip);
    card.querySelector(SELECT.fileName).textContent = picked.filename;
    chip.hidden = picked.draftItemId <= 0;
};

const pickFile = (card, picked, kind) => showFilePicker({
    state: {},
    CourseaiRepository: {initFilepicker},
    Notification,
    YUI,
    texts: {},
    onPicked: (filename, draftItemId) => {
        picked.filename = filename;
        picked.draftItemId = draftItemId;
        clearError(card);
        showPicked(card, picked);
        refreshSend(card, kind, picked);
    },
});

const removeFile = (card, picked, kind) => {
    picked.filename = '';
    picked.draftItemId = 0;
    showPicked(card, picked);
    refreshSend(card, kind, picked);
};

const sendAnswer = async(card, sessionId, question, kind, picked) => {
    const answer = readAnswer(card, kind, picked);
    setBusy(card, true);
    try {
        await answerTemplateQuestion(sessionId, String(question.call_id), kind, answer);
    } catch (exception) {
        restoreControls(card, kind, picked);
        const words = await failureText(exception);
        showError(card, words);
        return false;
    }
    const summary = await summaryOf(kind, answer, picked);
    card.remove();
    turn('user', 'user', summary);
    return true;
};

const sendWithoutFile = async(card, sessionId, question, picked) => {
    const sentence = await getString('template_agent_question_nofile_answer', 'local_coursegen');
    const {kind, answer} = answerWithoutFile(sentence);
    setBusy(card, true);
    try {
        await answerTemplateQuestion(sessionId, String(question.call_id), kind, answer);
    } catch (exception) {
        restoreControls(card, 'file', picked);
        const words = await failureText(exception);
        showError(card, words);
        return false;
    }
    card.remove();
    turn('user', 'user', answer.text);
    return true;
};

// The controls that change the answer: the text box and the options keep the send button in step with them.
const bindAnswerControls = (card, kind, picked) => {
    const textInput = card.querySelector(SELECT.textInput);
    if (textInput) {
        textInput.addEventListener('input', () => {
            clearError(card);
            refreshSend(card, kind, picked);
        });
    }
    card.querySelectorAll(SELECT.choiceInputs).forEach((choice) => {
        choice.addEventListener('change', () => refreshSend(card, kind, picked));
    });
};

const bindFileControls = (card, kind, picked) => {
    const pick = card.querySelector(SELECT.pick);
    if (pick) {
        pick.addEventListener('click', () => pickFile(card, picked, kind));
    }
    const remove = card.querySelector(SELECT.removeFile);
    if (remove) {
        remove.addEventListener('click', () => removeFile(card, picked, kind));
    }
};

const bindCard = (card, sessionId, question, kind, done) => {
    const picked = {filename: '', draftItemId: 0};
    bindFileControls(card, kind, picked);
    bindAnswerControls(card, kind, picked);
    const noFile = card.querySelector(SELECT.noFile);
    if (noFile) {
        noFile.addEventListener('click', async() => {
            if (await sendWithoutFile(card, sessionId, question, picked)) {
                done();
            }
        });
    }
    card.querySelector(SELECT.send).addEventListener('click', async() => {
        if (await sendAnswer(card, sessionId, question, kind, picked)) {
            done();
        }
    });
};

/**
 * Draw the question, wait for the teacher to answer it and send the answer.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @param {Object} question The question event: call_id, question, options and ask_for_file.
 * @returns {Promise<void>} Resolves once the service stored the answer.
 */
export const askQuestion = async(sessionId, question) => {
    const existing = document.querySelector(SELECT.card);
    if (existing) {
        existing.remove();
    }
    const title = await getString(questionTitleKey(question), 'local_coursegen');
    const about = await aboutLine(question);
    await appendCard(CARD_TEMPLATE, cardContext(question, title, about));
    const card = document.querySelector(SELECT.card);
    const kind = questionKind(question);
    await new Promise((resolve) => {
        bindCard(card, sessionId, question, kind, resolve);
    });
};

/**
 * Draw the message of a run that stopped and wait for the teacher to ask for a new attempt.
 *
 * @param {string} message Why the run stopped.
 * @returns {Promise<void>} Resolves when the teacher chooses to try again.
 */
export const askRetry = async(message) => {
    const existing = document.querySelector(SELECT.retry);
    if (existing) {
        existing.remove();
    }
    const fallback = await getString('template_agent_retry_message', 'local_coursegen');
    await appendCard(RETRY_TEMPLATE, {message: message || fallback});
    const card = document.querySelector(SELECT.retry);
    await new Promise((resolve) => {
        card.querySelector(SELECT.retryButton).addEventListener('click', () => {
            card.remove();
            resolve();
        });
    });
};
