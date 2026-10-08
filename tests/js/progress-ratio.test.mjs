// The share of the activities that are written, which the meter of the progress card draws.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {progressRatio} from '../../amd/src/local/courseai/template/progress_ratio.js';

test('nothing written yet is an empty meter', () => {
    assert.equal(progressRatio({done: 0, total: 2}), 0);
});

test('half of the activities written is half of the meter', () => {
    assert.equal(progressRatio({done: 1, total: 2}), 0.5);
});

test('every activity written is a full meter', () => {
    assert.equal(progressRatio({done: 2, total: 2}), 1);
});

test('a run that announced no activities has an empty meter, not a division by zero', () => {
    assert.equal(progressRatio({done: 0, total: 0}), 0);
    assert.equal(progressRatio({done: 3, total: 0}), 0);
});

test('a counter that ran past the total never draws more than a full meter', () => {
    assert.equal(progressRatio({done: 5, total: 2}), 1);
});

test('a negative counter never draws less than an empty meter', () => {
    assert.equal(progressRatio({done: -1, total: 4}), 0);
});

test('counters that are not numbers draw an empty meter', () => {
    assert.equal(progressRatio({done: undefined, total: undefined}), 0);
    assert.equal(progressRatio({done: 'x', total: 3}), 0);
    assert.equal(progressRatio({done: 1, total: null}), 0);
});

test('counters that arrive as numeric strings are read as numbers', () => {
    assert.equal(progressRatio({done: '1', total: '4'}), 0.25);
});

test('a missing progress object draws an empty meter', () => {
    assert.equal(progressRatio(undefined), 0);
    assert.equal(progressRatio(null), 0);
});
