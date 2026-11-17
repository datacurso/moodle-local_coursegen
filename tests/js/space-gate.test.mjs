// The check made when Generate is pressed: which required spaces are still without what the teacher brings.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {applyStructureResponse, setSpaceFile} from '../../amd/src/local/courseai/template/state.js';
import {missingSpaces} from '../../amd/src/local/courseai/template/space_gate.js';

const row = (id, extra = {}) => ({id, name: `Row ${id}`, modname: 'resource', ...extra});
const loaded = (rows) => {
    const state = {};
    applyStructureResponse(state, {sections: [{id: 1, name: 'S', activities: rows}]});
    return state;
};

test('a required space without its file is missing, with its place in the structure', () => {
    const state = loaded([row('1'), row('2', {isspace: true, spacerequired: true, spaceinstruction: 'Sube el PDF'})]);

    const missing = missingSpaces(state);

    assert.equal(missing.length, 1);
    assert.deepEqual([missing[0].sectionIndex, missing[0].activityIndex], [0, 1]);
    assert.equal(missing[0].activity.spaceinstruction, 'Sube el PDF');
});

test('an optional space, or a required one with its file, is not missing', () => {
    const state = loaded([row('1', {isspace: true, spacerequired: false}), row('2', {isspace: true, spacerequired: true})]);
    setSpaceFile(state, 0, 1, 'guia.pdf', 55);

    assert.deepEqual(missingSpaces(state), []);
});
