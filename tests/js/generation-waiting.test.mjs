// The live progress of a template run: queued rows spin from the start, a waiting line counts the seconds of a long
// call, and a question pauses both until the answer arrives.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {applyEvent, resetSeen} from 'local_coursegen/local/courseai/template/generation_events';
import {waitingSeconds} from 'local_coursegen/local/courseai/template/agent_events';
import {reset as resetChecklist} from '../../tests/js/stubs/generation-checklist.mjs';
import {shown, reset as resetShown} from '../../tests/js/stubs/generation-waiting.mjs';

const makeRow = (uid) => {
    const classes = new Set();
    return {
        dataset: {generationUid: uid},
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
        },
    };
};

const GUIDE = makeRow('guide-uid');
const RESOURCE = makeRow('resource-uid');
const rows = {
    '[data-generation-uid="guide-uid"]': GUIDE,
    '[data-generation-uid="resource-uid"]': RESOURCE,
    '[data-generation-cmid="11342"]': GUIDE,
    '[data-generation-cmid="11340"]': RESOURCE,
};
globalThis.document = {querySelector: (selector) => rows[selector] || null};

const paint = () => undefined;
const waiting = (seconds) => ({
    type: 'status',
    message: {string_id: 'agent_waiting', string: 'Waiting for the model', string_args: {seconds, turn: 2}},
});
const INIT = {type: 'activity_progress_init', total: 2, activities: [{aid: 't:11342'}, {aid: 't:11340'}]};

beforeEach(() => {
    resetChecklist();
    resetShown();
    resetSeen();
    ['cg-gen-pending', 'cg-gen-active', 'cg-gen-done'].forEach((name) => {
        GUIDE.classList.remove(name);
        RESOURCE.classList.remove(name);
    });
});

test('the seconds of a waiting status are read as whole seconds', () => {
    assert.equal(waitingSeconds(waiting(35)), 35);
    assert.equal(waitingSeconds(waiting(0)), 0);
    assert.equal(waitingSeconds(waiting('12')), 12);
    assert.equal(waitingSeconds(waiting(7.9)), 7);
});

test('a status that is not the waiting one, or has no usable seconds, is not a waiting line', () => {
    assert.equal(waitingSeconds({type: 'status', message: {string_id: 'other', string_args: {seconds: 4}}}), -1);
    assert.equal(waitingSeconds({type: 'status', message: {string_id: 'agent_waiting', string_args: {seconds: -3}}}), -1);
    assert.equal(waitingSeconds({type: 'status', message: {string_id: 'agent_waiting', string_args: {seconds: 'soon'}}}), -1);
    assert.equal(waitingSeconds({type: 'status', message: {string_id: 'agent_waiting'}}), -1);
    assert.equal(waitingSeconds({type: 'status', message: 'plain text'}), -1);
    assert.equal(waitingSeconds({type: 'status'}), -1);
    assert.equal(waitingSeconds({type: 'token'}), -1);
    assert.equal(waitingSeconds(null), -1);
    assert.equal(waitingSeconds('status'), -1);
});

test('every activity the run announces is queued with its spinner from the first event', () => {
    applyEvent(INIT, {total: 0, done: 0}, paint);
    assert.equal(GUIDE.classList.contains('cg-gen-pending'), true);
    assert.equal(RESOURCE.classList.contains('cg-gen-pending'), true);
});

test('an announced activity with no row on the page is skipped without failing the others', () => {
    const event = {type: 'activity_progress_init', total: 2, activities: [{aid: 't:99999'}, {aid: 't:11342'}]};
    applyEvent(event, {total: 0, done: 0}, paint);
    assert.equal(GUIDE.classList.contains('cg-gen-pending'), true);
    assert.equal(RESOURCE.classList.contains('cg-gen-pending'), false);
});

test('an announcement without a list of activities queues nothing and keeps the total', () => {
    const progress = {total: 0, done: 0};
    applyEvent({type: 'activity_progress_init', total: 3}, progress, paint);
    assert.equal(progress.total, 3);
    assert.equal(GUIDE.classList.contains('cg-gen-pending'), false);
});

test('a round reopened after an adjustment queues a finished row again', () => {
    GUIDE.classList.add('cg-gen-done');
    applyEvent(INIT, {total: 0, done: 0}, paint);
    assert.equal(GUIDE.classList.contains('cg-gen-done'), false);
    assert.equal(GUIDE.classList.contains('cg-gen-pending'), true);
});

test('the row being written is highlighted while the others keep waiting', () => {
    applyEvent(INIT, {total: 0, done: 0}, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, {total: 2, done: 0}, paint);
    assert.equal(GUIDE.classList.contains('cg-gen-active'), true);
    assert.equal(GUIDE.classList.contains('cg-gen-pending'), false);
    assert.equal(RESOURCE.classList.contains('cg-gen-pending'), true);
});

test('a failed row stops spinning and the others keep their spinner', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_failed', aid: 't:11342', reason: 'failed'}, progress, paint);
    assert.equal(GUIDE.classList.contains('cg-gen-done'), true);
    assert.equal(RESOURCE.classList.contains('cg-gen-pending'), true);
});

test('a waiting status shows the seconds and does not count as progress', () => {
    const progress = {total: 2, done: 0};
    const outcome = applyEvent(waiting(35), progress, paint);
    assert.equal(outcome, '');
    assert.deepEqual(shown, [['paused', false], ['wait', 35]]);
    assert.deepEqual(progress, {total: 2, done: 0});
});

test('a hundred waiting ticks only repaint the line, in the order they came', () => {
    for (let second = 0; second < 100; second += 1) {
        applyEvent(waiting(second), {total: 1, done: 0}, paint);
    }
    const waits = shown.filter((call) => call[0] === 'wait');
    assert.equal(waits.length, 100);
    assert.deepEqual(waits[99], ['wait', 99]);
    assert.equal(shown.length, 200);
});

test('a status with no seconds shows nothing', () => {
    applyEvent({type: 'status', message: 'Working'}, {total: 1, done: 0}, paint);
    assert.deepEqual(shown, []);
});

test('the next real event takes the waiting line away', () => {
    applyEvent(waiting(10), {total: 1, done: 0}, paint);
    applyEvent({type: 'tool_call', name: 'get_activity', call_id: 'c1'}, {total: 1, done: 0}, paint);
    assert.deepEqual(shown.map((call) => call[0]), ['paused', 'wait', 'clear', 'paused']);
    assert.deepEqual(shown[3], ['paused', false]);
});

test('a question stops the spinners and the waiting line, and the next event resumes them', () => {
    const progress = {total: 1, done: 0};
    applyEvent(waiting(10), progress, paint);
    const outcome = applyEvent({type: 'question', call_id: 'c2', question: 'Which file?'}, progress, paint);
    assert.equal(outcome, 'question');
    assert.deepEqual(shown.slice(2), [['clear'], ['paused', true]]);
    applyEvent({type: 'tool_result', call_id: 'c2'}, progress, paint);
    assert.deepEqual(shown.slice(4), [['clear'], ['paused', false]]);
});

test('the end of the run clears the waiting line and the paused state', () => {
    applyEvent(waiting(30), {total: 1, done: 0}, paint);
    const outcome = applyEvent({type: 'completed'}, {total: 1, done: 1}, paint);
    assert.equal(outcome, 'completed');
    assert.deepEqual(shown.slice(2), [['clear'], ['paused', false]]);
});

test('a failure of the run clears the waiting line', () => {
    applyEvent(waiting(30), {total: 1, done: 0}, paint);
    const outcome = applyEvent({type: 'failed', retryable: false, message: 'No'}, {total: 1, done: 0}, paint);
    assert.equal(outcome, 'failed');
    assert.deepEqual(shown.slice(2), [['clear'], ['paused', false]]);
});

test('a reloaded page repaints the queue from the replay and then follows the live ticks', () => {
    const progress = {total: 0, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11340', modname: 'resource', title: 'File'}, progress, paint);
    applyEvent(waiting(8), progress, paint);
    assert.equal(GUIDE.classList.contains('cg-gen-done'), true);
    assert.equal(RESOURCE.classList.contains('cg-gen-active'), true);
    assert.deepEqual(shown[shown.length - 1], ['wait', 8]);
});

test('a waiting tick after the answer of the professor turns the spinners again before any other event', () => {
    const progress = {total: 1, done: 0};
    applyEvent({type: 'question', call_id: 'c3', question: 'Which file?'}, progress, paint);
    applyEvent(waiting(8), progress, paint);
    assert.deepEqual(shown.slice(-2), [['paused', false], ['wait', 8]]);
});
