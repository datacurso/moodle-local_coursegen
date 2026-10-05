import assert from 'node:assert/strict';
import test from 'node:test';
import {TOGGLE_KINDS} from '../../amd/src/courseai/bootstrap/ui-state-capture.js';
import {restoreUiState} from '../../amd/src/courseai/bootstrap/ui-state-restore.js';
import {page, scroller, shown, textField, toggle} from './support/fake-page.mjs';

const lists = (overrides = {}) => {
    const result = {};
    TOGGLE_KINDS.forEach((kind) => {
        result[kind.selector] = overrides[kind.name] || [];
    });
    return result;
};

const noWait = async() => undefined;

test('only the toggles whose state differs are clicked', async() => {
    const closed = toggle(false);
    const open = toggle(true);
    const alreadyClosed = toggle(false);
    const root = page({lists: lists({group: [closed, open, alreadyClosed]})});

    await restoreUiState(root, {toggles: {group: [true, true, false]}}, {wait: noWait});

    assert.deepEqual([closed.clicks, open.clicks, alreadyClosed.clicks], [1, 0, 0]);
});

test('saved toggles beyond the ones the page has are ignored', async() => {
    const only = toggle(false);
    const root = page({lists: lists({section: [only]})});

    await restoreUiState(root, {toggles: {section: [true, true, true]}}, {wait: noWait});

    assert.equal(only.clicks, 1);
});

test('entries that are not booleans are ignored', async() => {
    const element = toggle(false);
    const root = page({lists: lists({detail: [element]})});

    await restoreUiState(root, {toggles: {detail: ['yes'], unknown: [true]}}, {wait: noWait});

    assert.equal(element.clicks, 0);
});

test('a panel is scrolled to where it was', async() => {
    const plan = scroller(0, 900, 1800);
    const root = page({lists: lists(), scrollers: {planningView: plan}});

    await restoreUiState(root, {toggles: {}, scroll: {plan: {top: 637, atEnd: false}}}, {wait: noWait});

    assert.equal(plan.scrollTop, 637);
});

test('a panel that was at its end goes to the end, however long the content grew', async() => {
    const chat = scroller(0, 700, 2400);
    const root = page({lists: lists(), scrollers: {courseaiChatScroll: chat}});

    await restoreUiState(root, {toggles: {}, scroll: {chat: {top: 100, atEnd: true}}}, {wait: noWait});

    assert.equal(chat.scrollTop, 2400);
});

test('the scroll is restored after the toggles had time to change the height', async() => {
    const order = [];
    const element = toggle(false, () => order.push('click'));
    const plan = scroller(0, 900, 1800);
    const root = page({lists: lists({section: [element]}), scrollers: {planningView: plan}});

    await restoreUiState(root, {toggles: {section: [true]}, scroll: {plan: {top: 50, atEnd: false}}}, {
        wait: async() => order.push('wait'),
    });

    assert.deepEqual(order, ['click', 'wait']);
    assert.equal(plan.scrollTop, 50);
});

test('nothing is restored once the user took over', async() => {
    const element = toggle(false);
    const plan = scroller(0, 900, 1800);
    const root = page({lists: lists({group: [element]}), scrollers: {planningView: plan}});

    await restoreUiState(root, {toggles: {group: [true]}, scroll: {plan: {top: 50, atEnd: false}}}, {
        wait: noWait,
        shouldStop: () => true,
    });

    assert.equal(element.clicks, 0);
    assert.equal(plan.scrollTop, 0);
});

test('a missing or malformed state restores nothing', async() => {
    const root = page({lists: lists()});
    await assert.doesNotReject(restoreUiState(root, null, {wait: noWait}));
    await assert.doesNotReject(restoreUiState(root, {toggles: 'x', scroll: 5}, {wait: noWait}));
});

test('a panel that is not on the page is skipped', async() => {
    const root = page({lists: lists()});
    await assert.doesNotReject(restoreUiState(root, {toggles: {}, scroll: {plan: {top: 5, atEnd: false}}}, {wait: noWait}));
});

test('the draft comes back in the field, and the page is told so its send button reacts', async() => {
    const field = textField('');
    const root = page({lists: lists(), scrollers: {compactPromptInput: field}});

    await restoreUiState(root, {toggles: {}, drafts: {compact: 'Add a forum'}}, {wait: noWait});

    assert.equal(field.value, 'Add a forum');
    assert.deepEqual(field.events, ['input']);
});

test('a field that already has text keeps it', async() => {
    const field = textField('typed after the reload');
    const root = page({lists: lists(), scrollers: {compactPromptInput: field}});

    await restoreUiState(root, {toggles: {}, drafts: {compact: 'older draft'}}, {wait: noWait});

    assert.equal(field.value, 'typed after the reload');
    assert.deepEqual(field.events, []);
});

test('drafts that are not text, or whose field is not on the page, are ignored', async() => {
    const root = page({lists: lists()});
    await assert.doesNotReject(restoreUiState(root, {toggles: {}, drafts: {compact: 'x', other: 5}}, {wait: noWait}));
    const field = textField('');
    const withField = page({lists: lists(), scrollers: {compactPromptInput: field}});
    await restoreUiState(withField, {toggles: {}, drafts: {compact: 42}}, {wait: noWait});
    assert.equal(field.value, '');
});

test('a user who was adjusting is taken back to the chat input when the page shows the review card', async() => {
    const adjust = shown('');
    const root = page({lists: lists(), scrollers: {cgDecisionOverlay: shown('flex'), cgDecisionAdjust: adjust}});

    await restoreUiState(root, {toggles: {}, modes: {adjusting: true}}, {wait: noWait});

    assert.equal(adjust.clicks, 1);
});

test('adjusting is not restored when the review card is not showing', async() => {
    const adjust = shown('');
    const root = page({lists: lists(), scrollers: {cgDecisionOverlay: shown('none'), cgDecisionAdjust: adjust}});

    await restoreUiState(root, {toggles: {}, modes: {adjusting: true}}, {wait: noWait});

    assert.equal(adjust.clicks, 0);
});

test('a user who was not adjusting is left at the review card', async() => {
    const adjust = shown('');
    const root = page({lists: lists(), scrollers: {cgDecisionOverlay: shown('flex'), cgDecisionAdjust: adjust}});

    await restoreUiState(root, {toggles: {}, modes: {adjusting: false}}, {wait: noWait});

    assert.equal(adjust.clicks, 0);
});

test('the chat input is back before the draft is, so the draft has a field to go in', async() => {
    const order = [];
    const adjust = shown('', () => order.push('adjust'));
    const field = textField('');
    field.dispatchEvent = () => order.push('draft');
    const root = page({lists: lists(), scrollers: {cgDecisionOverlay: shown('flex'), cgDecisionAdjust: adjust, compactPromptInput: field}});

    await restoreUiState(root, {toggles: {}, modes: {adjusting: true}, drafts: {compact: 'text'}}, {wait: noWait});

    assert.deepEqual(order, ['adjust', 'draft']);
});
