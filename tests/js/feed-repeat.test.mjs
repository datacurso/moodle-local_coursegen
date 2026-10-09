// A step the AI takes twice in a row ("Reading an activity" twice) is one line with a count, not two lines.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {collapseRepeat} from '../../amd/src/local/courseai/template/feed_repeat.js';
import {makeElement, makeFeed, makeStep} from './support/fake-feed.mjs';

const badgeOf = (entry) => entry.children[0].querySelector('.cg-log-repeat');

test('the same step twice in a row is merged into the first and shows a count of two', () => {
    const feed = makeFeed(makeStep('Reading an activity'));
    assert.equal(collapseRepeat(feed, 'Reading an activity'), true);
    assert.equal(feed.children.length, 1);
    assert.equal(badgeOf(feed.children[0]).textContent, '×2');
    assert.equal(feed.children[0].dataset.repeat, '2');
});

test('a third time raises the same count and never adds a second badge', () => {
    const feed = makeFeed(makeStep('Reading an activity'));
    collapseRepeat(feed, 'Reading an activity');
    collapseRepeat(feed, 'Reading an activity');
    const message = feed.children[0].children[0];
    assert.equal(message.children.filter((node) => node.classList.contains('cg-log-repeat')).length, 1);
    assert.equal(badgeOf(feed.children[0]).textContent, '×3');
});

test('the badge is decoration for the eyes: a screen reader hears the line once', () => {
    const feed = makeFeed(makeStep('Reading an activity'));
    collapseRepeat(feed, 'Reading an activity');
    assert.equal(badgeOf(feed.children[0]).attributes['aria-hidden'], 'true');
});

test('a different step is not merged', () => {
    const feed = makeFeed(makeStep('Reading the template'));
    assert.equal(collapseRepeat(feed, 'Reading an activity'), false);
    assert.equal(feed.children.length, 1);
    assert.equal(badgeOf(feed.children[0]), null);
});

test('the same step with something between the two is not merged', () => {
    const feed = makeFeed(makeStep('Reading an activity'), makeStep('Modifying an activity'));
    assert.equal(collapseRepeat(feed, 'Reading an activity'), false);
});

test('only the step at the end of the feed can take a repeat', () => {
    const feed = makeFeed(makeStep('Reading an activity'), makeStep('Reading the template'));
    assert.equal(collapseRepeat(feed, 'Reading an activity'), false);
    assert.equal(feed.children[0].dataset.repeat, undefined);
});

test('a turn of the teacher is never merged, even with the same words', () => {
    const feed = makeFeed(makeStep('Yes', 'cg-log-entry cg-log-entry--user cg-log-entry--turn-user'));
    assert.equal(collapseRepeat(feed, 'Yes'), false);
});

test('a card at the end of the feed (a question) is never merged into', () => {
    const feed = makeFeed(makeStep('Reading an activity'), makeElement('cg-decision-card'));
    assert.equal(collapseRepeat(feed, 'Reading an activity'), false);
});

test('an empty feed has nothing to merge into', () => {
    assert.equal(collapseRepeat(makeFeed(), 'Reading an activity'), false);
});

test('a missing feed has nothing to merge into and does not fail', () => {
    assert.equal(collapseRepeat(null, 'Reading an activity'), false);
});

test('a long transcript turn written as markdown is never merged', () => {
    const entry = makeStep('Plan');
    entry.children[0].className = 'cg-log-msg cg-log-md';
    const feed = makeFeed(entry);
    assert.equal(collapseRepeat(feed, 'Plan'), false);
});

test('an entry with no message element is left alone', () => {
    const feed = makeFeed(makeElement('cg-log-entry cg-log-entry--turn-ai'));
    assert.equal(collapseRepeat(feed, 'Reading an activity'), false);
});

test('a step whose words differ only in case or spacing is a different step', () => {
    const feed = makeFeed(makeStep('Reading an activity'));
    assert.equal(collapseRepeat(feed, 'reading an activity'), false);
    assert.equal(collapseRepeat(feed, 'Reading an activity '), false);
});

test('a repeat after a merge still compares with the original words, not with the badge', () => {
    const feed = makeFeed(makeStep('Reading an activity'));
    collapseRepeat(feed, 'Reading an activity');
    assert.equal(collapseRepeat(feed, 'Reading an activity ×2'), false);
    assert.equal(collapseRepeat(feed, 'Reading an activity'), true);
});
