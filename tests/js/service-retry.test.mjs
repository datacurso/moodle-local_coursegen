// What the person sees when the service retries after the AI provider failed.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';
import {findAll, makeDocument, settle} from './fake-dom.mjs';
import {setPauseForTests} from '../../amd/src/local/courseai/template/retry_schedule.js';

let doc = null;
let status = null;

beforeEach(async() => {
    doc = makeDocument();
    globalThis.document = doc;
    globalThis.window = {requestAnimationFrame: (fn) => fn(), setTimeout, clearTimeout};
    setPauseForTests(() => new Promise((resolve) => setTimeout(resolve, 5)));
    status = await import('../../amd/src/local/courseai/template/generation_status.js');
});

const cards = () => doc.elements.cgNoticeDock.children.filter((node) => node.id === 'cgServiceRetryCard');

test('a retry of the service shows the card with which retry it is and no buttons', async() => {
    await status.showNotice({code: 'retrying', attempt: 3, of: 10, seconds: 40, status: 503});
    await settle();
    assert.equal(cards().length, 1);
    const line = findAll(cards()[0], (node) => String(node.className || '').includes('cg-retry-status'))[0];
    assert.ok(/retry_status|retry_running/.test(line.textContent), 'the line of the retry');
    assert.equal(findAll(cards()[0], (node) => node.tag === 'button').length, 0, 'the service retries: no buttons');
});

test('a newer retry replaces the card and the end of the retries takes it off', async() => {
    await status.showNotice({code: 'retrying', attempt: 1, of: 10, seconds: 10});
    await status.showNotice({code: 'retrying', attempt: 2, of: 10, seconds: 20});
    await settle();
    assert.equal(cards().length, 1, 'one card at a time');
    await status.showNotice({code: 'retry_over'});
    await settle();
    assert.equal(cards().length, 0);
});
