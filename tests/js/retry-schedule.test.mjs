import {test} from 'node:test';
import assert from 'node:assert/strict';
import {ATTEMPTS, countdown, delayFor, isRetryable} from '../../amd/src/local/courseai/template/retry_schedule.js';

test('there are at least ten retries and the waits double from ten seconds up to a ceiling', () => {
    assert.ok(ATTEMPTS >= 10);
    assert.deepEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(delayFor), [10, 20, 40, 80, 120, 120, 120, 120, 120, 120]);
});

test('only failures that waiting can mend are retried', () => {
    assert.equal(isRetryable({code: 'connection'}), true);
    assert.equal(isRetryable({errorcode: 'connection'}), true);
    assert.equal(isRetryable({code: 'failed', retryable: true}), true);
    assert.equal(isRetryable({code: 'failed'}), false);
    assert.equal(isRetryable({code: 'invalidlicensekey'}), false);
    assert.equal(isRetryable(null), false);
});

test('the countdown tells each second left, down to zero', async() => {
    const seen = [];
    const outcome = await countdown(3, (left) => seen.push(left), {pause: () => Promise.resolve()});
    assert.deepEqual(seen, [3, 2, 1, 0]);
    assert.equal(outcome, 'elapsed');
});

test('the countdown ends at once when it is asked to', async() => {
    const seen = [];
    let skip = null;
    const early = new Promise((resolve) => {
        skip = resolve;
    });
    const waiting = countdown(10, (left) => {
        seen.push(left);
        if (left === 8) {
            skip();
        }
    }, {skip: early, pause: () => Promise.resolve()});
    assert.equal(await waiting, 'skipped');
    assert.ok(seen.length < 11);
});
