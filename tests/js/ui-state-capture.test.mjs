import assert from 'node:assert/strict';
import test from 'node:test';
import {captureUiState, TOGGLE_KINDS} from '../../amd/src/courseai/bootstrap/ui-state-capture.js';
import {page, scroller, shown, textField, toggle} from './support/fake-page.mjs';

const lists = (overrides = {}) => {
    const result = {};
    TOGGLE_KINDS.forEach((kind) => {
        result[kind.selector] = overrides[kind.name] || [];
    });
    return result;
};

test('the open state of every toggle is read in document order', () => {
    const root = page({lists: lists({group: [toggle(true), toggle(false)], section: [toggle(true)]})});
    const state = captureUiState(root);
    assert.deepEqual(state.toggles.group, [true, false]);
    assert.deepEqual(state.toggles.section, [true]);
    assert.deepEqual(state.toggles.detail, []);
});

test('an activity reads as open when its chevron is open', () => {
    const root = page({lists: lists({activity: [toggle(true), toggle(false)]})});
    assert.deepEqual(captureUiState(root).toggles.activity, [true, false]);
});

test('the scroll of both panels is read, with whether the panel was at its end', () => {
    const root = page({
        lists: lists(),
        scrollers: {
            courseaiChatScroll: scroller(60, 900, 960),
            planningView: scroller(100, 900, 1800),
        },
    });
    const state = captureUiState(root);
    assert.deepEqual(state.scroll.chat, {top: 60, atEnd: true});
    assert.deepEqual(state.scroll.plan, {top: 100, atEnd: false});
});

test('a panel that is not on the page has no scroll', () => {
    const state = captureUiState(page({lists: lists()}));
    assert.deepEqual(state.scroll, {});
});

test('a few pixels short of the end still counts as the end', () => {
    const root = page({lists: lists(), scrollers: {planningView: scroller(100, 900, 1003)}});
    assert.equal(captureUiState(root).scroll.plan.atEnd, true);
});

test('more than a few pixels short of the end does not count as the end', () => {
    const root = page({lists: lists(), scrollers: {planningView: scroller(100, 900, 1010)}});
    assert.equal(captureUiState(root).scroll.plan.atEnd, false);
});

test('the text the user typed and did not send is read', () => {
    const root = page({lists: lists(), scrollers: {compactPromptInput: textField('Add a forum to section 1')}});
    assert.deepEqual(captureUiState(root).drafts, {compact: 'Add a forum to section 1'});
});

test('an empty draft is not kept', () => {
    const root = page({lists: lists(), scrollers: {compactPromptInput: textField('   ')}});
    assert.deepEqual(captureUiState(root).drafts, {});
});

test('a page without the field has no draft', () => {
    assert.deepEqual(captureUiState(page({lists: lists()})).drafts, {});
});

test('adjusting is read from the accept bar the Adjust button shows next to the chat input', () => {
    const adjusting = page({lists: lists(), scrollers: {cgAcceptBar: shown('flex')}});
    const reviewing = page({lists: lists(), scrollers: {cgAcceptBar: shown('none')}});
    assert.equal(captureUiState(adjusting).modes.adjusting, true);
    assert.equal(captureUiState(reviewing).modes.adjusting, false);
});

test('a page without the accept bar is not adjusting', () => {
    assert.equal(captureUiState(page({lists: lists()})).modes.adjusting, false);
});
