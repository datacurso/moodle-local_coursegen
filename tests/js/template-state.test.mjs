// Tests of the logic of the template editor that needs no browser: the choices, the checks and the payload.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import * as State from 'local_coursegen/local/template/items_state';

const row = (cmid, action, instruction) => ({cmid, action, instruction});
const model = (overrides) => ({templateid: 3, courseid: 42, name: 'Marketing', description: '', rows: [], ...overrides});

test('every value that is not "ai" keeps the activity', () => {
    for (const value of ['', 'keep', 'AI', 'Ai', 'delete', undefined, null, 0, 1, {}, [], true]) {
        assert.equal(State.normalizeAction(value), State.KEEP, String(value));
    }
});

test('the value "ai" is the action of an activity modified with AI', () => {
    assert.equal(State.normalizeAction('ai'), State.AI);
});

test('only "ai" shows the instruction', () => {
    assert.equal(State.showsInstruction('ai'), true);
    assert.equal(State.showsInstruction('keep'), false);
    assert.equal(State.showsInstruction(undefined), false);
});

test('a kept activity is saved without its instruction', () => {
    const item = State.buildItem(row(7, 'keep', 'typed before'));
    assert.deepEqual(item, {cmid: 7, action: 'keep', instruction: ''});
});

test('an activity modified with AI keeps its instruction exactly', () => {
    const text = '  Line one\n\nLine two <b>x</b> 😀 áéíóú  ';
    assert.deepEqual(State.buildItem(row(7, 'ai', text)), {cmid: 7, action: 'ai', instruction: text});
});

test('an instruction that is not text becomes an empty one', () => {
    for (const value of [undefined, null, 5, {}, ['x']]) {
        const item = State.buildItem(row(1, 'ai', value));
        assert.equal(item.instruction, '');
    }
});

test('an unknown action is saved as keep without an instruction', () => {
    const item = State.buildItem(row(1, 'delete', 'x'));
    assert.deepEqual(item, {cmid: 1, action: 'keep', instruction: ''});
});

test('the items keep the order of the page', () => {
    const items = State.buildItems([row(3, 'ai', 'c'), row(1, 'keep', ''), row(2, 'ai', 'b')]);
    assert.deepEqual([items[0].cmid, items[1].cmid, items[2].cmid], [3, 1, 2]);
});

test('an empty page has no items', () => {
    assert.deepEqual(State.buildItems([]), []);
});

test('a template needs a course and a name', () => {
    assert.deepEqual(State.validate(model()), []);
    assert.deepEqual(State.validate(model({courseid: 0})), ['course']);
    assert.deepEqual(State.validate(model({name: ''})), ['name']);
    assert.deepEqual(State.validate(model({courseid: 0, name: ''})), ['course', 'name']);
});

test('invalid courses are rejected', () => {
    for (const courseid of [0, -1, NaN, undefined, null]) {
        assert.deepEqual(State.validate(model({courseid})), ['course'], String(courseid));
    }
});

test('invalid names are rejected', () => {
    for (const name of ['', '   ', '\t\n', null, undefined, 5]) {
        assert.deepEqual(State.validate(model({name})), ['name'], String(name));
    }
});

test('a name with unicode or emoji is valid', () => {
    assert.deepEqual(State.validate(model({name: 'Mercadeo digital 😀'})), []);
});

test('the payload trims the name and keeps the description as typed', () => {
    const payload = State.buildPayload(model({name: '  Marketing  ', description: ' Base\ncourse '}));
    assert.equal(payload.name, 'Marketing');
    assert.equal(payload.description, ' Base\ncourse ');
    assert.equal(payload.templateid, 3);
    assert.equal(payload.courseid, 42);
});

test('a description that is not text is sent empty', () => {
    assert.equal(State.buildPayload(model({description: null})).description, '');
});

test('nothing changed is not dirty', () => {
    const rows = [row(1, 'ai', 'a'), row(2, 'keep', '')];
    const initial = State.signature(model({rows}));
    assert.equal(State.isDirty(initial, model({rows})), false);
});

test('changing the name, the course, the description, an action or an instruction is dirty', () => {
    const rows = [row(1, 'ai', 'a'), row(2, 'keep', '')];
    const initial = State.signature(model({rows}));
    assert.equal(State.isDirty(initial, model({rows, name: 'Other'})), true);
    assert.equal(State.isDirty(initial, model({rows, courseid: 43})), true);
    assert.equal(State.isDirty(initial, model({rows, description: 'new'})), true);
    assert.equal(State.isDirty(initial, model({rows: [row(1, 'keep', 'a'), row(2, 'keep', '')]})), true);
    assert.equal(State.isDirty(initial, model({rows: [row(1, 'ai', 'b'), row(2, 'keep', '')]})), true);
});

test('typing in the instruction of a kept activity is not a change', () => {
    const initial = State.signature(model({rows: [row(2, 'keep', '')]}));
    assert.equal(State.isDirty(initial, model({rows: [row(2, 'keep', 'typed then hidden')]})), false);
});

test('spaces around the name are not a change', () => {
    const initial = State.signature(model({name: 'Marketing'}));
    assert.equal(State.isDirty(initial, model({name: ' Marketing '})), false);
});

test('switching to AI and back to keep is not a change', () => {
    const initial = State.signature(model({rows: [row(2, 'keep', '')]}));
    const there = model({rows: [row(2, 'ai', 'x')]});
    const back = model({rows: [row(2, 'keep', 'x')]});
    assert.equal(State.isDirty(initial, there), true);
    assert.equal(State.isDirty(initial, back), false);
});

test('a different order of the activities is a change', () => {
    const initial = State.signature(model({rows: [row(1, 'ai', 'a'), row(2, 'ai', 'b')]}));
    assert.equal(State.isDirty(initial, model({rows: [row(2, 'ai', 'b'), row(1, 'ai', 'a')]})), true);
});

test('five hundred activities build their payload and compare quickly', () => {
    const rows = [];
    for (let index = 0; index < 600; index++) {
        let action = 'keep';
        if (index % 2 === 0) {
            action = 'ai';
        }
        rows.push(row(index + 1, action, 'instruction ' + index));
    }
    const started = Date.now();
    const initial = State.signature(model({rows}));
    const dirty = State.isDirty(initial, model({rows}));
    const payload = State.buildPayload(model({rows}));
    const elapsed = Date.now() - started;
    assert.equal(dirty, false);
    assert.equal(payload.items.length, 600);
    assert.ok(elapsed < 1000);
});
