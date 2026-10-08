// What the rows of the activities look like when a run ends: no row keeps a spinner or a badge, as in free mode.
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

test('settling the rows clears every status class of every row', () => {
    GUIDE.classes.add('cg-gen-pending');
    RESOURCE.classes.add('cg-gen-done');
    settleRows();
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('settling the rows keeps the classes that are not status ones', () => {
    GUIDE.classes.add('cg-gen-active');
    GUIDE.classes.add('activity');
    settleRows();
    assert.deepEqual(stateOf(GUIDE), ['activity']);
});

test('settling a page with no generated rows changes nothing and does not fail', () => {
    page.querySelectorAll = () => [];
    assert.doesNotThrow(() => settleRows());
});

test('the end of a run leaves no row spinning, whether it was written or never reached', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-pending']);
    const outcome = applyEvent({type: 'completed'}, progress, paint);
    assert.equal(outcome, 'completed');
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('rows announced and never closed are settled at the end of the run', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('a failed row does not keep spinning once the run completes', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_failed', aid: 't:11340', reason: 'failed'}, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('replaying the events of a finished run on a reloaded page shows no spinner', () => {
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
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('while the run is still going the rows keep their spinners', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-active']);
    assert.deepEqual(stateOf(RESOURCE), ['cg-gen-pending']);
});

test('a change request spins only the rows that are redone and clears them at the end', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    const round = {type: 'activity_progress_init', total: 1, activities: [{aid: 't:11342'}]};
    applyEvent(round, progress, paint);
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-pending']);
    assert.deepEqual(stateOf(RESOURCE), []);
    applyEvent({type: 'activity_progress_start', aid: 't:11342', modname: 'page', title: 'Guide'}, progress, paint);
    applyEvent({type: 'activity_progress_done', aid: 't:11342', status: 'ok'}, progress, paint);
    applyEvent({type: 'completed'}, progress, paint);
    assert.deepEqual(stateOf(GUIDE), []);
    assert.deepEqual(stateOf(RESOURCE), []);
});

test('a question keeps the rows as they are: the page hides their spinners while the run waits', () => {
    const progress = {total: 2, done: 0};
    applyEvent(INIT, progress, paint);
    const outcome = applyEvent({type: 'question', call_id: 'q1', question: 'Which file?'}, progress, paint);
    assert.equal(outcome, 'question');
    assert.deepEqual(stateOf(GUIDE), ['cg-gen-pending']);
});
