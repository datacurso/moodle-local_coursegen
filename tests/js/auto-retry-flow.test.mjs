// A failure of the connection is retried by itself, waiting longer each time, before the person is asked.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import RelaySource from './stubs/relay-source.mjs';
import * as ajax from './stubs/core-ajax.mjs';
import {turns} from './stubs/log.mjs';
import {state as indicator} from './stubs/feedback-progress.mjs';
import {makeDocument, settle} from './fake-dom.mjs';
import {setPauseForTests} from '../../amd/src/local/courseai/template/retry_schedule.js';

let doc = null;
let stream = null;
let pauses = 0;

beforeEach(async() => {
    RelaySource.instances.length = 0;
    ajax.reset();
    turns.length = 0;
    indicator.shown = false;
    pauses = 0;
    setPauseForTests(() => {
        pauses++;
        return Promise.resolve();
    });
    doc = makeDocument();
    globalThis.document = doc;
    globalThis.window = {requestAnimationFrame: (fn) => fn(), setTimeout, clearTimeout};
    ajax.handlers.local_coursegen_retry_template_generation = () => ({ok: true});
    stream = await import('../../amd/src/local/courseai/template/generation_stream.js');
    stream.abortGenerationView('');
});

const context = {template: 'T', language: 'es', syllabus: '', instruction: ''};
const retryCalls = () => ajax.calls.filter((call) => call.methodname === 'local_coursegen_retry_template_generation');
const completed = (source) => source.emit('message', {type: 'completed', result: {}});

test('a lost connection is retried without the person doing anything, and the run goes on', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({courseurl: '/course'}), 7, context);
    await settle();
    RelaySource.instances[0].fail();
    await settle();

    assert.equal(retryCalls().length, 1, 'the run was asked to go on by itself');
    assert.equal(RelaySource.instances.length, 2, 'and the stream was opened again');
    assert.equal(pauses, 10, 'after waiting the first ten seconds');
    assert.equal(doc.elements.cgRetryCard, undefined, 'the person was not asked anything');

    completed(RelaySource.instances[1]);
    assert.deepEqual(await run, {courseurl: '/course'});
});

test('each retry waits twice as long as the one before, up to the ceiling', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    for (let i = 0; i < 4; i++) {
        RelaySource.instances[i].fail();
        await settle();
    }
    assert.equal(RelaySource.instances.length, 5);
    assert.equal(retryCalls().length, 4);
    // 10 + 20 + 40 + 80 seconds of waiting, one pause per second
    assert.equal(pauses, 150);

    completed(RelaySource.instances[4]);
    await run;
});

test('a failure that is not about the connection goes straight to the person', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    RelaySource.instances[0].emit('message', {type: 'failed', message: 'No license', code: 'invalidlicensekey'});
    await settle();

    assert.equal(retryCalls().length, 0, 'nothing was retried');
    assert.equal(RelaySource.instances.length, 1);
    assert.ok(doc.elements.cgLog.children.length > 0 || doc.body.children.length > 0, 'the offer to decide is in the thread');
    // the run waits for the person: nothing more to do in this test, the next one resets it
    run.catch(() => undefined);
});

test('a failure that the service marks as retriable is retried by itself, as one that the relay marks as retryable', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({courseurl: '/course'}), 7, context);
    await settle();
    RelaySource.instances[0].emit('message', {
        type: 'failed', message: 'We could not complete your request right now.', code: 'generation_failed', retriable: true,
    });
    await settle();

    assert.equal(retryCalls().length, 1, 'the run was asked to go on by itself');
    assert.equal(RelaySource.instances.length, 2, 'and the stream was opened again');
    completed(RelaySource.instances[1]);
    assert.deepEqual(await run, {courseurl: '/course'});
});

test('the retries of the service and of the page are one count of ten', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    for (let i = 0; i < 10; i++) {
        const source = RelaySource.instances[i];
        // the service says it made its first retry and that an activity failed, then the run fails again
        source.emit('message', {type: 'template_notice', code: 'retrying', attempt: 1, of: 10, seconds: 2});
        source.emit('message', {type: 'activity_progress_failed', index: 0, uid: 'a', cmid: -1});
        source.emit('message', {type: 'failed', message: 'x', code: 'generation_failed', retriable: true});
        await settle();
    }
    assert.equal(retryCalls().length, 9, 'the retry of the service is one of the ten: nine are left, then the person decides');
    assert.equal(RelaySource.instances.length, 10);
    run.catch(() => undefined);
});

test('when the service used its ten retries the page does not add ten more', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({}), 7, context);
    await settle();
    const source = RelaySource.instances[0];
    source.emit('message', {type: 'template_notice', code: 'retrying', attempt: 10, of: 10, seconds: 120});
    source.emit('message', {type: 'failed', message: 'x', code: 'generation_failed', retriable: true});
    await settle();

    assert.equal(retryCalls().length, 0, 'nothing is retried by the page');
    run.catch(() => undefined);
});

test('a run that writes an activity between two failures starts counting again', async() => {
    const run = stream.runGenerationStream('/relay', async() => ({courseurl: '/course'}), 7, context);
    await settle();
    for (let i = 0; i < 12; i++) {
        const source = RelaySource.instances[i];
        source.emit('message', {type: 'activity_progress_done', index: 0, uid: 'a', cmid: -1});
        source.emit('message', {type: 'failed', message: 'x', code: 'generation_failed', retriable: true});
        await settle();
    }
    assert.equal(retryCalls().length, 12, 'it never runs out while it keeps moving');
    completed(RelaySource.instances[12]);
    assert.deepEqual(await run, {courseurl: '/course'});
});
