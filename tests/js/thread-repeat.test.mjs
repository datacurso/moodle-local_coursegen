// The thread of a run: a step the AI repeats is one line with a count, and every other turn is a line of its own.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {turn, resetThread} from '../../amd/src/local/courseai/template/thread.js';
import {drawn, reset as resetDrawn} from './stubs/log.mjs';
import {makeFeed} from './support/fake-feed.mjs';

let feed = null;

beforeEach(() => {
    resetDrawn();
    feed = makeFeed();
    globalThis.document = {getElementById: (id) => (id === 'cgLog' ? feed : null)};
    feed.innerHTML = '';
    resetThread();
});

test('the same step twice in a row is drawn once and counted', () => {
    turn('ai', 'ai', 'Reading an activity');
    turn('ai', 'ai', 'Reading an activity');
    assert.equal(drawn.length, 1);
    assert.equal(feed.children[0].dataset.repeat, '2');
});

test('three equal steps in a row are one line counted three times', () => {
    turn('ai', 'ai', 'Reading an activity');
    turn('ai', 'ai', 'Reading an activity');
    turn('ai', 'ai', 'Reading an activity');
    assert.equal(drawn.length, 1);
    assert.equal(feed.children[0].dataset.repeat, '3');
});

test('different steps are drawn as lines of their own', () => {
    turn('ai', 'ai', 'Reading the template');
    turn('ai', 'ai', 'Reading an activity');
    assert.equal(drawn.length, 2);
});

test('the same step after another one is a new line', () => {
    turn('ai', 'ai', 'Reading an activity');
    turn('ai', 'ai', 'Modifying an activity');
    turn('ai', 'ai', 'Reading an activity');
    assert.equal(drawn.length, 3);
});

test('two answers of the teacher with the same words stay two lines', () => {
    turn('user', 'user', 'Yes');
    turn('user', 'user', 'Yes');
    assert.equal(drawn.length, 2);
});

test('a step of the AI after a turn of the teacher with the same words is a new line', () => {
    turn('user', 'user', 'Yes');
    turn('ai', 'ai', 'Yes');
    assert.equal(drawn.length, 2);
});

test('a markdown turn is never merged', () => {
    turn('ai', 'ai', 'Plan', true);
    turn('ai', 'ai', 'Plan', true);
    assert.equal(drawn.length, 2);
});

test('an empty step is not drawn and does not count', () => {
    turn('ai', 'ai', 'Reading an activity');
    turn('ai', 'ai', '   ');
    turn('ai', 'ai', 'Reading an activity');
    assert.equal(drawn.length, 1);
    assert.equal(feed.children[0].dataset.repeat, '2');
});
