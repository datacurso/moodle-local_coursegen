// The institutional guideline of a template run: kept in the state as an id, sent only when one is chosen.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    createTemplateState,
    guidelineIdOf,
    setGuideline,
} from '../../amd/src/local/courseai/template/state.js';
import {buildStartArgs} from '../../amd/src/local/courseai/template/start_args.js';

test('a fresh state has no guideline', () => {
    assert.equal(createTemplateState().guidelineid, 0);
});

test('the guideline is carried over like the other input-bar values', () => {
    assert.equal(createTemplateState({guidelineid: 12}).guidelineid, 12);
});

test('the page ids of the guidelines turn into numeric ids', () => {
    assert.equal(guidelineIdOf('si_12'), 12);
    assert.equal(guidelineIdOf(7), 7);
    assert.equal(guidelineIdOf('7'), 7);
    assert.equal(guidelineIdOf(null), 0);
    assert.equal(guidelineIdOf(''), 0);
    assert.equal(guidelineIdOf('si_x'), 0);
    assert.equal(guidelineIdOf('si_0'), 0);
    assert.equal(guidelineIdOf(-3), 0);
});

test('choosing a guideline replaces the previous one, and none clears it', () => {
    const state = createTemplateState();
    setGuideline(state, 'si_4');
    assert.equal(state.guidelineid, 4);
    setGuideline(state, 'si_9');
    assert.equal(state.guidelineid, 9);
    setGuideline(state, null);
    assert.equal(state.guidelineid, 0);
});

test('the request has no guideline argument when none is chosen', () => {
    const args = buildStartArgs(5, 'adapt', 0, 'es', false, null, 0);
    assert.equal('systeminstructionid' in args, false);
    assert.deepEqual(args, {templateid: 5, prompt: 'adapt', draftitemid: 0, lang: 'es', withimages: false});
});

test('the request carries the id, never a text, when one is chosen', () => {
    const args = buildStartArgs(5, 'adapt', 0, 'es', true, 3, 12);
    assert.equal(args.systeminstructionid, 12);
    assert.equal(args.units, 3);
    assert.deepEqual(Object.keys(args).sort(), [
        'draftitemid', 'lang', 'prompt', 'systeminstructionid', 'templateid', 'units', 'withimages',
    ]);
});

test('the old call without a guideline builds the old request', () => {
    assert.deepEqual(buildStartArgs(5, 'a', 0, '', false), {templateid: 5, prompt: 'a', draftitemid: 0, lang: '', withimages: false});
});
