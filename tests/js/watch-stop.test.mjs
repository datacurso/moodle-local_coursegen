import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';
import RelaySource from './stubs/relay-source.mjs';
import {watchOnce} from '../../amd/src/local/courseai/template/generation_watch.js';

beforeEach(() => {
    RelaySource.instances.length = 0;
});

const apply = () => undefined;

test('closing the pass settles it as stopped and closes the stream', async() => {
    let close = null;
    const pass = watchOnce('/stream', {}, apply, () => undefined, (fn) => {
        close = fn;
    });
    close();
    assert.deepEqual(await pass, {outcome: 'stopped', data: null});
    assert.equal(RelaySource.instances[0].closed, true);
});

test('the stopped event of the service ends the pass as stopped', async() => {
    const pass = watchOnce('/stream', {}, apply, () => undefined);
    RelaySource.instances[0].emit('message', {type: 'stopped', reason: 'user'});
    const result = await pass;
    assert.equal(result.outcome, 'stopped');
    assert.equal(RelaySource.instances[0].closed, true);
});

test('the done event that follows a stop is not reported as a failure', async() => {
    const pass = watchOnce('/stream', {}, apply, () => undefined);
    const source = RelaySource.instances[0];
    source.emit('message', {type: 'stopped'});
    source.emit('done');
    assert.equal((await pass).outcome, 'stopped');
});

test('a connection error after closing the pass is not a failure', async() => {
    let close = null;
    const pass = watchOnce('/stream', {}, apply, () => undefined, (fn) => {
        close = fn;
    });
    close();
    RelaySource.instances[0].fail();
    assert.equal((await pass).outcome, 'stopped');
});

test('closing twice settles once', async() => {
    let close = null;
    let failures = 0;
    const pass = watchOnce('/stream', {}, apply, () => {
        failures++;
    }, (fn) => {
        close = fn;
    });
    close();
    close();
    assert.equal((await pass).outcome, 'stopped');
    assert.equal(failures, 0);
});

test('closing a pass that already completed changes nothing', async() => {
    let close = null;
    const pass = watchOnce('/stream', {}, () => 'completed', () => undefined, (fn) => {
        close = fn;
    });
    RelaySource.instances[0].emit('message', {type: 'anything'});
    const first = await pass;
    close();
    assert.equal(first.outcome, 'completed');
});

test('a pass that already failed is not turned into a stop', async() => {
    let close = null;
    const pass = watchOnce('/stream', {}, apply, () => undefined, (fn) => {
        close = fn;
    });
    RelaySource.instances[0].fail();
    await assert.rejects(pass);
    close();
});

test('a stream that fails on its own still rejects as before', async() => {
    const pass = watchOnce('/stream', {}, apply, () => undefined);
    RelaySource.instances[0].fail();
    await assert.rejects(pass, /connection to the generation was lost/);
});

test('each pass has its own stream and its own close', async() => {
    let first = null;
    let second = null;
    const one = watchOnce('/stream', {}, apply, () => undefined, (fn) => {
        first = fn;
    });
    first();
    await one;
    const two = watchOnce('/stream', {}, apply, () => undefined, (fn) => {
        second = fn;
    });
    assert.equal(RelaySource.instances.length, 2);
    assert.equal(RelaySource.instances[1].closed, false);
    second();
    assert.equal((await two).outcome, 'stopped');
});

test('the review and completed outcomes still end the pass as they did', async() => {
    const review = watchOnce('/stream', {}, () => 'review', () => undefined);
    RelaySource.instances[0].emit('message', {type: 'review_needed'});
    assert.equal((await review).outcome, 'review');
});
