import assert from 'node:assert/strict';
import test from 'node:test';
import {showApprovedPlan, showGeneratedPlan} from '../../amd/src/courseai/bootstrap/resume-generated-plan.js';

const classes = () => {
    const names = new Set();
    return {add: (name) => names.add(name), remove: (...list) => list.forEach((n) => names.delete(n)), contains: (n) => names.has(n)};
};

const row = () => ({classList: classes()});

const page = (activityIds) => {
    const rows = {};
    activityIds.forEach((id) => {
        rows[id] = row();
    });
    const nodes = {
        prvHeader: {classList: classes()},
        prvSpinnerIcon: {style: {display: ''}},
        prvCheckIcon: {style: {display: 'none'}},
        prvHeaderSub: {textContent: ''},
        prvSections: {querySelector: (selector) => rows[selector.match(/"(.+)"/)[1]] || null},
    };
    const root = {body: {classList: classes()}, getElementById: (id) => nodes[id] || null};
    return {root, rows, nodes};
};

const TEXTS = {courseai_section_label: 'Section', courseai_activity_default: 'Activity', courseai_finalizing_course: 'Finalizing…'};
const SECTIONS = [
    {id: 's1', activities: [{id: 'a1', title: 'Book', activity_type: 'book'}, {id: 'a2', title: 'Quiz', activity_type: 'quiz'}]},
    {id: 's2', deleted: true, activities: [{id: 'a3', title: 'Gone'}]},
];

const run = (sections, activityIds = ['a1', 'a2', 'a3']) => {
    const {root, rows, nodes} = page(activityIds);
    const state = {latestInitialSections: sections};
    const previous = globalThis.document;
    globalThis.document = root;
    try {
        showGeneratedPlan({state, texts: TEXTS, root});
    } finally {
        globalThis.document = previous;
    }
    return {root, rows, nodes, state};
};

test('every activity of the plan shows as generated', () => {
    const {rows} = run(SECTIONS);
    assert.equal(rows.a1.classList.contains('cg-gen-done'), true);
    assert.equal(rows.a2.classList.contains('cg-gen-done'), true);
});

test('an activity of a deleted section is not marked', () => {
    const {rows} = run(SECTIONS);
    assert.equal(rows.a3.classList.contains('cg-gen-done'), false);
});

test('the page takes the generating look, which hides the editing controls', () => {
    const {root} = run(SECTIONS);
    assert.equal(root.body.classList.contains('cg-generating'), true);
});

test('the header shows the check and the finalizing text, as the live stream leaves it', () => {
    const {nodes} = run(SECTIONS);
    assert.equal(nodes.prvHeader.classList.contains('prv-header--done'), true);
    assert.equal(nodes.prvSpinnerIcon.style.display, 'none');
    assert.equal(nodes.prvCheckIcon.style.display, '');
    assert.equal(nodes.prvHeaderSub.textContent, 'Finalizing…');
});

test('the header leaves the streaming look', () => {
    const {nodes} = run(SECTIONS);
    nodes.prvHeader.classList.add('prv-header--stream');
    run(SECTIONS);
    const second = run(SECTIONS);
    assert.equal(second.nodes.prvHeader.classList.contains('prv-header--stream'), false);
});

test('the tracker of the page is kept for what comes after', () => {
    const {state} = run(SECTIONS);
    assert.equal(state.generationTracker.flat.length, 2);
    assert.equal(state.generationTracker.flat.every((activity) => activity.status === 'done'), true);
});

test('a plan without sections and a page without header do not fail', () => {
    const root = {body: {classList: classes()}, getElementById: () => null};
    const previous = globalThis.document;
    globalThis.document = root;
    try {
        assert.doesNotThrow(() => showGeneratedPlan({state: {latestInitialSections: []}, texts: TEXTS, root}));
    } finally {
        globalThis.document = previous;
    }
});

test('an approved plan that is still generating leaves the streaming look and gets its controls enabled', () => {
    const {root, nodes} = page([]);
    nodes.prvHeader.classList.add('prv-header--stream');
    let enabled = 0;
    showApprovedPlan({root, detailedUi: {enableAllActionControls: () => enabled++}});
    assert.equal(nodes.prvHeader.classList.contains('prv-header--stream'), false);
    assert.equal(enabled, 1);
});

test('an approved plan without a plan panel that enables controls does not fail', () => {
    const {root} = page([]);
    assert.doesNotThrow(() => showApprovedPlan({root, detailedUi: {}}));
});
