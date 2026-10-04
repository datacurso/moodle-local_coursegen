import assert from 'node:assert/strict';
import test from 'node:test';
import {askForCourseDetails, showFailure} from '../../amd/src/courseai/bootstrap/resume-end-states.js';

test('asking for the course details opens the form once and logs nothing when it works', async() => {
    const logs = [];
    let opened = 0;
    await askForCourseDetails({
        createCourseFromSession: async() => {
            opened++;
        },
        emitLog: (entry) => logs.push(entry),
        texts: {},
    });
    assert.equal(opened, 1);
    assert.deepEqual(logs, []);
});

test('asking for the course details tells the reason when the form fails', async() => {
    const logs = [];
    await askForCourseDetails({
        createCourseFromSession: async() => {
            throw new Error('no categories');
        },
        emitLog: (entry) => logs.push(entry),
        texts: {courseai_error_generic: 'Generation failed'},
    });
    assert.deepEqual(logs, [{actor: 'ai', kind: 'danger', message: 'no categories'}]);
});

test('asking for the course details falls back to the generic text when the error has no message', async() => {
    const logs = [];
    await askForCourseDetails({
        createCourseFromSession: async() => {
            throw new Error('');
        },
        emitLog: (entry) => logs.push(entry),
        texts: {courseai_error_generic: 'Generation failed'},
    });
    assert.deepEqual(logs, [{actor: 'ai', kind: 'danger', message: 'Generation failed'}]);
});

test('showing a failure marks the stage, the step and the turn, and frees the plan controls', () => {
    const state = {};
    const steps = [];
    const logs = [];
    let freed = 0;
    showFailure({
        state,
        stepsUi: {setStepState: (...args) => steps.push(args)},
        detailedUi: {enableAllActionControls: () => freed++},
        emitLog: (entry) => logs.push(entry),
        texts: {},
    });
    assert.equal(state.currentStage, 'failed');
    assert.deepEqual(steps, [['planning', 'active']]);
    assert.deepEqual(logs, [{actor: 'ai', kind: 'danger', message: 'Generation failed'}]);
    assert.equal(freed, 1);
});

test('showing a failure works when the plan panel has no controls to free', () => {
    const logs = [];
    showFailure({
        state: {},
        stepsUi: {setStepState() {}},
        detailedUi: {},
        emitLog: (entry) => logs.push(entry),
        texts: {courseai_error_generic: 'Something failed'},
    });
    assert.deepEqual(logs, [{actor: 'ai', kind: 'danger', message: 'Something failed'}]);
});
