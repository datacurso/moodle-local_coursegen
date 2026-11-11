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
