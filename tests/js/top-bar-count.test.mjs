// The activities pill of the top bar follows the checklist: the short count, the meter and the closing of its panel.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {
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

let nodes = {};
let card = null;
let panel = null;
let toggle = null;

const installPage = () => {
    card = {classList: makeClasses(['hidden']), style: {props: {}, setProperty: (name, value) => {
        card.style.props[name] = value;
    }}};
    panel = {hidden: false};
    toggle = {attributes: {'aria-expanded': 'true'}, setAttribute: (name, value) => {
        toggle.attributes[name] = value;
    }};
    nodes = {
        courseaiChecklist: card,
        courseaiChecklistCount: {textContent: ''},
        courseaiChecklistList: {innerHTML: '', appended: []},
        tplTopActivitiesCount: {textContent: ''},
        tplTopActivitiesPanel: panel,
        tplTopActivitiesToggle: toggle,
    };
    globalThis.document = {
        getElementById: (id) => nodes[id] || null,
        querySelector: () => ({classList: makeClasses(['is-loading']), querySelector: () => null}),
        querySelectorAll: () => [],
    };
};

beforeEach(async() => {
    installPage();
    resetChecklist();
    await flush();
});

test('opening the card for two activities puts the short count on the pill', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    assert.equal(nodes.tplTopActivitiesCount.textContent, 'local_coursegen:courseai_template_progress_short:[object Object]');
});

test('the full count of the panel is still written, so both say the same thing', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    assert.equal(nodes.courseaiChecklistCount.textContent, 'local_coursegen:courseai_template_progress_count:[object Object]');
});

test('the pill and the panel appear together: the card wrapper loses its hidden class', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    assert.equal(card.classList.contains('hidden'), false);
});

test('an activity that closes moves the meter that the ring of the pill reads', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    closeActivity('a', {total: 2, done: 1});
    await flush();
    assert.equal(card.style.props['--cg-progress'], '0.5');
});

test('the end of the run fills the ring and the short count', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    settleChecklist({total: 2, done: 1});
    await flush();
    assert.equal(card.style.props['--cg-progress'], '1');
});

test('a run that starts over closes the panel so it never greets the next run open', async() => {
    openChecklist({total: 2, done: 0});
    await flush();
    resetChecklist();
    await flush();
    assert.equal(panel.hidden, true);
    assert.equal(toggle.attributes['aria-expanded'], 'false');
    assert.equal(card.classList.contains('hidden'), true);
});

test('a page without the pill still counts in the panel', async() => {
    delete nodes.tplTopActivitiesCount;
    openChecklist({total: 3, done: 1});
    await flush();
    assert.equal(nodes.courseaiChecklistCount.textContent, 'local_coursegen:courseai_template_progress_count:[object Object]');
});
