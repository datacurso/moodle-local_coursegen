// How the events of a template run draw the rows: totals up front, one close per row, a quiet state for no changes.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {applyEvent, resetSeen} from 'local_coursegen/local/courseai/template/generation_events';
import {calls, reset} from '../../tests/js/stubs/generation-checklist.mjs';

const UID_OF_GUIDE = '3f2a9c1e-77b4-4e0a-9d21-5c8f1b2e7a90';
const UID_OF_RESOURCE = '8c1d0b52-0f4e-4d0c-b1f3-2a6e9d7c4b15';
const rows = {
    '[data-generation-cmid="11342"]': {dataset: {generationUid: UID_OF_GUIDE}},
    '[data-generation-cmid="11340"]': {dataset: {generationUid: UID_OF_RESOURCE}},
};
globalThis.document = {querySelector: (selector) => rows[selector] || null, querySelectorAll: () => []};

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
    assert.deepEqual(calls[0], ['close', UID_OF_GUIDE, true, 1]);
});

test('a real failure closes its row without calling it unchanged', () => {
    const progress = {total: 2, done: 0};
    applyEvent({type: 'activity_progress_failed', aid: 't:11342', reason: 'failed'}, progress, paint);
    assert.deepEqual(calls[0], ['close', UID_OF_GUIDE, false, 1]);
});

test('an activity that was written closes its row as done', () => {
    const progress = {total: 1, done: 0};
    applyEvent({type: 'activity_progress_done', aid: 't:11340', status: 'ok'}, progress, paint);
    assert.deepEqual(calls[0], ['close', UID_OF_RESOURCE, false, 1]);
});

test('the end of the run settles any row still spinning', () => {
    const progress = {total: 2, done: 1};
    const outcome = applyEvent({type: 'completed'}, progress, paint);
    assert.equal(outcome, 'completed');
    assert.deepEqual(calls[0], ['settle', 2]);
});

test('a new round after a change request draws the reopened activity again from the start', () => {
    const progress = newProgress();
    applyEvent({type: 'activity_progress_init', total: 1, activities: [{aid: 't:11342'}]}, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    reset();

    applyEvent({type: 'activity_progress_init', total: 1, activities: [{aid: 't:11342'}]}, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);

    assert.deepEqual(calls.map((call) => call[0]), ['open', 'add', 'close']);
    assert.equal(progress.done, 1);
    assert.equal(progress.total, 1);
});

test('a question of a new round is shown even when an earlier round asked one with the same id', () => {
    const progress = newProgress();
    const first = applyEvent({type: 'question', call_id: 'c1x0', question: 'Which file?'}, progress, paint);
    applyEvent({type: 'activity_progress_init', total: 1, activities: []}, progress, paint);
    const second = applyEvent({type: 'question', call_id: 'c1x0', question: 'Which file?'}, progress, paint);
    assert.equal(first, 'question');
    assert.equal(second, 'question');
});

test('a refused step the AI recovers from by itself changes nothing on screen and keeps listening', () => {
    const progress = {total: 2, done: 0};
    const outcome = applyEvent({type: 'tool_result', call_id: 'c1', name: 'modify_activity', ok: false}, progress, paint);
    assert.equal(outcome, '');
    assert.deepEqual(progress, {total: 2, done: 0});
    assert.deepEqual(calls, []);
});

test('a step that worked changes nothing on screen either', () => {
    const progress = {total: 2, done: 0};
    const outcome = applyEvent({type: 'tool_result', call_id: 'c2', name: 'attach_file', ok: true}, progress, paint);
    assert.equal(outcome, '');
    assert.deepEqual(calls, []);
});
