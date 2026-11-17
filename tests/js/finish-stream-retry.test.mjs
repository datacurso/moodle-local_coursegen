import {test} from 'node:test';
import assert from 'node:assert/strict';
import {finishTemplateGenerationStream} from '../../amd/src/local/courseai/template/finish_stream.js';
import {ATTEMPTS} from '../../amd/src/local/courseai/template/retry_schedule.js';

const noWait = () => Promise.resolve();
const lost = () => Object.assign(new Error('lost'), {errorcode: 'connection'});

test('a lost connection is retried with a line that says which retry it is and the time left', async() => {
    globalThis.window = {location: {href: 'https://site.example/x'}};
    let opened = 0;
    const open = async() => {
        opened++;
        if (opened <= 2) {
            throw lost();
        }
        return {success: true, courseid: 7};
    };
    const news = [];
    const result = await finishTemplateGenerationStream('https://site.example', 3, {}, (n) => news.push(n), {open, pause: noWait});
    assert.deepEqual(result, {success: true, courseid: 7});
    assert.equal(opened, 3);
    const retrying = news.filter((n) => n.step === 'retrying');
    assert.ok(retrying.length > 0);
    assert.deepEqual([...new Set(retrying.map((n) => n.done))], [1, 2]);
    assert.ok(retrying.every((n) => n.total === ATTEMPTS));
});

test('it gives up after the retries', async() => {
    globalThis.window = {location: {href: 'https://site.example/x'}};
    let opened = 0;
    const open = async() => {
        opened++;
        throw lost();
    };
    await assert.rejects(finishTemplateGenerationStream('https://site.example', 3, {}, () => undefined, {open, pause: noWait}));
    assert.equal(opened, ATTEMPTS + 1);
});

test('a failure that waiting does not mend is not retried', async() => {
    globalThis.window = {location: {href: 'https://site.example/x'}};
    let opened = 0;
    const open = async() => {
        opened++;
        throw Object.assign(new Error('no'), {errorcode: 'nopermissions'});
    };
    await assert.rejects(finishTemplateGenerationStream('https://site.example', 3, {}, () => undefined, {open, pause: noWait}));
    assert.equal(opened, 1);
});

test('a failure that the server says may be retried is retried like a lost connection', async() => {
    globalThis.window = {location: {href: 'https://site.example/x'}};
    let opened = 0;
    const open = async() => {
        opened++;
        if (opened === 1) {
            throw Object.assign(new Error('the service could not be reached'), {errorcode: 'curlerror', retryable: true});
        }
        return {success: true, courseid: 9};
    };
    const result = await finishTemplateGenerationStream('https://site.example', 3, {}, () => undefined, {open, pause: noWait});
    assert.equal(result.courseid, 9);
    assert.equal(opened, 2);
});
