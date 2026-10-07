// The waiting line under the progress list: one line updated in place, removed when the call ends, and the paused
// state that stops the spinners while the AI waits for an answer.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {
    clearWaiting,
    setPaused,
    showWaiting,
} from '../../amd/src/local/courseai/template/generation_waiting.js';

const flush = () => new Promise((resolve) => setImmediate(resolve));

let created = 0;
let page = null;

const makePage = (withChecklist) => {
    const nodes = {};
    const bodyClasses = new Set();
    const checklist = {
        insertAdjacentElement: (where, element) => {
            nodes[element.id] = element;
            assert.equal(where, 'afterend');
        },
    };
    if (withChecklist) {
        nodes.courseaiChecklist = checklist;
    }
    return {
        nodes,
        bodyClasses,
        getElementById: (id) => nodes[id] || null,
        createElement: () => {
            created += 1;
            const element = {
                attributes: {},
                textContent: '',
                setAttribute(name, value) {
                    this.attributes[name] = value;
                },
                remove() {
                    delete nodes[this.id];
                },
            };
            return element;
        },
        body: {
            classList: {
                toggle: (name, force) => {
                    if (force) {
                        bodyClasses.add(name);
                    } else {
                        bodyClasses.delete(name);
                    }
                },
            },
        },
    };
};

beforeEach(async() => {
    created = 0;
    await flush();
    page = makePage(true);
    globalThis.document = page;
});

test('the first tick creates the line under the progress list with the seconds in it', async() => {
    showWaiting(12);
    await flush();
    const line = page.nodes.cgWaitingLine;
    assert.equal(line.textContent, 'local_coursegen:template_agent_waiting:12');
    assert.equal(line.attributes.role, 'status');
    assert.equal(line.className, 'cg-waiting-line');
});

test('the next ticks update the same line in place', async() => {
    showWaiting(8);
    showWaiting(16);
    showWaiting(24);
    await flush();
    assert.equal(created, 1);
    assert.equal(page.nodes.cgWaitingLine.textContent, 'local_coursegen:template_agent_waiting:24');
});

test('the line goes away when the call ends and comes back with the next tick', async() => {
    showWaiting(8);
    clearWaiting();
    await flush();
    assert.equal(page.nodes.cgWaitingLine, undefined);
    showWaiting(8);
    await flush();
    assert.equal(page.nodes.cgWaitingLine.textContent, 'local_coursegen:template_agent_waiting:8');
});

test('a tick that arrives before a clear does not outlive it', async() => {
    showWaiting(30);
    clearWaiting();
    showWaiting(31);
    clearWaiting();
    await flush();
    assert.equal(page.nodes.cgWaitingLine, undefined);
});

test('clearing when no line was ever shown does nothing', async() => {
    clearWaiting();
    await flush();
    assert.equal(created, 0);
});

test('a page with no progress list shows no line and does not fail', async() => {
    page = makePage(false);
    globalThis.document = page;
    showWaiting(5);
    await flush();
    assert.equal(created, 0);
    assert.equal(page.nodes.cgWaitingLine, undefined);
});

test('the paused state is a class of the page that a resume takes off', () => {
    setPaused(true);
    assert.equal(page.bodyClasses.has('cg-generation-paused'), true);
    setPaused(false);
    assert.equal(page.bodyClasses.has('cg-generation-paused'), false);
});
