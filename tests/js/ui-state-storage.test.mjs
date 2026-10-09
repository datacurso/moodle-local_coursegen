import assert from 'node:assert/strict';
import test from 'node:test';
import {loadUiState, saveUiState, uiStateKey} from '../../amd/src/courseai/bootstrap/ui-state-storage.js';

const memory = (initial = {}) => {
    const items = {...initial};
    return {
        items,
        getItem: (key) => {
            if (key in items) {
                return items[key];
            }
            return null;
        },
        setItem: (key, value) => {
            items[key] = String(value);
        },
    };
};

test('the key belongs to the session, so two sessions never share a state', () => {
    assert.notEqual(uiStateKey(5), uiStateKey(6));
});

test('a saved state is loaded back', () => {
    const storage = memory();
    const state = {toggles: {group: [true]}, scroll: {plan: {top: 5, atEnd: false}}};
    assert.equal(saveUiState(storage, 5, state), true);
    assert.deepEqual(loadUiState(storage, 5), state);
});

test('a session that saved nothing loads nothing', () => {
    assert.equal(loadUiState(memory(), 5), null);
});

test('a state that is not valid JSON loads nothing', () => {
    const storage = memory({[uiStateKey(5)]: '{not json'});
    assert.equal(loadUiState(storage, 5), null);
});

test('a state that is not an object loads nothing', () => {
    ['[1,2]', '"text"', '7', 'null'].forEach((raw) => {
        assert.equal(loadUiState(memory({[uiStateKey(5)]: raw}), 5), null, raw);
    });
});

test('without a session there is nothing to load or save', () => {
    const storage = memory();
    assert.equal(saveUiState(storage, 0, {toggles: {}}), false);
    assert.equal(loadUiState(storage, 0), null);
    assert.deepEqual(storage.items, {});
});

test('a storage that fails never breaks the page', () => {
    const broken = {
        getItem: () => {
            throw new Error('blocked');
        },
        setItem: () => {
            throw new Error('full');
        },
    };
    assert.equal(loadUiState(broken, 5), null);
    assert.equal(saveUiState(broken, 5, {toggles: {}}), false);
});

test('a page without storage never breaks', () => {
    assert.equal(loadUiState(null, 5), null);
    assert.equal(saveUiState(undefined, 5, {toggles: {}}), false);
});
