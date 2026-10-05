import assert from 'node:assert/strict';
import test from 'node:test';
import {restoreAndTrackUiState} from '../../amd/src/courseai/bootstrap/ui-state.js';
import {saveUiState, loadUiState} from '../../amd/src/courseai/bootstrap/ui-state-storage.js';
import {page, scroller, toggle} from './support/fake-page.mjs';

const eventTarget = () => {
    const listeners = {};
    return {
        listeners,
        addEventListener: (type, handler) => {
            listeners[type] = (listeners[type] || []).concat([handler]);
        },
        removeEventListener: (type, handler) => {
            listeners[type] = (listeners[type] || []).filter((candidate) => candidate !== handler);
        },
        fire: (type) => (listeners[type] || []).forEach((handler) => handler({type})),
        count: (type) => (listeners[type] || []).length,
    };
};

const memory = () => {
    const items = {};
    return {
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

const build = ({saved, group = toggle(false)} = {}) => {
    const plan = scroller(0, 900, 1800);
    const root = Object.assign(eventTarget(), page({
        lists: {'.cg-group-head': [group]},
        scrollers: {planningView: plan},
    }));
    const win = eventTarget();
    const storage = memory();
    if (saved) {
        saveUiState(storage, 5, saved);
    }
    return {root, win, storage, plan, group};
};

const SAVED = {toggles: {group: [true]}, scroll: {plan: {top: 300, atEnd: false}}};

const run = (parts, extra = {}) => restoreAndTrackUiState({
    root: parts.root,
    win: parts.win,
    storage: parts.storage,
    getSessionId: () => 5,
    restore: true,
    settle: async() => undefined,
    restoreOptions: {wait: async() => undefined},
    ...extra,
});

test('what the user left open is put back once the page settled', async() => {
    const parts = build({saved: SAVED});
    await run(parts);
    assert.equal(parts.group.clicks, 1);
    assert.equal(parts.plan.scrollTop, 300);
});

test('the page is tracked after the restore, so the restore does not overwrite what was saved', async() => {
    const parts = build({saved: SAVED});
    parts.group.clicks = 0;
    await run(parts);
    assert.equal(parts.root.count('click'), 1);
    assert.deepEqual(loadUiState(parts.storage, 5), SAVED);
});

test('a session that saved nothing restores nothing and is tracked', async() => {
    const parts = build();
    await run(parts);
    assert.equal(parts.group.clicks, 0);
    assert.equal(parts.root.count('click'), 1);
});

test('a new page restores nothing and is tracked', async() => {
    const parts = build({saved: SAVED});
    await run(parts, {restore: false});
    assert.equal(parts.group.clicks, 0);
    assert.equal(parts.root.count('click'), 1);
});

test('the user taking over before the page settled cancels the restore', async() => {
    const parts = build({saved: SAVED});
    await run(parts, {settle: async() => parts.root.fire('wheel')});
    assert.equal(parts.group.clicks, 0);
    assert.equal(parts.plan.scrollTop, 0);
    assert.equal(parts.root.count('click'), 1);
});

test('the listeners of the takeover guard are removed when the restore ends', async() => {
    const parts = build({saved: SAVED});
    await run(parts);
    ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach((type) => {
        assert.equal(parts.root.count(type), 0, type);
    });
});
