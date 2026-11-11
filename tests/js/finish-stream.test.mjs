import {test} from 'node:test';
import assert from 'node:assert/strict';
import {streamUrl} from '../../amd/src/local/courseai/template/finish_stream.js';

test('the address carries the session and only what the teacher chose', () => {
    globalThis.window = {location: {href: 'https://site.example/local/coursegen/aicoursecreation.php'}};
    const url = new URL(streamUrl('https://site.example', 24, {fullname: 'Fundamentos', shortname: '', category: 3}));
    assert.equal(url.pathname, '/local/coursegen/finish_template.php');
    assert.equal(url.searchParams.get('sessionid'), '24');
    assert.equal(url.searchParams.get('fullname'), 'Fundamentos');
    assert.equal(url.searchParams.get('category'), '3');
    assert.equal(url.searchParams.has('shortname'), false);
});

test('without choices the address has only the session', () => {
    globalThis.window = {location: {href: 'https://site.example/x'}};
    const url = new URL(streamUrl('https://site.example', 5, null));
    assert.deepEqual([...url.searchParams.keys()], ['sessionid']);
});
