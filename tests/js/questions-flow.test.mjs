// The run asks before it starts: through the real controller, with a fake page, a fake stream and a fake service.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import RelaySource from './stubs/relay-source.mjs';
import * as ajax from './stubs/core-ajax.mjs';
import {turns} from './stubs/log.mjs';
import {state as indicator} from './stubs/feedback-progress.mjs';
import {picker} from './stubs/syllabus-picker.mjs';
import {byText, findAll, makeDocument, settle} from './fake-dom.mjs';

let doc = null;
let stream = null;

beforeEach(async() => {
    RelaySource.instances.length = 0;
    ajax.reset();
    turns.length = 0;
    indicator.shown = false;
    picker.next = null;
    picker.asked = 0;
    doc = makeDocument();
    globalThis.document = doc;
    globalThis.window = {requestAnimationFrame: (fn) => fn(), setTimeout, clearTimeout};
    ajax.handlers.local_coursegen_answer_template_questions = () => ({settled: false, answered: 1});
    ajax.handlers.local_coursegen_stop_template_generation = () => ({wasrunning: true, stopped: true});
    stream = await import('../../amd/src/local/courseai/template/generation_stream.js');
    stream.abortGenerationView('');
});

const context = {template: 'T', language: 'es', syllabus: '', instruction: ''};
const T = (key) => `local_coursegen:courseai_template_questions_${key}`;
const QUESTIONS = {
    type: 'questions',
    can_skip: true,
    questions: [
        {id: 'images', kind: 'toggle', prompt: 'Make pictures?', effect: 'enable_images'},
        {id: 'syllabus', kind: 'file', prompt: 'Attach the syllabus', effect: 'attach_file'},
    ],
};

const card = () => doc.elements.cgLog.children[doc.elements.cgLog.children.length - 1];
const answerCalls = () => ajax.calls.filter((call) => call.methodname === 'local_coursegen_answer_template_questions');
const press = (root, text) => byText(root, text).find((node) => node.tag === 'button').click();
const completed = (source) => source.emit('message', {type: 'completed', result: {}});

const start = async(build = async() => ({course: true})) => {
    const run = stream.runGenerationStream('/relay', build, 7, context);
    await settle();
    // wrapped: returning the promise itself would make `await start()` wait for the whole run
    return {run};
};

test('the run stops to ask, and nothing is generated until it is answered', async() => {
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();

    assert.equal(RelaySource.instances[0].closed, true, 'the stream of the pass is closed while asking');
    assert.equal(RelaySource.instances.length, 1, 'no new stream until the person answers');
    assert.ok(byText(card(), 'Make pictures?').length);
    assert.equal(indicator.shown, false, 'nothing looks busy while it waits');
    assert.equal(doc.elements.tplGenStop.hidden, true, 'there is nothing running to stop');
    assert.equal(doc.body.classList.contains('cg-generation-paused'), true);
    run.catch(() => undefined);
});

test('the answers are sent, the run goes on from a new stream, and the course gets built', async() => {
    const built = [];
    const {run} = await start(async() => {
        built.push(true);
        return {course: true};
    });
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();

    press(card(), 'local_coursegen:courseai_template_questions_yes');
    press(card(), T('continue'));
    await settle();

    assert.deepEqual(answerCalls().map((call) => call.args), [
        {sessionid: 7, skipped: false, answers: [{questionid: 'images', value: 'true'}]},
    ]);
    assert.equal(RelaySource.instances.length, 2, 'the run goes on');
    assert.equal(doc.elements.tplGenStop.hidden, false, 'it runs again, so it can be stopped');
    assert.equal(doc.body.classList.contains('cg-generation-paused'), false);
    completed(RelaySource.instances[1]);
    assert.deepEqual(await run, {course: true});
    assert.equal(built.length, 1);
});

test('the conversation keeps what the person answered', async() => {
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();
    press(card(), 'local_coursegen:courseai_template_questions_yes');
    press(card(), T('continue'));
    await settle();
    completed(RelaySource.instances[1]);
    await run;

    const mine = turns.filter((entry) => entry.actor === 'user').map((entry) => entry.message);
    assert.ok(mine.some((message) => message.includes('Make pictures?')));
});

test('going on without answering tells the service so', async() => {
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();
    press(card(), T('skip'));
    await settle();

    assert.deepEqual(answerCalls()[0].args, {sessionid: 7, skipped: true, answers: []});
    assert.equal(RelaySource.instances.length, 2);
    completed(RelaySource.instances[1]);
    await run;
});

test('a file answer travels as the draft item that was picked', async() => {
    picker.next = {filename: 'guide.pdf', draftitemid: 91};
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();

    press(card(), T('attach'));
    await settle();
    press(card(), T('continue'));
    await settle();

    const sent = answerCalls()[0].args.answers;
    assert.deepEqual(sent, [{questionid: 'syllabus', value: JSON.stringify({draftitemid: 91, filename: 'guide.pdf'})}]);
    assert.equal(picker.asked, 1);
    completed(RelaySource.instances[1]);
    await run;
});

test('answers the service does not take are said and asked again, and the run waits', async() => {
    let calls = 0;
    ajax.handlers.local_coursegen_answer_template_questions = () => {
        calls++;
        if (calls === 1) {
            throw new Error('That answer does not fit');
        }
        return {settled: false, answered: 1};
    };
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();

    press(card(), T('continue'));
    await settle();

    assert.ok(turns.some((entry) => entry.kind === 'danger' && entry.message === 'That answer does not fit'));
    assert.equal(RelaySource.instances.length, 1, 'the run did not go on with answers nobody took');
    assert.ok(byText(card(), 'Make pictures?').length, 'the questions are on the page again');

    press(card(), T('continue'));
    await settle();
    assert.equal(calls, 2);
    assert.equal(RelaySource.instances.length, 2);
    completed(RelaySource.instances[1]);
    await run;
});

test('after answering, the run can be stopped and resumed as any other', async() => {
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();
    press(card(), T('skip'));
    await settle();

    doc.elements.tplGenStop.click();
    await settle();
    assert.equal(RelaySource.instances[1].closed, true);
    assert.equal(doc.elements.tplGenResume.hidden, false);

    doc.elements.tplGenResume.click();
    await settle();
    assert.equal(RelaySource.instances.length, 3);
    completed(RelaySource.instances[2]);
    await run;
});

test('a run can ask, then go to the review, and ask nothing more', async() => {
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();
    press(card(), T('skip'));
    await settle();

    RelaySource.instances[1].emit('message', {type: 'review_needed', generated_activities: []});
    await settle();
    assert.equal(doc.elements.tplGenStop.hidden, true, 'the review leaves no Stop button');
    assert.equal(answerCalls().length, 1);
    run.catch(() => undefined);
});

test('a stop ordered while the questions are on the page waits for the answer, not for a stream that is closed', async() => {
    const {run} = await start();
    RelaySource.instances[0].emit('message', QUESTIONS);
    await settle();
    assert.equal(doc.elements.tplGenStop.hidden, true);
    press(card(), T('skip'));
    await settle();
    completed(RelaySource.instances[1]);
    await run;
});

test('a service that asks nothing never shows a card', async() => {
    const {run} = await start();
    completed(RelaySource.instances[0]);
    await run;
    assert.equal(findAll(doc.elements.cgLog, (node) => /cg-question-card/.test(node.className || '')).length, 0);
});
