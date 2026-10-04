import assert from 'node:assert/strict';
import test from 'node:test';
import {makeResumeFromSnapshot} from '../../amd/src/courseai/bootstrap/resume-snapshot.js';

globalThis.document = {
    getElementById: () => null,
    body: {classList: {add() {}, contains: () => false}},
};

const EVENTS = [{type: 'status', message: 'x'}, {type: 'section', id: 's1', name: 'One'}];

const build = (snapshot, resumeExtra = {}) => {
    const calls = {open: [], hydrate: 0, review: 0, thread: []};
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
        detailedUi: {enableAllActionControls() {}},
        proposalsUi: {renderProposals() {}},
        streamManager: {openSSEStream: (...args) => calls.open.push(args)},
        actions: {showCompletionView() {}},
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
        emitLog() {},
        texts: {},
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
