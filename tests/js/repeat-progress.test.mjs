import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRepeatTracker} from '../../amd/src/local/courseai/template/repeat_progress.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('an activity with no repeat block reports nothing', () => {
    const tracker = createRepeatTracker();
    assert.equal(tracker.summary('a'), null);
});

test('the plan gives the total and nothing is written yet', () => {
    const tracker = createRepeatTracker();
    tracker.plan('a', 'b1', 50);
    assert.deepEqual(tracker.summary('a'), {done: 0, total: 50, last: '', names: []});
});

test('the progress counts the items written out of the planned ones', () => {
    const tracker = createRepeatTracker();
    tracker.plan('a', 'b1', 50);
    tracker.progress('a', 'b1', 10, 50, 'Kappa');
    assert.deepEqual(tracker.summary('a'), {done: 10, total: 50, last: 'Kappa', names: []});
});

test('a late message never takes the count back', () => {
    const tracker = createRepeatTracker();
    tracker.plan('a', 'b1', 50);
    tracker.progress('a', 'b1', 30, 50, 'Omega');
    tracker.progress('a', 'b1', 10, 50, 'Kappa');
    assert.deepEqual(tracker.summary('a'), {done: 30, total: 50, last: 'Omega', names: []});
});

test('the blocks of one activity add up and the activities stay apart', () => {
    const tracker = createRepeatTracker();
    tracker.plan('a', 'b1', 10);
    tracker.plan('a', 'b2', 5);
    tracker.plan('z', 'b1', 7);
    tracker.progress('a', 'b1', 10, 10, 'Ten');
    tracker.progress('a', 'b2', 2, 5, 'Two');
    assert.deepEqual(tracker.summary('a'), {done: 12, total: 15, last: 'Two', names: []});
    assert.deepEqual(tracker.summary('z'), {done: 0, total: 7, last: '', names: []});
});

test('a progress that comes before its plan still counts', () => {
    const tracker = createRepeatTracker();
    tracker.progress('a', 'b1', 4, 20, 'Four');
    assert.deepEqual(tracker.summary('a'), {done: 4, total: 20, last: 'Four', names: []});
});

test('a run that starts over forgets the counts', () => {
    const tracker = createRepeatTracker();
    tracker.plan('a', 'b1', 5);
    tracker.reset();
    assert.equal(tracker.summary('a'), null);
});

test('the row of an activity has the place where the count is shown, hidden until a plan arrives', () => {
    const template = read('templates/template_generation_item.mustache');
    assert.match(template, /courseai-checklist-repeat" hidden/);
    assert.match(template, /courseai-checklist-repeat-count/);
});

test('the count is styled in the top bar panel, in a file of its own', () => {
    const bar = read('styles/template_repeat_count.css');
    assert.match(bar, /\.tpl-topbar-panel \.courseai-checklist-repeat/);
});

test('the names of the planned items are kept and added up over the blocks', () => {
    const tracker = createRepeatTracker();
    tracker.plan('a', 'b1', 2, ['Alfa', 'Beta']);
    tracker.plan('a', 'b2', 1, ['Gamma']);
    assert.deepEqual(tracker.summary('a').names, ['Alfa', 'Beta', 'Gamma']);
});
