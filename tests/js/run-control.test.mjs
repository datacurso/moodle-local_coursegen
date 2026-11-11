import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    STATE,
    bindControlButtons,
    createRunControl,
    renderControl,
} from '../../amd/src/local/courseai/template/generation_control.js';

const fakeDocument = () => {
    const make = () => ({
        hidden: true,
        disabled: false,
        dataset: {},
        attrs: {},
        handlers: {},
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
        addEventListener(type, handler) {
            (this.handlers[type] = this.handlers[type] || []).push(handler);
        },
        removeEventListener(type, handler) {
            this.handlers[type] = (this.handlers[type] || []).filter((h) => h !== handler);
        },
        click() {
            (this.handlers.click || []).forEach((handler) => handler());
        },
    });
    const elements = {tplRunBar: make(), tplGenStop: make(), tplGenResume: make()};
    return {elements, getElementById: (id) => elements[id] || null};
};

test('a run starts idle and the Stop button is only offered while it runs', () => {
    const control = createRunControl();
    assert.equal(control.state(), STATE.IDLE);
    assert.equal(control.requestStop(), false, 'nothing runs, so there is nothing to stop');
    control.begin();
    assert.equal(control.state(), STATE.RUNNING);
});

test('stopping closes the open pass once and asks for nothing more', () => {
    const control = createRunControl();
    let closed = 0;
    control.begin();
    control.attach(() => {
        closed++;
    });
    assert.equal(control.requestStop(), true);
    assert.equal(control.requestStop(), false, 'a second click while stopping is ignored');
    assert.equal(closed, 1);
    assert.equal(control.state(), STATE.STOPPING);
});

test('an order given before the stream exists is remembered and spent once', () => {
    const control = createRunControl();
    control.begin();
    assert.equal(control.requestStop(), true);
    assert.equal(control.takePendingStop(), true);
    assert.equal(control.takePendingStop(), false);
});

test('an order given with a pass open is not left pending', () => {
    const control = createRunControl();
    control.begin();
    control.attach(() => undefined);
    control.requestStop();
    assert.equal(control.takePendingStop(), false);
});

test('a pass that ended by itself can no longer be closed by an old order', () => {
    const control = createRunControl();
    let closed = 0;
    control.begin();
    control.attach(() => {
        closed++;
    });
    control.detach();
    control.requestStop();
    assert.equal(closed, 0);
    assert.equal(control.takePendingStop(), true, 'the order waits for the next pass instead');
});

test('Resume only works once the run is stopped, and then it goes on', async() => {
    const control = createRunControl();
    control.begin();
    assert.equal(control.requestResume(), false);
    control.attach(() => undefined);
    control.requestStop();
    assert.equal(control.requestResume(), false, 'still stopping: the service has not confirmed');
    control.markStopped();
    const waiting = control.waitForResume();
    assert.equal(control.state(), STATE.STOPPED);
    assert.equal(control.requestResume(), true);
    await waiting;
    assert.equal(control.state(), STATE.RUNNING);
    assert.equal(control.requestResume(), false, 'a second click does nothing');
});

test('after a resume the run can be stopped again', () => {
    const control = createRunControl();
    control.begin();
    control.attach(() => undefined);
    control.requestStop();
    control.markStopped();
    control.waitForResume();
    control.requestResume();
    control.attach(() => undefined);
    assert.equal(control.requestStop(), true);
});

test('ending the run clears everything it was holding', async() => {
    const control = createRunControl();
    control.begin();
    control.requestStop();
    control.end();
    assert.equal(control.state(), STATE.IDLE);
    assert.equal(control.takePendingStop(), false);
    assert.equal(control.requestResume(), false);
});

test('the state changes are reported once each, in order', () => {
    const seen = [];
    const control = createRunControl((state) => seen.push(state));
    control.begin();
    control.begin();
    control.attach(() => undefined);
    control.requestStop();
    control.markStopped();
    control.waitForResume();
    control.requestResume();
    control.end();
    assert.deepEqual(seen, [STATE.RUNNING, STATE.STOPPING, STATE.STOPPED, STATE.RUNNING, STATE.IDLE]);
});

test('the buttons follow the state', () => {
    const doc = fakeDocument();
    const {tplRunBar: bar, tplGenStop: stop, tplGenResume: resume} = doc.elements;

    renderControl(STATE.IDLE, doc);
    assert.deepEqual([bar.hidden, stop.hidden, resume.hidden], [true, true, true]);

    renderControl(STATE.RUNNING, doc);
    assert.deepEqual([bar.hidden, stop.hidden, stop.disabled, resume.hidden], [false, false, false, true]);

    renderControl(STATE.STOPPING, doc);
    assert.deepEqual([bar.hidden, stop.hidden, stop.disabled, resume.hidden], [false, false, true, true]);
    assert.equal(stop.attrs['aria-busy'], 'true');

    renderControl(STATE.STOPPED, doc);
    assert.deepEqual([bar.hidden, stop.hidden, resume.hidden], [false, true, false]);

    renderControl(STATE.IDLE, doc);
    assert.equal(bar.hidden, true, 'the bar goes away with the run');
});

test('a page without the buttons does not break', () => {
    const doc = {getElementById: () => null};
    renderControl(STATE.RUNNING, doc);
    const unbind = bindControlButtons(createRunControl(), doc);
    unbind();
});

test('the buttons order the control, and taking them off stops that', () => {
    const doc = fakeDocument();
    const control = createRunControl();
    let closed = 0;
    control.begin();
    control.attach(() => {
        closed++;
    });
    const unbind = bindControlButtons(control, doc);

    doc.elements.tplGenStop.click();
    assert.equal(closed, 1);

    control.markStopped();
    control.waitForResume();
    doc.elements.tplGenResume.click();
    assert.equal(control.state(), STATE.RUNNING);

    unbind();
    control.attach(() => {
        closed++;
    });
    doc.elements.tplGenStop.click();
    assert.equal(closed, 1, 'no handler is left after unbinding');
});
