// The spaces a teacher fills with one of their own activities: what the row keeps, when Generate stays off, what
// the generation start is given, and the list the teacher picks from.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {makeDocument} from './fake-dom.mjs';
import {
    applyStructureResponse,
    clearSpaceActivity,
    hasMissingRequiredSpace,
    pickedSpaceActivities,
    pickedSpaceFiles,
    setSpaceActivity,
} from '../../amd/src/local/courseai/template/state.js';

const structure = (rows) => ({sections: [{id: 1, name: 'S', activities: rows}]});
const space = (extra = {}) => ({
    id: 'space-1', name: 'Quiz', modname: 'quiz', isspace: true, spacekind: 'activity', spacerequired: true, ...extra,
});

beforeEach(() => {
    globalThis.document = makeDocument();
});

test('a required space without its activity keeps Generate off, and with it lets it on', () => {
    const state = {};
    applyStructureResponse(state, structure([space()]));

    assert.equal(hasMissingRequiredSpace(state), true);
    assert.equal(setSpaceActivity(state, 0, 0, {cmid: 42, name: 'Mi quiz', coursename: 'Curso A'}), true);
    assert.equal(hasMissingRequiredSpace(state), false);
    assert.equal(clearSpaceActivity(state, 0, 0), true);
    assert.equal(hasMissingRequiredSpace(state), true);
});

test('an optional space never keeps Generate off', () => {
    const state = {};
    applyStructureResponse(state, structure([space({spacerequired: false})]));

    assert.equal(hasMissingRequiredSpace(state), false);
});

test('the generation start gets the uid of each space with the activity chosen, and no file for it', () => {
    const state = {};
    applyStructureResponse(state, structure([space(), space({id: 'space-2', spacerequired: false})]));
    setSpaceActivity(state, 0, 0, {cmid: 42, name: 'Mi quiz', coursename: 'Curso A'});

    assert.deepEqual(pickedSpaceActivities(state), [{uid: 'space-1', cmid: 42}]);
    assert.deepEqual(pickedSpaceFiles(state), []);
});

test('a row that is not a space for an activity does not take one', () => {
    const state = {};
    applyStructureResponse(state, structure([
        {id: '7', name: 'Guide', modname: 'resource', isspace: true, spacekind: 'file', spacerequired: true},
        {id: '8', name: 'Page', modname: 'page'},
    ]));

    assert.equal(setSpaceActivity(state, 0, 0, {cmid: 1, name: 'x', coursename: 'y'}), false);
    assert.equal(setSpaceActivity(state, 0, 1, {cmid: 1, name: 'x', coursename: 'y'}), false);
    assert.deepEqual(pickedSpaceActivities(state), []);
});

test('a space sent by an older server, with no kind, is a file space', () => {
    const state = {};
    applyStructureResponse(state, structure([{id: '7', name: 'Guide', modname: 'resource', isspace: true}]));

    assert.equal(state.sections[0].activities[0].spacekind, 'file');
});
