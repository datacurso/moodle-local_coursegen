import assert from 'node:assert/strict';
import test from 'node:test';
import {startUiStateTracking} from '../../amd/src/courseai/bootstrap/ui-state-tracking.js';
import {loadUiState} from '../../amd/src/courseai/bootstrap/ui-state-storage.js';
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
    };
};

const memory = () => {
    const items = {};
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

const timers = () => {
    const queue = [];
    return {
        queue,
        schedule: (callback) => {
            queue.push(callback);
            return queue.length;
        },
        cancel: (handle) => {
            queue[handle - 1] = null;
        },
        flush: () => queue.splice(0).forEach((callback) => callback && callback()),
    };
};

const setup = (sessionId = 5) => {
    const root = Object.assign(eventTarget(), page({
        lists: {'.cg-group-head': [toggle(true)]},
        scrollers: {planningView: scroller(120, 900, 1800)},
    }));
    const win = eventTarget();
    const storage = memory();
    const clock = timers();
    const tracker = startUiStateTracking({
        root, win, storage, getSessionId: () => sessionId, schedule: clock.schedule, cancel: clock.cancel,
    });
    return {root, win, storage, clock, tracker};
};

test('a click saves the state of the page once the click had its effect', () => {
    const {root, storage, clock} = setup();
    root.fire('click');
    assert.equal(loadUiState(storage, 5), null);
    clock.flush();
    assert.deepEqual(loadUiState(storage, 5).toggles.group, [true]);
});

test('a burst of scrolls saves once', () => {
    const {root, clock} = setup();
    root.fire('scroll');
    root.fire('scroll');
    root.fire('scroll');
    assert.equal(clock.queue.filter(Boolean).length, 1);
});

test('the scroll position is saved with the state', () => {
    const {root, storage, clock} = setup();
    root.fire('scroll');
    clock.flush();
    assert.deepEqual(loadUiState(storage, 5).scroll.plan, {top: 120, atEnd: false});
});

test('leaving the page saves at once', () => {
    const {win, storage} = setup();
    win.fire('pagehide');
    assert.notEqual(loadUiState(storage, 5), null);
});

test('nothing is saved before the page has a session', () => {
    const {root, storage, clock} = setup(0);
    root.fire('click');
    clock.flush();
    assert.deepEqual(storage.items, {});
});

test('stopping removes the listeners and the pending save', () => {
    const {root, win, storage, clock, tracker} = setup();
    root.fire('click');
    tracker.stop();
    clock.flush();
    root.fire('click');
    win.fire('pagehide');
    assert.deepEqual(storage.items, {});
});

test('typing saves the draft', () => {
    const {root, clock} = setup();
    root.fire('input');
    assert.equal(clock.queue.filter(Boolean).length, 1);
});
