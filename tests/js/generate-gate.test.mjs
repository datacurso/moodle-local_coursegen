// When the Generate button is on: a template is loaded and the teacher gave a text or a file, never neither.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {createTemplateState} from 'local_coursegen/local/courseai/template/state';
import {
    hasMaterial,
    canStart,
    refreshGenerateButton,
    trackPrompt,
    attachSyllabus,
    removeSyllabus,
} from 'local_coursegen/local/courseai/template/generate_gate';

const loaded = (inputbar = {}) => {
    const tplState = createTemplateState(inputbar);
    tplState.loaded = true;
    return tplState;
};

const button = (disabled = true) => ({disabled});

const promptField = (value = '') => {
    const listeners = {};
    const field = {
        value,
        addEventListener: (name, listener) => {
            listeners[name] = listener;
        },
        type: (text) => {
            field.value = text;
            listeners.input();
        },
    };
    return field;
};

test('a new form has nothing to work from', () => {
    assert.equal(hasMaterial(createTemplateState()), false);
});

test('a request with text is something to work from', () => {
    assert.equal(hasMaterial(createTemplateState({prompt: 'Adapt it to nursing'})), true);
});

test('only blanks is no request', () => {
    for (const prompt of ['', ' ', '   ', '\n', '\t \n']) {
        assert.equal(hasMaterial(createTemplateState({prompt})), false, JSON.stringify(prompt));
    }
});

test('an attached file is something to work from', () => {
    assert.equal(hasMaterial(createTemplateState({syllabusdraftitemid: 412})), true);
});

test('a draft area that is not a number above zero is no file', () => {
    for (const syllabusdraftitemid of [0, -1, NaN, undefined, null, '', 'abc']) {
        assert.equal(hasMaterial({prompt: '', syllabusdraftitemid}), false, String(syllabusdraftitemid));
    }
});

test('a text that is not a string is no request', () => {
    for (const prompt of [undefined, null, 5, {}, []]) {
        assert.equal(hasMaterial({prompt, syllabusdraftitemid: 0}), false, String(prompt));
    }
});

test('either one, or both, is enough', () => {
    assert.equal(hasMaterial({prompt: 'x', syllabusdraftitemid: 0}), true);
    assert.equal(hasMaterial({prompt: '', syllabusdraftitemid: 7}), true);
    assert.equal(hasMaterial({prompt: 'x', syllabusdraftitemid: 7}), true);
});

test('starting needs a loaded template and something to work from', () => {
    assert.equal(canStart(loaded({prompt: 'x'}), false), true);
    assert.equal(canStart(loaded(), false), false);
    assert.equal(canStart(createTemplateState({prompt: 'x'}), false), false);
});

test('nothing starts while a generation is on screen', () => {
    assert.equal(canStart(loaded({prompt: 'x'}), true), false);
});

test('the button is off with neither text nor file and on as soon as there is one', () => {
    const tplState = loaded();
    const generate = button(false);

    refreshGenerateButton(tplState, generate, false);
    assert.equal(generate.disabled, true);

    tplState.prompt = 'Adapt it';
    refreshGenerateButton(tplState, generate, false);
    assert.equal(generate.disabled, false);

    tplState.prompt = '';
    refreshGenerateButton(tplState, generate, false);
    assert.equal(generate.disabled, true);
});

test('the button is off before a template is loaded even with a text and a file', () => {
    const tplState = createTemplateState({prompt: 'x', syllabusdraftitemid: 3});
    const generate = button(false);
    refreshGenerateButton(tplState, generate, false);
    assert.equal(generate.disabled, true);
});

test('while a generation is on screen the button belongs to the review and is left alone', () => {
    const tplState = loaded({prompt: 'x'});
    const on = button(false);
    const off = button(true);
    refreshGenerateButton(tplState, on, true);
    refreshGenerateButton(tplState, off, true);
    assert.equal(on.disabled, false);
    assert.equal(off.disabled, true);
});

test('a page without the button does not break', () => {
    refreshGenerateButton(loaded({prompt: 'x'}), null, false);
});

test('typing updates the state and tells the form, deleting it all turns the button off again', () => {
    const tplState = loaded();
    const field = promptField();
    const generate = button(true);
    trackPrompt(field, tplState, () => refreshGenerateButton(tplState, generate, false));

    field.type('Adapt it');
    assert.equal(tplState.prompt, 'Adapt it');
    assert.equal(generate.disabled, false);

    field.type('');
    assert.equal(tplState.prompt, '');
    assert.equal(generate.disabled, true);
});

test('text that the browser put back in the field after a reload counts from the start', () => {
    const tplState = loaded();
    const field = promptField('Restored by the browser');
    const generate = button(true);

    trackPrompt(field, tplState, () => refreshGenerateButton(tplState, generate, false));

    assert.equal(tplState.prompt, 'Restored by the browser');
    assert.equal(generate.disabled, false);
});

test('a page without the text field does not break', () => {
    const tplState = loaded();
    trackPrompt(null, tplState, () => undefined);
    assert.equal(tplState.prompt, '');
});

test('attaching a file turns the button on and removing it turns it off again', () => {
    const tplState = loaded();
    const generate = button(true);
    const refresh = () => refreshGenerateButton(tplState, generate, false);

    attachSyllabus(tplState, 'silabo.pdf', 55, refresh);
    assert.equal(tplState.syllabusfilename, 'silabo.pdf');
    assert.equal(tplState.syllabusdraftitemid, 55);
    assert.equal(generate.disabled, false);

    removeSyllabus(tplState, refresh);
    assert.equal(tplState.syllabusfilename, '');
    assert.equal(tplState.syllabusdraftitemid, 0);
    assert.equal(generate.disabled, true);
});

test('removing the file keeps the button on while there is still a text', () => {
    const tplState = loaded({prompt: 'Adapt it', syllabusdraftitemid: 9, syllabusfilename: 'a.pdf'});
    const generate = button(false);
    removeSyllabus(tplState, () => refreshGenerateButton(tplState, generate, false));
    assert.equal(generate.disabled, false);
});

test('a refused file that the form forgot leaves the button off when there is no text', () => {
    const tplState = loaded({syllabusdraftitemid: 9, syllabusfilename: 'a.pdf'});
    const generate = button(false);
    removeSyllabus(tplState, () => refreshGenerateButton(tplState, generate, false));
    assert.equal(generate.disabled, true);
});
