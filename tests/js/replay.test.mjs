import assert from 'node:assert/strict';
import test from 'node:test';
import {replayInto} from '../../amd/src/local/courseai/stream/replay.js';
import {eventsToReplay} from '../../amd/src/courseai/bootstrap/resume-replay.js';

test('events are routed in order, ahead of what is queued later', async() => {
    const seen = [];
    let queue = Promise.resolve();
    queue = replayInto(queue, [{n: 1}, {n: 2}, {n: 3}], async(d) => {
        await new Promise((r) => setTimeout(r, 10 - d.n * 3));
        seen.push(d.n);
    });
    queue = queue.then(() => seen.push('live'));
    await queue;
    assert.deepEqual(seen, [1, 2, 3, 'live']);
});

test('a failing handler does not stop the next ones', async() => {
    const seen = [];
    const queue = replayInto(Promise.resolve(), [{n: 1}, {n: 2}, {n: 3}], (d) => {
        if (d.n === 2) {
            throw new Error('boom');
        }
        seen.push(d.n);
    });
    await queue;
    assert.deepEqual(seen, [1, 3]);
});

test('nothing to replay returns the same queue', async() => {
    const queue = Promise.resolve();
    assert.equal(replayInto(queue, [], () => {}), queue);
    assert.equal(replayInto(queue, undefined, () => {}), queue);
    assert.equal(replayInto(queue, null, () => {}), queue);
});

test('only running statuses replay events', () => {
    const snapshot = {progress_events: [{type: 'status'}]};
    ['PENDING', 'PLANNING', 'PLANNING_ADJUST', 'PLANNING_ACCEPT', 'GENERATING'].forEach((status) => {
        assert.deepEqual(eventsToReplay(status, snapshot), [{type: 'status'}]);
    });
    ['WAITING_APPROVAL', 'COMPLETED', 'FAILED', 'WAITING_SUBSECTIONS_DECISION', ''].forEach((status) => {
        assert.deepEqual(eventsToReplay(status, snapshot), []);
    });
});

test('a snapshot without usable events replays nothing', () => {
    assert.deepEqual(eventsToReplay('PLANNING', {}), []);
    assert.deepEqual(eventsToReplay('PLANNING', null), []);
    assert.deepEqual(eventsToReplay('PLANNING', {progress_events: 'oops'}), []);
    assert.deepEqual(eventsToReplay('PLANNING', {progress_events: null}), []);
});
