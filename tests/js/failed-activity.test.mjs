// An activity whose generation failed is not an activity that is done: no check, not counted, and the header does not
// move on to saving the course.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {makeDocument} from './fake-dom.mjs';
import {applyEvent} from '../../amd/src/local/courseai/template/generation_events.js';

beforeEach(() => {
    globalThis.document = makeDocument();
});

test('a failed activity is not counted as done and the header does not move on to saving', () => {
    const stages = [];
    const progress = {total: 0, done: 0};
    const paint = (key) => stages.push(key);

    applyEvent({type: 'activity_progress_init', total: 2}, progress, paint);
    applyEvent({type: 'activity_progress_start', uid: 'a', name: 'A'}, progress, paint);
    applyEvent({type: 'activity_progress_start', uid: 'b', name: 'B'}, progress, paint);
    applyEvent({type: 'activity_progress_failed', uid: 'a'}, progress, paint);
    applyEvent({type: 'activity_progress_failed', uid: 'b'}, progress, paint);

    assert.equal(progress.done, 0, 'none of them was written');
    assert.ok(!stages.includes('saving'), 'the course is not being saved: nothing was written');
});

test('an activity that is written is counted, and the header moves on to saving when every one is', () => {
    const stages = [];
    const progress = {total: 0, done: 0};
    const paint = (key) => stages.push(key);

    applyEvent({type: 'activity_progress_init', total: 2}, progress, paint);
    applyEvent({type: 'activity_progress_done', uid: 'a'}, progress, paint);
    assert.equal(progress.done, 1);
    assert.ok(!stages.includes('saving'));
    applyEvent({type: 'activity_progress_done', uid: 'b'}, progress, paint);

    assert.equal(progress.done, 2);
    assert.ok(stages.includes('saving'));
});
