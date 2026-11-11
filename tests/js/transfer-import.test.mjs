import {test} from 'node:test';
import assert from 'node:assert/strict';
import {init} from '../../amd/src/transfer_import.js';

const element = (extra = {}) => {
    const handlers = {};
    return {
        handlers,
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
        ...extra,
    };
};

const formWith = (chosen) => {
    const category = element();
    const field = {querySelectorAll: () => chosen};
    const course = element({closest: () => field});
    return {category, course, doc: {getElementById: (id) => ({id_category: category, id_courseid: course})[id] || null}};
};

const chip = (inside = true) => {
    const chosen = {clicks: 0, click() {
        this.clicks++;
    }, closest: () => (inside ? {} : null)};
    return chosen;
};

test('choosing another category drops the course that was chosen for the previous one', () => {
    const chosen = chip();
    const form = formWith([chosen]);
    init(form.doc);
    assert.equal(chosen.clicks, 0, 'nothing is touched until the category changes');
    form.category.handlers.change();
    assert.equal(chosen.clicks, 1);
});

test('only what is chosen is dropped, not the suggestions of the list', () => {
    const chosen = chip();
    const suggestion = chip(false);
    const form = formWith([chosen, suggestion]);
    init(form.doc);
    form.category.handlers.change();
    assert.equal(chosen.clicks, 1);
    assert.equal(suggestion.clicks, 0);
});

test('a page without the two fields is left alone', () => {
    assert.doesNotThrow(() => init({getElementById: () => null}));
});
