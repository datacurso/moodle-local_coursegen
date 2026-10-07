// How the events of a template run draw the rows: totals up front, one close per row, a quiet state for no changes.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {applyEvent, resetSeen} from 'local_coursegen/local/courseai/template/generation_events';
import {calls, reset} from '../../tests/js/stubs/generation-checklist.mjs';

globalThis.document = {querySelector: () => null};

const paint = () => undefined;
const newProgress = () => ({total: 0, done: 0});

beforeEach(() => {
    reset();
    resetSeen();
});

test('the totals announced up front are what the counter starts from', () => {
    const progress = newProgress();
    applyEvent({type: 'activity_progress_init', total: 2, activities: []}, progress, paint);
    assert.equal(progress.total, 2);
    assert.deepEqual(calls[0], ['open', 2]);
});

test('an activity left as it was closes its row as unchanged and still counts', () => {
    const progress = {total: 2, done: 0};
    applyEvent({type: 'activity_progress_failed', aid: 't:11342', reason: 'not_changed'}, progress, paint);
    assert.equal(progress.done, 1);
    assert.deepEqual(calls[0], ['close', '11342', true, 1]);
});

test('a real failure closes its row without calling it unchanged', () => {
    const progress = {total: 2, done: 0};
    applyEvent({type: 'activity_progress_failed', aid: 't:11342', reason: 'failed'}, progress, paint);
    assert.deepEqual(calls[0], ['close', '11342', false, 1]);
});

test('an activity that was written closes its row as done', () => {
    const progress = {total: 1, done: 0};
    applyEvent({type: 'activity_progress_done', aid: 't:11340', status: 'ok'}, progress, paint);
    assert.deepEqual(calls[0], ['close', '11340', false, 1]);
});

test('the end of the run settles any row still spinning', () => {
    const progress = {total: 2, done: 1};
    const outcome = applyEvent({type: 'completed'}, progress, paint);
    assert.equal(outcome, 'completed');
    assert.deepEqual(calls[0], ['settle', 2]);
});
