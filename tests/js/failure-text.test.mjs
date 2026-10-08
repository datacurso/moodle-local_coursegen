// What the teacher reads when a run fails: always plain words, whatever shape the failure arrives in.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

import {FAILURE_KEYS, describeFailure, failureText} from 'local_coursegen/local/courseai/template/failure_text';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (path) => readFileSync(root + path, 'utf8');
const stringOf = (lang, key) => {
    const found = read(`lang/${lang}/local_coursegen.php`).match(new RegExp(`\\$string\\['${key}'\\] = '((?:[^'\\\\]|\\\\.)*)';`));
    if (found === null) {
        return null;
    }
    return found[1];
};

const GENERIC_KEY = 'template_agent_error_failed';

const keyOf = (value) => describeFailure(value).key;

test('a plain sentence from the service is shown as it is', () => {
    assert.deepEqual(describeFailure('The file could not be read.'), {
        key: null, stringId: null, stringArgs: null, text: 'The file could not be read.',
    });
});

test('blank, missing and meaningless values fall back to the generic message', () => {
    for (const value of ['', '   ', null, undefined, 4, true, [], {}, [null, undefined, ''], () => 'x']) {
        assert.equal(keyOf(value), GENERIC_KEY, String(value));
        assert.equal(describeFailure(value).text, null, String(value));
    }
});

test('every known service code has its own message', () => {
    const expected = {
        fuse: 'template_error_too_big',
        invalid_turn: 'template_error_not_understood',
        syllabus_unavailable: 'template_error_no_syllabus',
        nothing_to_start_from: 'template_error_nothing_to_start',
        not_found: 'template_error_not_found',
        document_too_long: 'template_error_document_too_long',
        stream_error: 'template_agent_error_ended',
        generation_failed: GENERIC_KEY,
        timeout: 'template_error_timeout',
        read_timeout: 'template_error_timeout',
        model_timeout: 'template_error_timeout',
        rate_limited: 'template_error_rate_limit',
        too_many_requests: 'template_error_rate_limit',
        upstream_error: 'template_error_upstream',
        provider_error: 'template_error_upstream',
        license_required: 'template_error_license',
        invalidlicensekey: 'template_error_license',
        file_save_failed: 'template_error_file_save',
    };
    for (const [code, key] of Object.entries(expected)) {
        assert.equal(keyOf({code, message: 'Raw message'}), key, code);
    }
});

test('the code is read in any case and under any of its usual names', () => {
    assert.equal(keyOf({code: 'DOCUMENT_TOO_LONG'}), 'template_error_document_too_long');
    assert.equal(keyOf({errorcode: 'invalidlicensekey'}), 'template_error_license');
    assert.equal(keyOf({error_code: 'rate_limited'}), 'template_error_rate_limit');
    assert.equal(keyOf({code: '  timeout  '}), 'template_error_timeout');
});

test('the code wins over the words that come with it', () => {
    assert.equal(keyOf({code: 'timeout', message: 'Something else'}), 'template_error_timeout');
});

test('an unknown code with a sentence shows the sentence and never the code', () => {
    const view = describeFailure({code: 'weird_internal_code', message: 'The model said no.'});
    assert.equal(view.key, null);
    assert.equal(view.text, 'The model said no.');
});

test('an unknown code with nothing else falls back to the generic message', () => {
    assert.equal(keyOf({code: 'weird_internal_code'}), GENERIC_KEY);
});

test('the HTTP status tells the reason when there is no code', () => {
    assert.equal(keyOf({status: 429}), 'template_error_rate_limit');
    assert.equal(keyOf({status: '503'}), 'template_error_upstream');
    assert.equal(keyOf({statusCode: 504}), 'template_error_timeout');
    assert.equal(keyOf({status: 401}), 'template_error_license');
    assert.equal(keyOf({status: 200}), GENERIC_KEY);
});

test('the failure may be wrapped under detail, error, errors or data', () => {
    assert.equal(keyOf({detail: {code: 'rate_limited'}}), 'template_error_rate_limit');
    assert.equal(keyOf({error: {code: 'timeout'}}), 'template_error_timeout');
    assert.equal(describeFailure({detail: 'Plain detail'}).text, 'Plain detail');
    assert.equal(describeFailure({error: {message: 'Wrapped words'}}).text, 'Wrapped words');
    assert.equal(keyOf({errors: [{code: 'file_save_failed'}]}), 'template_error_file_save');
    assert.equal(keyOf({data: {code: 'fuse'}}), 'template_error_too_big');
});

test('a failure nested too deep is not followed', () => {
    const deep = {error: {error: {error: {error: {error: {code: 'timeout'}}}}}};
    assert.equal(keyOf(deep), GENERIC_KEY);
});

test('a failure that points to itself ends instead of looping', () => {
    const loop = {message: 'Loop'};
    loop.error = loop;
    assert.equal(describeFailure(loop).text, 'Loop');
});

test('an array is read element by element, the first useful one wins', () => {
    assert.equal(keyOf(['', null, {code: 'timeout'}, {code: 'fuse'}]), 'template_error_timeout');
    assert.equal(describeFailure(['', 'First words', 'Second words']).text, 'First words');
});

test('a message the service localized keeps its string id and its arguments', () => {
    const view = describeFailure({string_id: 'stream_generic_error', string: 'Server text', string_args: {max: '3'}});
    assert.equal(view.stringId, 'stream_generic_error');
    assert.deepEqual(view.stringArgs, {max: '3'});
    assert.equal(view.text, 'Server text');
    assert.equal(view.key, null);
});

test('a localized message with a known code in its wrapper uses the code first', () => {
    const view = describeFailure({code: 'timeout', message: {string_id: 'x', string: 'Server text'}});
    assert.equal(view.key, 'template_error_timeout');
});

test('raw JSON is decoded to find the reason and never shown', () => {
    assert.equal(keyOf('{"code":"rate_limited","message":"slow down"}'), 'template_error_rate_limit');
    assert.equal(keyOf('{broken json'), GENERIC_KEY);
    assert.equal(describeFailure('{broken json').text, null);
    assert.equal(keyOf('["x"'), GENERIC_KEY);
});

test('technical text is never shown', () => {
    const technical = [
        '[object Object]',
        'Traceback (most recent call last):\n  File "x.py", line 3',
        'httpx.ReadTimeout: timed out',
        'TypeError: x is not a function',
        'at Object.run (file.js:10:5)',
        'x'.repeat(500),
    ];
    for (const text of technical) {
        assert.equal(describeFailure(text).text, null, text);
        assert.equal(keyOf(text), GENERIC_KEY, text);
    }
});

test('an Error object gives its message, unless the message is technical', () => {
    assert.equal(describeFailure(new Error('The upload broke.')).text, 'The upload broke.');
    assert.equal(keyOf(new Error('ReadTimeout: timed out')), GENERIC_KEY);
    assert.equal(keyOf(new Error('')), GENERIC_KEY);
});

test('an object with its own toString never leaks it', () => {
    const odd = {toString: () => 'secret', message: {}};
    assert.equal(keyOf(odd), GENERIC_KEY);
});

test('the words are trimmed', () => {
    assert.equal(describeFailure('  Plain words  \n').text, 'Plain words');
});

test('the text of a known code comes from the language pack', async() => {
    assert.equal(await failureText({code: 'timeout'}), 'local_coursegen:template_error_timeout:undefined');
});

test('the text of a plain sentence is the sentence', async() => {
    assert.equal(await failureText('Plain words'), 'Plain words');
});

test('the text of a missing failure is the generic message', async() => {
    for (const value of [null, undefined, '', {}, [], '[object Object]']) {
        assert.equal(await failureText(value), `local_coursegen:${GENERIC_KEY}:undefined`);
    }
});

test('the text of a localized message is read by its string id', async() => {
    const text = await failureText({string_id: 'stream_generic_error', string: 'Server text'});
    assert.equal(text, 'local_coursegen:stream_generic_error:null');
});

test('the text is never an object and never contains the object marker', async() => {
    const values = [{a: 1}, {message: {a: 1}}, [{}], new Error('x'), {toString: () => '[object Object]'}];
    for (const value of values) {
        const text = await failureText(value);
        assert.equal(typeof text, 'string');
        assert.equal(text.includes('[object'), false);
        assert.notEqual(text, '');
    }
});

test('every sentence a failure can be told with exists in English and Spanish', () => {
    assert.ok(FAILURE_KEYS.length >= 12);
    for (const key of FAILURE_KEYS) {
        for (const lang of ['en', 'es']) {
            const text = stringOf(lang, key);
            assert.notEqual(text, null, `${lang} ${key}`);
            assert.notEqual(text.trim(), '', `${lang} ${key}`);
        }
    }
});

test('the Spanish sentences avoid the informal and the formal address', () => {
    for (const key of FAILURE_KEYS) {
        const text = stringOf('es', key);
        assert.doesNotMatch(text, /\b(tu|tus|usted|ustedes|su|sus)\b/i, key);
    }
});

test('no sentence shows a code, a placeholder or an object marker', () => {
    for (const key of FAILURE_KEYS) {
        for (const lang of ['en', 'es']) {
            const text = stringOf(lang, key);
            assert.doesNotMatch(text, /\{\$a|\[object|_[a-z]+_|Traceback/, `${lang} ${key}`);
        }
    }
});

test('the retry card writes the reason as text, so markup in it never runs', () => {
    const retry = read('templates/template_agent_retry.mustache');
    assert.match(retry, /\{\{message\}\}/);
    assert.doesNotMatch(retry, /\{\{\{\s*message\s*\}\}\}/);
    assert.doesNotMatch(retry, /\{\{&\s*message/);
});
