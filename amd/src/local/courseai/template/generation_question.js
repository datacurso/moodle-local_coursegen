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
import {turn} from 'local_coursegen/local/courseai/template/thread';
import {questionKind, questionOptions} from 'local_coursegen/local/courseai/template/agent_events';
import {allowsNoFile, answerWithoutFile} from 'local_coursegen/local/courseai/template/question_answer';

const CARD_TEMPLATE = 'local_coursegen/template_agent_question';
const RETRY_TEMPLATE = 'local_coursegen/template_agent_retry';
const SELECT = {
    log: 'cgLog',
    card: '[data-region="local_coursegen/template-agent/question"]',
    retry: '[data-region="local_coursegen/template-agent/retry"]',
    fileName: '[data-region="file-name"]',
    textInput: '[data-region="text-input"]',
    choiceChecked: '[data-region="choice-input"]:checked',
    error: '[data-region="error"]',
    pick: '[data-action="local_coursegen/template-agent/pick-file"]',
    noFile: '[data-action="local_coursegen/template-agent/no-file"]',
    send: '[data-action="local_coursegen/template-agent/send-answer"]',
    retryButton: '[data-action="local_coursegen/template-agent/retry"]',
};

const cardContext = (question) => {
    const kind = questionKind(question);
    const options = questionOptions(question).map((value, index) => ({value, index}));
    return {
        callid: String(question.call_id || ''),
        question: String(question.question || ''),
        askfile: kind === 'file',
        nofile: allowsNoFile(kind),
        asktext: kind === 'text',
        askchoice: kind === 'choice',
        options,
    };
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

const setBusy = (card, busy) => {
    card.querySelectorAll('button, textarea, input').forEach((control) => {
        control.disabled = busy;
    });
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

const pickFile = (card, picked) => showFilePicker({
    state: {},
    CourseaiRepository: {initFilepicker},
    Notification,
    YUI,
    texts: {},
    onPicked: (filename, draftItemId) => {
        picked.filename = filename;
        picked.draftItemId = draftItemId;
        card.querySelector(SELECT.fileName).textContent = filename;
    },
});

const sendAnswer = async(card, sessionId, question, kind, picked) => {
    const answer = readAnswer(card, kind, picked);
    setBusy(card, true);
    try {
        await answerTemplateQuestion(sessionId, String(question.call_id), kind, answer);
    } catch (exception) {
        setBusy(card, false);
        showError(card, exception.message || String(exception));
        return false;
    }
    const summary = await summaryOf(kind, answer, picked);
    card.remove();
    turn('user', 'user', summary);
    return true;
};

const sendWithoutFile = async(card, sessionId, question) => {
    const sentence = await getString('template_agent_question_nofile_answer', 'local_coursegen');
    const {kind, answer} = answerWithoutFile(sentence);
    setBusy(card, true);
    try {
        await answerTemplateQuestion(sessionId, String(question.call_id), kind, answer);
    } catch (exception) {
        setBusy(card, false);
        showError(card, exception.message || String(exception));
        return false;
    }
    card.remove();
    turn('user', 'user', answer.text);
    return true;
};

const bindCard = (card, sessionId, question, kind, done) => {
    const picked = {filename: '', draftItemId: 0};
    const pick = card.querySelector(SELECT.pick);
    if (pick) {
        pick.addEventListener('click', () => pickFile(card, picked));
    }
    const noFile = card.querySelector(SELECT.noFile);
    if (noFile) {
        noFile.addEventListener('click', async() => {
            if (await sendWithoutFile(card, sessionId, question)) {
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
    await appendCard(CARD_TEMPLATE, cardContext(question));
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
