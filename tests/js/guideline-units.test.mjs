// The units of a chosen guideline prefill the units field, until the professor edits it by hand.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    applyStructureResponse,
    createTemplateState,
    guidelineUnitsOf,
    requestedUnits,
    setGuideline,
    setUnits,
} from '../../amd/src/local/courseai/template/state.js';

const loaded = (extra = {}) => {
    const state = createTemplateState();
    applyStructureResponse(state, {sections: [], unitcount: 3, maxunits: 50, ...extra});
    return state;
};

test('the units of a guideline come from its list item, 0 when it has none', () => {
    assert.equal(guidelineUnitsOf({units: 8}), 8);
    assert.equal(guidelineUnitsOf({units: '8'}), 8);
    assert.equal(guidelineUnitsOf({units: null}), 0);
    assert.equal(guidelineUnitsOf({}), 0);
    assert.equal(guidelineUnitsOf(undefined), 0);
    assert.equal(guidelineUnitsOf({units: 0}), 0);
    assert.equal(guidelineUnitsOf({units: -2}), 0);
    assert.equal(guidelineUnitsOf({units: 'abc'}), 0);
});

test('choosing a guideline with units prefills the field', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 8);
    assert.equal(state.guidelineid, 4);
    assert.equal(state.units, 8);
    assert.equal(requestedUnits(state), 8);
});

test('a guideline without units leaves the template count', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 0);
    assert.equal(state.units, 3);
    assert.equal(requestedUnits(state), null);
});

test('removing the guideline restores the template count', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 8);
    setGuideline(state, null);
    assert.equal(state.guidelineid, 0);
    assert.equal(state.units, 3);
    assert.equal(requestedUnits(state), null);
});

test('replacing the guideline follows the new one', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 8);
    setGuideline(state, 'si_5', 0);
    assert.equal(state.units, 3);
    setGuideline(state, 'si_6', 5);
    assert.equal(state.units, 5);
});

test('a value typed by hand is not overwritten by choosing or removing a guideline', () => {
    const state = loaded();
    setUnits(state, '6');
    setGuideline(state, 'si_4', 8);
    assert.equal(state.units, 6);
    setGuideline(state, null);
    assert.equal(state.units, 6);
});

test('the template count typed on purpose is sent when a guideline has other units', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 8);
    setUnits(state, '3');
    assert.equal(state.units, 3);
    // The server would otherwise fall back to the units of the guideline.
    assert.equal(requestedUnits(state), 3);
});

test('emptying the field hands it back to the guideline', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 8);
    setUnits(state, '6');
    setUnits(state, '');
    assert.equal(state.units, 8);
    setGuideline(state, null);
    assert.equal(state.units, 3);
});

test('a guideline value above the maximum is kept within it', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 99);
    assert.equal(state.units, 50);
});

test('a guideline chosen before the structure loads applies once it does', () => {
    const state = createTemplateState();
    setGuideline(state, 'si_4', 8);
    applyStructureResponse(state, {sections: [], unitcount: 3, maxunits: 50});
    assert.equal(state.units, 8);
    assert.equal(requestedUnits(state), 8);
});

test('picking another template drops the manual value and follows the guideline again', () => {
    const state = loaded();
    setGuideline(state, 'si_4', 8);
    setUnits(state, '6');
    applyStructureResponse(state, {sections: [], unitcount: 5, maxunits: 50});
    assert.equal(state.units, 8);
});

test('a template without units ignores the guideline units', () => {
    const state = createTemplateState();
    applyStructureResponse(state, {sections: []});
    setGuideline(state, 'si_4', 8);
    assert.equal(state.units, 0);
    assert.equal(requestedUnits(state), null);
});

test('the guideline and its units are carried over like the other input-bar values', () => {
    const state = createTemplateState({guidelineid: 4, guidelineunits: 8});
    assert.equal(state.guidelineunits, 8);
    applyStructureResponse(state, {sections: [], unitcount: 3, maxunits: 50});
    assert.equal(state.units, 8);
});
