// Stopping and resuming a template generation, through the real controller with a fake page and a fake stream.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import RelaySource from './stubs/relay-source.mjs';
import * as ajax from './stubs/core-ajax.mjs';
import {turns} from './stubs/log.mjs';
import {state as indicator} from './stubs/feedback-progress.mjs';
import {makeDocument, settle} from './fake-dom.mjs';

let doc = null;
let stream = null;

beforeEach(async() => {
    RelaySource.instances.length = 0;
    ajax.reset();
    turns.length = 0;
    indicator.shown = false;
    doc = makeDocument();
    globalThis.document = doc;
    globalThis.window = {requestAnimationFrame: (fn) => fn(), setTimeout, clearTimeout};
    ajax.handlers.local_coursegen_stop_template_generation = () => ({wasrunning: true, stopped: true});
    stream = await import('../../amd/src/local/courseai/template/generation_stream.js');
    // a test that leaves its run waiting (for a retry, for the review) must not hand its control to the next one
    stream.abortGenerationView('');
});

const context = {template: 'T', language: 'es', syllabus: '', instruction: ''};
const stopCalls = () => ajax.calls.filter((call) => call.methodname === 'local_coursegen_stop_template_generation');
const click = (id) => doc.elements[id].click();
const completed = (source) => source.emit('message', {type: 'completed', result: {}});

test('stopping closes the stream, tells the service, and resuming opens it again until the course is built', async() => {
    const built = [];
    const run = stream.runGenerationStream('/relay', async() => {
        built.push(true);
        return {courseurl: '/course'};
    }, 7, context);
    await settle();
    assert.equal(RelaySource.instances.length, 1);
    assert.equal(doc.elements.tplGenStop.hidden, false, 'Stop is offered while the run goes');
    assert.equal(doc.elements.tplRunBar.hidden, false, 'in the bar at the bottom of the column');

    click('tplGenStop');
    await settle();

    assert.equal(RelaySource.instances[0].closed, true, 'the stream is closed at the click');
    assert.deepEqual(stopCalls().map((call) => call.args), [{sessionid: 7}], 'the service is told');
    assert.equal(doc.elements.tplGenStop.hidden, true);
    assert.equal(indicator.shown, false, 'nothing looks busy while it is stopped');
    assert.equal(doc.elements.tplGenResume.hidden, false, 'Resume is offered once the service answered');
    assert.equal(RelaySource.instances.length, 1, 'nothing is opened while it is stopped');

    click('tplGenResume');
    await settle();

    assert.equal(RelaySource.instances.length, 2, 'a new stream, from where the run was');
    assert.equal(doc.elements.tplGenResume.hidden, true);
    assert.equal(doc.elements.tplGenStop.hidden, false);

    completed(RelaySource.instances[1]);
    assert.deepEqual(await run, {courseurl: '/course'});
    assert.equal(built.length, 1);
    assert.equal(doc.elements.tplGenStop.hidden, true, 'no button is left when the run ends');
    assert.equal(doc.elements.tplRunBar.hidden, true, 'and no bar');
});

test('the thread says that it was stopped and that it resumed', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    click('tplGenStop');
    await settle();
    click('tplGenResume');
    await settle();
    completed(RelaySource.instances[1]);
    await run;

    const messages = turns.map((entry) => entry.message);
    assert.ok(messages.some((message) => /log_stopped/.test(message)));
    assert.ok(messages.some((message) => /log_resuming/.test(message)));
});

test('the page stops looking busy at the click, before the service answers', async() => {
    let release = null;
    ajax.handlers.local_coursegen_stop_template_generation = () => new Promise((resolve) => {
        release = () => resolve({wasrunning: true, stopped: true});
    });
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();

    click('tplGenStop');
    await settle();

    assert.equal(doc.elements.tplGenStop.disabled, true, 'a second click is not possible while stopping');
    assert.equal(doc.elements.tplGenResume.hidden, true, 'Resume waits for the confirmation');
    assert.equal(doc.body.classList.contains('cg-generation-paused'), true);

    release();
    await settle();
    assert.equal(doc.elements.tplGenResume.hidden, false);

    click('tplGenResume');
    await settle();
    completed(RelaySource.instances[1]);
    await run;
});

test('clicking Stop twice stops once', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    click('tplGenStop');
    click('tplGenStop');
    await settle();
    assert.equal(stopCalls().length, 1);
    click('tplGenResume');
    await settle();
    completed(RelaySource.instances[1]);
    await run;
});

test('a stop that the service cannot confirm is said, and the run can still go on', async() => {
    ajax.handlers.local_coursegen_stop_template_generation = () => {
        throw new Error('no connection');
    };
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    click('tplGenStop');
    await settle();

    assert.ok(turns.some((entry) => entry.kind === 'danger' && /stop_unconfirmed/.test(entry.message)));
    assert.equal(doc.elements.tplGenResume.hidden, false, 'Resume is there: opening the stream ends any run left');

    click('tplGenResume');
    await settle();
    completed(RelaySource.instances[1]);
    await run;
});

test('a service that says it did not end the run is also said', async() => {
    ajax.handlers.local_coursegen_stop_template_generation = () => ({wasrunning: true, stopped: false});
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    click('tplGenStop');
    await settle();

    assert.ok(turns.some((entry) => /stop_unconfirmed/.test(entry.message)));
    click('tplGenResume');
    await settle();
    completed(RelaySource.instances[1]);
    await run;
});

test('the run can be stopped and resumed more than once', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({done: true}), 7, context);
    await settle();
    for (let round = 1; round <= 3; round++) {
        click('tplGenStop');
        await settle();
        assert.equal(stopCalls().length, round);
        click('tplGenResume');
        await settle();
        assert.equal(RelaySource.instances.length, round + 1);
    }
    completed(RelaySource.instances[3]);
    assert.deepEqual(await run, {done: true});
});

test('a stop ordered before the stream exists never opens it', async() => {
    const begun = stream.beginGenerationView(context);
    await begun;
    click('tplGenStop');
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context, true);
    await settle();

    assert.equal(RelaySource.instances.length, 0, 'the stream was never opened');
    assert.equal(stopCalls().length, 1);
    assert.equal(doc.elements.tplGenResume.hidden, false);

    click('tplGenResume');
    await settle();
    assert.equal(RelaySource.instances.length, 1);
    completed(RelaySource.instances[0]);
    await run;
});

test('a run that fails is offered a retry, not a stop', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    RelaySource.instances[0].fail();
    await settle();
    assert.equal(doc.elements.tplGenStop.hidden, true, 'Stop is not offered while the person decides to retry');
    assert.equal(stopCalls().length, 0);
    run.catch(() => undefined);
});

test('the review of the professor leaves no Stop button', async() => {
    stream.runGenerationStream('/relay', async() => ({}), 7, context).catch(() => undefined);
    await settle();
    RelaySource.instances[0].emit('message', {type: 'review_needed', generated_activities: []});
    await settle();
    assert.equal(doc.elements.tplGenStop.hidden, true);
    assert.equal(doc.elements.tplGenResume.hidden, true);
});

test('the service ending the run on its own order is the same as the click', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    RelaySource.instances[0].emit('message', {type: 'stopped', reason: 'user'});
    await settle();
    assert.equal(doc.elements.tplGenResume.hidden, false);
    click('tplGenResume');
    await settle();
    completed(RelaySource.instances[1]);
    await run;
});
