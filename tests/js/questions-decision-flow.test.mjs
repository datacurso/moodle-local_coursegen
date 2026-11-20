// A run that asks for a decision in the middle of its work: through the real controller, with a fake page, a fake
// stream and a fake service. And the way the answer reads in the conversation afterwards.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import RelaySource from './stubs/relay-source.mjs';
import * as ajax from './stubs/core-ajax.mjs';
import {turns} from './stubs/log.mjs';
import {summarize} from '../../amd/src/local/courseai/template/generation_questions.js';
import {byText, findAll, makeDocument, settle} from './fake-dom.mjs';

let doc = null;
let stream = null;

beforeEach(async() => {
    RelaySource.instances.length = 0;
    ajax.reset();
    turns.length = 0;
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
const VIDEO = {
    id: 'video',
    kind: 'single_choice',
    prompt: 'No video of 10 minutes or less was found.',
    hint: 'The ones there last 12 to 41 minutes.',
    scope: {uid: 'u7', name: 'Interactive video · Lesson 7', type: 'h5pactivity'},
    options: [
        {value: 'longer', label: 'Use a longer one', description: 'The 12 minute one.', recommended: true},
        {value: 'skip', label: 'Leave it without video', description: ''},
    ],
    allow_other: true,
    required: true,
};
const TOPICS = {
    id: 'topics',
    kind: 'multi_choice',
    prompt: 'Topics?',
    scope: null,
    options: [{value: 'a', label: 'Anchoring'}, {value: 'b', label: 'Berthing'}],
    allow_other: true,
    required: false,
};
const midRun = (questions) => ({type: 'questions', phase: 'generation', request_id: 'r-1', can_skip: false, questions});

const card = () => doc.elements.cgLog.children[doc.elements.cgLog.children.length - 1];
const answerCalls = () => ajax.calls.filter((call) => call.methodname === 'local_coursegen_answer_template_questions');
const inputs = (type) => findAll(card(), (node) => node.tag === 'input' && node.type === type);
const otherBox = () => findAll(card(), (node) => /cg-question-other-text/.test(node.className || ''))[0];
const send = () => byText(card(), T('send')).find((node) => node.tag === 'button').click();
const choose = (input) => {
    input.checked = true;
    input.dispatch('change');
};
const write = (box, value) => {
    box.value = value;
    box.dispatch('input');
};

const askMidRun = async(questions) => {
    const run = stream.runGenerationStream('/relay', async() => ({course: true}), 7, context);
    await settle();
    RelaySource.instances[0].emit('message', {type: 'template_stage', stage: 'activities'});
    await settle();
    RelaySource.instances[0].emit('message', midRun(questions));
    await settle();
    // Wrapped: returning the promise itself would make `await askMidRun()` wait for the whole run.
    return {run};
};
const finish = async(run) => {
    RelaySource.instances[RelaySource.instances.length - 1].emit('message', {type: 'completed', result: {}});
    return run;
};

test('a decision in the middle of a run is asked in the thread, with the activity it is about', async() => {
    const {run} = await askMidRun([VIDEO]);
    assert.equal(RelaySource.instances[0].closed, true, 'the pass is closed while the person decides');
    assert.ok(byText(card(), T('title_generation')).length);
    assert.ok(byText(card(), 'Interactive video · Lesson 7').length);
    assert.ok(byText(card(), 'The ones there last 12 to 41 minutes.').length);
    run.catch(() => undefined);
});

test('an answer in other words travels as other, and the run goes on from a new stream', async() => {
    const {run} = await askMidRun([VIDEO]);
    choose(inputs('radio')[2]);
    write(otherBox(), 'https://example.com/clip');
    send();
    await settle();

    assert.deepEqual(answerCalls().map((call) => call.args), [{
        sessionid: 7,
        skipped: false,
        answers: [{questionid: 'video', value: JSON.stringify({other: 'https://example.com/clip'})}],
    }]);
    assert.equal(RelaySource.instances.length, 2, 'the run goes on');
    assert.equal(doc.body.classList.contains('cg-generation-paused'), false);
    assert.deepEqual(await finish(run), {course: true});
});

test('several choices with an answer in other words travel as one list', async() => {
    const {run} = await askMidRun([VIDEO, TOPICS]);
    choose(inputs('radio')[0]);
    const [, berthing, other] = inputs('checkbox');
    choose(berthing);
    choose(other);
    write(findAll(card(), (node) => /cg-question-other-text/.test(node.className || ''))[1], 'Towing');
    send();
    await settle();

    assert.deepEqual(answerCalls()[0].args.answers, [
        {questionid: 'video', value: JSON.stringify('longer')},
        {questionid: 'topics', value: JSON.stringify(['b', {other: 'Towing'}])},
    ]);
    await finish(run);
});

test('the conversation keeps the answer in the words of the options, not their values', async() => {
    const {run} = await askMidRun([VIDEO]);
    choose(inputs('radio')[0]);
    send();
    await settle();
    await finish(run);

    const mine = turns.filter((entry) => entry.actor === 'user').map((entry) => entry.message);
    assert.ok(mine.includes(`${VIDEO.prompt} Use a longer one`));
});

test('an answer the service does not take asks the same decision again, and the run waits', async() => {
    let calls = 0;
    ajax.handlers.local_coursegen_answer_template_questions = () => {
        calls++;
        if (calls === 1) {
            throw new Error('Not taken');
        }
        return {settled: false, answered: 1};
    };
    const {run} = await askMidRun([VIDEO]);
    choose(inputs('radio')[1]);
    send();
    await settle();
    assert.equal(RelaySource.instances.length, 1);
    assert.ok(byText(card(), T('title_generation')).length, 'asked again');

    choose(inputs('radio')[1]);
    send();
    await settle();
    assert.equal(RelaySource.instances.length, 2);
    await finish(run);
});

const text = {yes: 'Yes', no: 'No', skipped: 'No answers'};

test('the summary of an answer in other words is the words written', () => {
    const reply = {skipped: false, answers: [{question_id: 'video', value: {other: 'a shorter cut'}}]};
    assert.equal(summarize([VIDEO], reply, text), `${VIDEO.prompt} a shorter cut`);
});

test('the summary of several choices with other words lists the labels and then the words', () => {
    const reply = {skipped: false, answers: [{question_id: 'topics', value: ['a', 'b', {other: 'Towing'}]}]};
    assert.equal(summarize([TOPICS], reply, text), 'Topics? Anchoring, Berthing, Towing');
});

test('the summary of a value no option names shows the value itself', () => {
    const reply = {skipped: false, answers: [{question_id: 'topics', value: ['z']}]};
    assert.equal(summarize([TOPICS], reply, text), 'Topics? z');
});

test('the summary of an answer to a question it does not know is left out', () => {
    const reply = {skipped: false, answers: [{question_id: 'gone', value: 'x'}, {question_id: 'video', value: 'skip'}]};
    assert.equal(summarize([VIDEO], reply, text), `${VIDEO.prompt} Leave it without video`);
});
