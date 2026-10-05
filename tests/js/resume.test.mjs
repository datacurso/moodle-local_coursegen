import assert from 'node:assert/strict';
import test from 'node:test';
import {makeResumeFromSnapshot} from '../../amd/src/courseai/bootstrap/resume-snapshot.js';

globalThis.document = {
    getElementById: () => null,
    body: {classList: {add() {}, contains: () => false}},
};

const EVENTS = [{type: 'status', message: 'x'}, {type: 'section', id: 's1', name: 'One'}];

const build = (snapshot, resumeExtra = {}) => {
    const calls = {open: [], hydrate: 0, review: 0, thread: [], logs: [], completion: [], createCourse: 0, controls: 0};
    const state = {defaultLang: 'en'};
    const resume = {
        success: true, recordid: 5, sessionid: 'thread-1', streamingurl: 'relay-url',
        coursedatajson: '{}', snapshotjson: JSON.stringify(snapshot), sessionstatus: 1, courseid: 0, iscreated: false,
        ...resumeExtra,
    };
    const run = makeResumeFromSnapshot({
        state,
        elements: {},
        CourseaiRepository: {getSessionState: async() => resume},
        stepsUi: {transitionToPlanning() {}, setStepState() {}, setProgress() {}, updateFlowNav() {}},
        planningUi: {syncCompactChatState() {}, showReviewActions() {
            calls.review++;
        }},
        detailedUi: {enableAllActionControls() {
            calls.controls++;
        }},
        proposalsUi: {renderProposals() {}},
        streamManager: {openSSEStream: (...args) => calls.open.push(args)},
        actions: {showCompletionView: (args) => calls.completion.push(args)},
        createCourseFromSession: async() => {
            calls.createCourse++;
        },
        parseJsonField: (value, fallback) => {
            try {
                return JSON.parse(value);
            } catch (e) {
                return fallback;
            }
        },
        normalizeSnapshotStatus: (s) => String(s || '').toUpperCase(),
        buildSectionsFromDetailedPlan: (sections) => sections,
        buildCourseUrlFromResume: () => '',
        applyCourseTitleToHeader() {},
        setPlanningStreamVisible() {},
        hydrateDetailedPlanFromSnapshot: async() => {
            calls.hydrate++;
        },
        resumeSessionId: 5,
        replayThread: async(thread, opts) => calls.thread.push([thread.length, opts]),
        emitLog: (entry) => calls.logs.push(entry),
        texts: {courseai_error_generic: 'Generation failed'},
    });
    return {run, calls, state};
};

const SECTIONS = [{id: 's1', name: 'One', activities: []}];
const THREAD = [{type: 'user_prompt'}];

test('a planning run with events replays them through a fresh stream and draws nothing from the plan', async() => {
    const {run, calls, state} = build({status: 'PLANNING', progress_events: EVENTS, detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.deepEqual(calls.open, [['relay-url', 0, 'planning', false, EVENTS]]);
    assert.equal(calls.hydrate, 0);
    assert.equal(calls.thread.length, 1);
    assert.deepEqual(state.latestInitialSections, []);
});

test('a planning run without events keeps the earlier way: hydrate, then follow the stream', async() => {
    const {run, calls} = build({status: 'PLANNING', detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.equal(calls.hydrate, 1);
    assert.deepEqual(calls.open, [['relay-url', 0, 'planning', true]]);
});

test('a generation with events replays them over the hydrated approved plan', async() => {
    const {run, calls} = build({status: 'GENERATING', progress_events: EVENTS, detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.equal(calls.hydrate, 1);
    assert.deepEqual(calls.open, [['relay-url', 0, 'generating', true, EVENTS]]);
});

test('an adjustment in flight draws the plan, replays its events and shows no review actions', async() => {
    const {run, calls} = build({status: 'PLANNING_ADJUST', progress_events: EVENTS, detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.equal(calls.hydrate, 1);
    assert.deepEqual(calls.open, [['relay-url', 0, 'planning', true, EVENTS]]);
    assert.equal(calls.review, 0);
});

test('a plan at review shows the review and opens no stream, even with stale events', async() => {
    const {run, calls} = build({status: 'WAITING_APPROVAL', progress_events: EVENTS, detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.deepEqual(calls.open, []);
    assert.equal(calls.review, 1);
});

test('an adjustment without events keeps showing the review', async() => {
    const {run, calls} = build({status: 'PLANNING_ADJUST', detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.deepEqual(calls.open, []);
    assert.equal(calls.review, 1);
});

test('a finished generation whose course is not created yet asks for the course details again', async() => {
    const {run, calls, state} = build({status: 'COMPLETED', detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.equal(calls.createCourse, 1);
    assert.equal(calls.review, 0);
    assert.deepEqual(calls.open, []);
    assert.equal(state.planApproved, true);
    assert.equal(state.currentStage, 'generating');
});

test('a finished generation whose course exists shows the completion view and does not ask again', async() => {
    const {run, calls} = build(
        {status: 'COMPLETED', detailed_plan_sections: SECTIONS, thread: THREAD},
        {courseid: 9, iscreated: true}
    );
    assert.equal(await run(), true);
    assert.equal(calls.createCourse, 0);
    assert.equal(calls.completion.length, 1);
    assert.equal(calls.completion[0].courseid, 9);
});

test('a failed generation draws the conversation, tells what failed and opens no stream', async() => {
    const {run, calls, state} = build({status: 'FAILED', detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(await run(), true);
    assert.equal(calls.hydrate, 1);
    assert.equal(calls.thread.length, 1);
    assert.deepEqual(calls.open, []);
    assert.equal(calls.review, 0);
    assert.equal(calls.controls, 1);
    assert.equal(state.currentStage, 'failed');
    assert.deepEqual(calls.logs, [{actor: 'ai', kind: 'danger', message: 'Generation failed'}]);
});

test('a failed generation without a plan still tells what failed', async() => {
    const {run, calls} = build({status: 'FAILED', thread: THREAD});
    assert.equal(await run(), true);
    assert.equal(calls.hydrate, 0);
    assert.deepEqual(calls.logs, [{actor: 'ai', kind: 'danger', message: 'Generation failed'}]);
});

test('the syllabus chip of the chat input comes back with the name the session stores', async() => {
    const nodes = {
        compactChipSyllabus: {classList: {remove() {}, contains: () => true}},
        compactChipSyllabusName: {textContent: ''},
        compactChipsRow: {style: {}},
    };
    const previous = globalThis.document;
    globalThis.document = {...previous, getElementById: (id) => nodes[id] || null};
    try {
        const {run} = build({status: 'WAITING_APPROVAL', detailed_plan_sections: SECTIONS, thread: THREAD}, {syllabusname: 'Marketing.pdf'});
        await run();
    } finally {
        globalThis.document = previous;
    }
    assert.equal(nodes.compactChipSyllabusName.textContent, 'Marketing.pdf');
    assert.equal(nodes.compactChipsRow.style.display, 'flex');
});

const withSkeletons = async(snapshot) => {
    const skeletons = {cgLeftSkeleton: {style: {display: ''}}, cgCenterSkeleton: {style: {display: ''}}};
    const previous = globalThis.document;
    globalThis.document = {...previous, getElementById: (id) => skeletons[id] || null};
    try {
        const {run} = build(snapshot);
        await run();
    } finally {
        globalThis.document = previous;
    }
    return skeletons;
};

test('the skeletons stay while a run has not drawn its first section', async() => {
    const skeletons = await withSkeletons({status: 'PLANNING', progress_events: EVENTS.slice(0, 1), thread: THREAD});
    assert.equal(skeletons.cgCenterSkeleton.style.display, '');
    assert.equal(skeletons.cgLeftSkeleton.style.display, '');
});

test('the skeletons go once a section was drawn', async() => {
    const skeletons = await withSkeletons({status: 'PLANNING', progress_events: EVENTS, detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(skeletons.cgCenterSkeleton.style.display, 'none');
    assert.equal(skeletons.cgLeftSkeleton.style.display, 'none');
});

test('the skeletons go at a review, which always has a plan', async() => {
    const skeletons = await withSkeletons({status: 'WAITING_APPROVAL', detailed_plan_sections: SECTIONS, thread: THREAD});
    assert.equal(skeletons.cgCenterSkeleton.style.display, 'none');
});

test('a finished generation is drawn as the live stream leaves it: generated, with the controls enabled but hidden', async() => {
    const {run, calls, state} = build({status: 'COMPLETED', detailed_plan_sections: SECTIONS, thread: THREAD});
    state.latestInitialSections = SECTIONS;
    await run();
    assert.equal(calls.controls, 1);
    assert.ok(state.generationTracker);
    assert.equal(calls.createCourse, 1);
});

test('a generation still running is drawn with its controls enabled, then followed live', async() => {
    const {run, calls} = build({status: 'GENERATING', detailed_plan_sections: SECTIONS, thread: THREAD});
    await run();
    assert.equal(calls.controls, 1);
    assert.equal(calls.open.length, 1);
});
