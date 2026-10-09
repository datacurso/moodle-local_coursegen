// Tests of what the template start form does when the service refuses the syllabus it was given.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {isSyllabusRefusal, forgetRefusedSyllabus} from 'local_coursegen/local/courseai/template/syllabus_refusal';

const attached = () => ({syllabusdraftitemid: 8421, syllabusfilename: 'syllabus.pdf', prompt: 'Make it'});

test('every message about the syllabus is a refusal of the syllabus', () => {
    const codes = [
        'templatesyllabusmissing', 'templatesyllabusempty', 'templatesyllabustoolarge', 'templatesyllabusrejected',
        'templatesyllabusrun', 'templatesyllabustimeout',
    ];
    for (const code of codes) {
        assert.equal(isSyllabusRefusal({errorcode: code}), true, code);
    }
});

test('other errors are not refusals of the syllabus', () => {
    for (const error of [{errorcode: 'invalidlicensekey'}, {errorcode: 'httperror'}, {errorcode: ''}, {}, null, undefined, 'text', 4]) {
        assert.equal(isSyllabusRefusal(error), false, JSON.stringify(error));
    }
});

test('a code that only contains the word is not a refusal', () => {
    assert.equal(isSyllabusRefusal({errorcode: 'othertemplatesyllabus'}), false);
});

test('a refused syllabus is forgotten and the rest of the form stays', () => {
    const state = attached();
    const forgotten = forgetRefusedSyllabus(state, {errorcode: 'templatesyllabusrejected'});
    assert.equal(forgotten, true);
    assert.equal(state.syllabusdraftitemid, 0);
    assert.equal(state.syllabusfilename, '');
    assert.equal(state.prompt, 'Make it');
});

test('any other error keeps the syllabus', () => {
    const state = attached();
    const forgotten = forgetRefusedSyllabus(state, {errorcode: 'invalidlicensekey'});
    assert.equal(forgotten, false);
    assert.equal(state.syllabusdraftitemid, 8421);
    assert.equal(state.syllabusfilename, 'syllabus.pdf');
});

test('a form that attached nothing is left as it is', () => {
    const state = {syllabusdraftitemid: 0, syllabusfilename: ''};
    assert.equal(forgetRefusedSyllabus(state, {errorcode: 'templatesyllabusmissing'}), true);
    assert.deepEqual(state, {syllabusdraftitemid: 0, syllabusfilename: ''});
});
