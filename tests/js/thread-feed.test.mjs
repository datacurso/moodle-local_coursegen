import {test} from 'node:test';
import assert from 'node:assert/strict';
import {makeDocument} from './fake-dom.mjs';
import {currentFeed, listIsShown} from '../../amd/src/local/courseai/template/thread_feed.js';

test('before the activities are listed the thread grows in the feed above the list', () => {
    globalThis.document = makeDocument();
    assert.equal(listIsShown(), false);
    assert.equal(currentFeed(), document.getElementById('cgLog'));
});

test('once the list is on the page the thread grows under it', () => {
    globalThis.document = makeDocument();
    document.getElementById('courseaiLeftChecklist').classList.remove('hidden');
    assert.equal(listIsShown(), true);
    assert.equal(currentFeed(), document.getElementById('cgLogAfter'));
});

test('a page with no feed under the list keeps using the one above', () => {
    globalThis.document = {
        getElementById: (id) => ({cgLog: {id: 'cgLog'}, courseaiLeftChecklist: {classList: {contains: () => false}}})[id] || null,
    };
    assert.equal(currentFeed().id, 'cgLog');
});
