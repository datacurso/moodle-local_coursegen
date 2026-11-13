// The number of units the professor asks for follows the structure the server described.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    applyStructureResponse,
    createTemplateState,
    countsForUnits,
    requestedUnits,
    setUnits,
} from '../../amd/src/local/courseai/template/state.js';

const loaded = (extra = {}) => {
    const state = createTemplateState();
    applyStructureResponse(state, {sections: [], ...extra});
    return state;
};

test('a template with units starts at its own count', () => {
    const state = loaded({unitcount: 3, maxunits: 50});
    assert.equal(state.unitcount, 3);
    assert.equal(state.maxunits, 50);
    assert.equal(state.units, 3);
});

test('a template without units has no unit field', () => {
    const state = loaded();
    assert.equal(state.unitcount, 0);
    assert.equal(requestedUnits(state), null);
});

test('the template count asks for nothing', () => {
    assert.equal(requestedUnits(loaded({unitcount: 3, maxunits: 50})), null);
});

test('another number is asked for', () => {
    const state = loaded({unitcount: 3, maxunits: 50});
    setUnits(state, '7');
    assert.equal(requestedUnits(state), 7);
});

test('the number is kept between 1 and the maximum', () => {
    const state = loaded({unitcount: 3, maxunits: 50});
    setUnits(state, '0');
    assert.equal(state.units, 1);
    setUnits(state, '999');
    assert.equal(state.units, 50);
    setUnits(state, '-4');
    assert.equal(state.units, 1);
});

test('an empty or invalid value goes back to the template count', () => {
    const state = loaded({unitcount: 3, maxunits: 50});
    setUnits(state, '');
    assert.equal(state.units, 3);
    setUnits(state, 'abc');
    assert.equal(state.units, 3);
    assert.equal(requestedUnits(state), null);
});

test('picking another template resets the number', () => {
    const state = loaded({unitcount: 3, maxunits: 50});
    setUnits(state, '9');
    applyStructureResponse(state, {sections: [], unitcount: 5, maxunits: 50});
    assert.equal(state.units, 5);
});

const act = (isinstance = false) => ({id: 1, name: 'a', modname: 'page', isinstance});
const unitsTemplate = () => loaded({
    unitcount: 2,
    maxunits: 50,
    sections: [
        {id: 0, name: 'General', depth: 0, activities: [act()]},
        {id: 1, name: 'U1', depth: 0, activities: [act(), act()]},
        {id: 2, name: 'U1.1', depth: 1, activities: [act(true), act()]},
        {id: 3, name: 'U2', depth: 0, activities: [act()]},
        {id: 4, name: 'U2.1', depth: 1, activities: [act(true), act(true), act()]},
        {id: 5, name: 'U2.2', depth: 1, activities: [act(true)]},
        {id: 6, name: 'Closing', depth: 0, activities: [act()]},
    ],
});

test('the counts of the template stand while its own number of units is asked', () => {
    assert.deepEqual(countsForUnits(unitsTemplate()), {sections: 7, activities: 11});
});

test('more units add the last unit with its subsections and the activities the AI writes in it', () => {
    const state = unitsTemplate();
    setUnits(state, 4);
    // Two more copies of U2 (a section and two subsections) with three instance activities each.
    assert.deepEqual(countsForUnits(state), {sections: 7 + 6, activities: 11 + 6});
});

test('fewer units drop the last units with all their activities', () => {
    const state = unitsTemplate();
    setUnits(state, 1);
    assert.deepEqual(countsForUnits(state), {sections: 4, activities: 6});
});

test('a template without units counts what it has', () => {
    const state = loaded({sections: [{id: 1, name: 'S', depth: 0, activities: [act(), act()]}]});
    assert.deepEqual(countsForUnits(state), {sections: 1, activities: 2});
});
