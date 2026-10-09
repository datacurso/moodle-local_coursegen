// What the rows of the activities look like when a run ends, as in free mode: a row that was written keeps its check, any other row keeps no spinner.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {applyEvent, resetSeen, settleRows} from 'local_coursegen/local/courseai/template/generation_events';
import {reset} from '../../tests/js/stubs/generation-checklist.mjs';

const makeRow = (uid, cmid) => {
    const classes = new Set();
    return {
        dataset: {generationUid: uid, generationCmid: cmid},
        classes,
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
        },
    };
};

const GUIDE = makeRow('guide-uid', '11342');
const RESOURCE = makeRow('resource-uid', '11340');
const ALL_ROWS = [GUIDE, RESOURCE];
const lookup = {
    '[data-generation-uid="guide-uid"]': GUIDE,
    '[data-generation-uid="resource-uid"]': RESOURCE,
    '[data-generation-cmid="11342"]': GUIDE,
    '[data-generation-cmid="11340"]': RESOURCE,
};
let page = {};

const paint = () => undefined;
const INIT = {type: 'activity_progress_init', total: 2, activities: [{aid: 't:11342'}, {aid: 't:11340'}]};

const stateOf = (row) => [...row.classes];

beforeEach(() => {
    reset();
    resetSeen();
    GUIDE.classes.clear();
    RESOURCE.classes.clear();
    page = {
        querySelector: (selector) => lookup[selector] || null,
        querySelectorAll: (selector) => {
            if (selector === '[data-generation-uid]') {
                return ALL_ROWS;
            }
            return [];
        },
    };
    globalThis.document = page;
});

test('settling the rows keeps the check of a row that was written and clears every other status', () => {
    GUIDE.classes.add('cg-gen-pending');
    RESOURCE.classes.add('cg-gen-done');
    settleRows();
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-done']);
});

test('settling the rows clears the active and the skipped status', () => {
    GUIDE.classes.add('cg-gen-active');
    RESOURCE.classes.add('cg-gen-skipped');
    settleRows();
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('settling the rows keeps the classes that are not status ones', () => {
    GUIDE.classes.add('cg-gen-active');
    GUIDE.classes.add('activity');
    RESOURCE.classes.add('cg-gen-done');
    RESOURCE.classes.add('activity');
    settleRows();
    assert.deepEqual(stateOf(GUIDE), ['activity']);
    assert.deepEqual(stateOf(RESOURCE).sort(), ['activity', 'cg-gen-done']);
});

test('settling a page with no generated rows changes nothing and does not fail', () => {
    page.querySelectorAll = () => [];
    assert.doesNotThrow(() => settleRows());
});

test('settling twice leaves the same rows', () => {
    RESOURCE.classes.add('cg-gen-done');
    settleRows();
    settleRows();
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-done']);
});

test('a row written during the run ends with its check and a row never reached ends clear', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-done']);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-pending']);
    const outcome = applyEvent({type: 'completed'}, progress, paint);
    assert.equal(outcome, 'completed');
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-done']);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('rows announced and never closed end clear', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('a failed row stops spinning at once and ends clear, with no check', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_failed', aid: 't:11340', reason: 'failed'}, progress, paint);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-skipped']);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('an activity the run left as it was ends clear, with no check', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_failed', aid: 't:11342', reason: 'not_changed'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-skipped']);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), []);
});

test('replaying the events of a finished run on a reloaded page shows a check on every written row and no spinner', () => {
    const progress = {total: 0, done: 0};
    GUIDE.classes.add('cg-gen-pending');
    RESOURCE.classes.add('cg-gen-pending');
    const events = [
        INIT,
        {type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'},
        {type: 'activity_progress_done', aid: 't:11342', status: 'ok'},
        {type: 'activity_progress_start', aid: 't:11340', modname: 'resource', title: 'File'},
        {type: 'activity_progress_done', aid: 't:11340', status: 'ok'},
        {type: 'completed'},
    ];
    for (const event of events) {
        applyEvent(event, progress, paint);
    }
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-done']);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-done']);
});

test('while the run is still going the rows keep their spinners', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-active']);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-pending']);
});

test('a row never shows a spinner and a check together', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    const classes = stateOf(GUIDE);
    assert.equal(classes.length, 1);
    assert.equal(classes[0], 'cg-gen-done');
});

test('a change request spins only the rows that are redone, and they end with a check again', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11340', modname: 'resource', title: 'File'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11340', status: 'ok'}, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    const round = {type: 'activity_progress_init', total: 1, activities: [{aid: 't:11342'}]};
    applyEvent(round, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-pending']);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-done']);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-done']);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-done']);
});

test('a question keeps the rows as they are: the page hides their spinners while the run waits', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    const outcome = applyEvent({type: 'question', call_id: 'q1', question: 'Which file?'}, progress, paint);
    assert.equal(outcome, 'question');
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-pending']);
});

test('the free-mode tracker and the template flow share the done class', async() => {
    const tracker = await import('local_coursegen/local/courseai/stream/tracker-renderer');
    const row = makeRow('x', '1');
    const root = {querySelector: () => row};
    globalThis.document = {getElementById: () => root};
    tracker.renderGenerationTracker({generationTracker: {flat: [{id: 'a', status: 'done'}]}});
    assert.deepEqual(stateOf(row), ['cg-gen-done']);
});
