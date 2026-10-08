// The progress card of a template run: the meter of the card follows the count of activities written.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {
    addActivity,
    closeActivity,
    openChecklist,
    resetChecklist,
    settleChecklist,
} from '../../amd/src/local/courseai/template/generation_checklist.js';

const flush = () => new Promise((resolve) => setImmediate(resolve));

const makeClasses = (initial = []) => {
    const names = new Set(initial);
    return {
        add: (name) => names.add(name),
        remove: (name) => names.delete(name),
        contains: (name) => names.has(name),
    };
};

const makeItem = () => ({classList: makeClasses(['is-loading']), querySelector: () => null});

let card = null;
let items = {};

const makePage = (withCard) => {
    card = {
        classList: makeClasses(['hidden']),
        style: {props: {}, setProperty(name, value) {
            card.style.props[name] = value;
        }},
    };
    const count = {textContent: ''};
    const list = {innerHTML: '', appended: []};
    const nodes = {courseaiChecklistCount: count, courseaiChecklistList: list};
    if (withCard) {
        nodes.courseaiChecklist = card;
    }
    return {
        nodes,
        getElementById: (id) => nodes[id] || null,
        querySelector: (selector) => {
            const found = selector.match(/data-progress-uid="([^"]+)"/);
            return found ? items[found[1]] || null : null;
        },
        querySelectorAll: () => Object.values(items),
    };
};

let page = null;

beforeEach(async() => {
    items = {a: makeItem(), b: makeItem()};
    page = makePage(true);
    globalThis.document = page;
    resetChecklist();
    await flush();
});

test('opening the card for two activities shows an empty meter and the count in words', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    assert.equal(card.style.props['--cg-progress'], '0');
    assert.equal(page.nodes.courseaiChecklistCount.textContent, 'local_coursegen:courseai_template_progress_count:[object Object]');
    assert.equal(card.classList.contains('hidden'), false);
});

test('closing the first of two activities fills half of the meter', async() => {
    openChecklist({total: 2, done: 0});
    closeActivity('a', {total: 2, done: 1});
    await flush();
    assert.equal(card.style.props['--cg-progress'], '0.5');
    assert.equal(items.a.classList.contains('is-done'), true);
});

test('closing the last activity fills the whole meter', async() => {
    openChecklist({total: 2, done: 0});
    closeActivity('a', {total: 2, done: 1});
    closeActivity('b', {total: 2, done: 2});
    await flush();
    assert.equal(card.style.props['--cg-progress'], '1');
});

test('the end of the run fills the meter even if an activity never reported', async() => {
    openChecklist({total: 2, done: 0});
    closeActivity('a', {total: 2, done: 1});
    const progress = {total: 2, done: 1};
    settleChecklist(progress);
    await flush();
    assert.equal(progress.done, 2);
    assert.equal(card.style.props['--cg-progress'], '1');
    assert.equal(items.b.classList.contains('is-loading'), false);
});

test('a run that announced nothing keeps an empty meter', async() => {
    openChecklist({total: 0, done: 0});
    await flush();
    assert.equal(card.style.props['--cg-progress'], '0');
});

test('a page without the card paints nothing and does not fail', async() => {
    page = makePage(false);
    globalThis.document = page;
    openChecklist({total: 2, done: 0});
    closeActivity('a', {total: 2, done: 1});
    await flush();
    assert.deepEqual(card.style.props, {});
});

test('an activity that starts is added as a row of the list', async() => {
    openChecklist({total: 1, done: 0});
    addActivity({uid: 'a', name: 'Guide'});
    await flush();
    assert.equal(page.nodes.courseaiChecklistList.appended.length, 1);
});
