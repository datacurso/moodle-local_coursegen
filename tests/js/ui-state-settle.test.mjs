import assert from 'node:assert/strict';
import test from 'node:test';
import {waitForQuiet} from '../../amd/src/courseai/bootstrap/ui-state-settle.js';

const harness = () => {
    const state = {observed: null, disconnected: false, timers: [], callback: null};
    class FakeObserver {
        constructor(callback) {
            state.callback = callback;
        }

        observe(target, options) {
            state.observed = {target, options};
        }

        disconnect() {
            state.disconnected = true;
        }
    }
    const schedule = (callback, delay) => {
        state.timers.push({callback, delay, active: true});
        return state.timers.length - 1;
    };
    const cancel = (handle) => {
        state.timers[handle].active = false;
    };
    const fire = (delay) => {
        state.timers.filter((timer) => timer.active && timer.delay === delay).forEach((timer) => {
            timer.active = false;
            timer.callback();
        });
    };
    return {state, options: {Observer: FakeObserver, schedule, cancel, quietMs: 500, maxMs: 3500}, fire};
};

test('the page is quiet when nothing changed for the quiet time', async() => {
    const {state, options, fire} = harness();
    const target = {};
    const waiting = waitForQuiet(target, options);
    fire(500);
    await waiting;
    assert.equal(state.observed.target, target);
    assert.equal(state.disconnected, true);
});

test('a change restarts the quiet time', async() => {
    const {state, options, fire} = harness();
    const waiting = waitForQuiet({}, options);
    state.callback();
    state.callback();
    const quietTimers = state.timers.filter((timer) => timer.delay === 500);
    assert.equal(quietTimers.filter((timer) => timer.active).length, 1);
    fire(500);
    await waiting;
});

test('a page that never stops changing is waited for no longer than the maximum', async() => {
    const {state, options, fire} = harness();
    const waiting = waitForQuiet({}, options);
    state.callback();
    fire(3500);
    await waiting;
    assert.equal(state.disconnected, true);
});

test('the watching stops when the wait ends, whichever way it ends', async() => {
    const {state, options, fire} = harness();
    const waiting = waitForQuiet({}, options);
    fire(500);
    await waiting;
    assert.equal(state.timers.every((timer) => !timer.active), true);
});

test('a missing target is quiet at once', async() => {
    await waitForQuiet(null, {});
});
