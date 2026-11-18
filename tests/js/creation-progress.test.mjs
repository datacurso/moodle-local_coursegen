import {test} from 'node:test';
import assert from 'node:assert/strict';
import {creationPercent} from '../../amd/src/local/courseai/template/creation_progress.js';

test('the bar starts at zero and the first steps leave it near the start', () => {
    assert.equal(creationPercent('result', 0, 0), 0);
    assert.equal(creationPercent('course', 0, 0), 2);
});

test('the activities move the bar with each one written, from two to ninety-two percent', () => {
    assert.equal(creationPercent('activities', 0, 100), 2);
    assert.equal(creationPercent('activities', 50, 100), 47);
    assert.equal(creationPercent('activities', 100, 100), 92);
});

test('the steps after the activities have a place and the bar never reaches a hundred by itself', () => {
    assert.equal(creationPercent('kept', 0, 0, 92), 92);
    assert.equal(creationPercent('arrange', 0, 0, 92), 96);
    assert.equal(creationPercent('arrange', 0, 0, 99), 99);
});

test('a step the bar does not know, or one that comes late, leaves it where it was and it never goes back', () => {
    assert.equal(creationPercent('retrying', 3, 10, 40), 40);
    assert.equal(creationPercent('waiting', 0, 0, 40), 40);
    assert.equal(creationPercent('activities', 10, 100, 60), 60);
});
