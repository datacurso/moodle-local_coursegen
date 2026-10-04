import assert from 'node:assert/strict';
import test from 'node:test';
import {hideSkeletons, isWaitingForFirstSection} from '../../amd/src/courseai/bootstrap/resume-skeleton.js';

test('a starting run without sections or section events is waiting', () => {
    assert.equal(isWaitingForFirstSection('PLANNING', [], []), true);
    assert.equal(isWaitingForFirstSection('PENDING', undefined, undefined), true);
});

test('a plan in the snapshot ends the wait', () => {
    assert.equal(isWaitingForFirstSection('PLANNING', [{id: 's1'}], []), false);
});

test('a section event ends the wait', () => {
    assert.equal(isWaitingForFirstSection('PLANNING', [], [{type: 'status'}, {type: 'section'}]), false);
});

test('empty entries among the events are ignored', () => {
    assert.equal(isWaitingForFirstSection('PLANNING', [], [null, undefined, {type: 'status'}]), true);
});

test('only a starting run can be waiting', () => {
    ['WAITING_APPROVAL', 'GENERATING', 'COMPLETED', 'FAILED', 'PLANNING_ADJUST', ''].forEach((status) => {
        assert.equal(isWaitingForFirstSection(status, [], []), false, status);
    });
});

test('hiding skeletons hides the two the page has and ignores the ones it lacks', () => {
    const nodes = {cgLeftSkeleton: {style: {display: ''}}};
    hideSkeletons({getElementById: (id) => nodes[id] || null});
    assert.equal(nodes.cgLeftSkeleton.style.display, 'none');
});
