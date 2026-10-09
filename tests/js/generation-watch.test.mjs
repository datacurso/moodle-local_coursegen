// The pass that follows the stream of a template run: how it ends for a question, a retry, a failure and a plain end.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {watchOnce} from 'local_coursegen/local/courseai/template/generation_watch';
import FakeRelaySource from 'local_coursegen/local/courseai/stream/relay-source';

const QUESTION = {type: 'question', call_id: 'c4x0', question: 'Which file?', options: [], ask_for_file: true};

// Maps the type of an event to the outcome the real applyEvent gives it.
const outcomeOf = (data) => {
    const outcomes = {question: 'question', completed: 'completed', failed: 'failed', failed_retry: 'retry'};
    return outcomes[data.type] || '';
};

const startPass = () => {
    const calls = {failures: [], settled: null};
    const promise = watchOnce('stream.php?x', {total: 0, done: 0}, outcomeOf, (message) => {
        calls.failures.push(message);
    });
    promise.then((value) => {
        calls.settled = {resolved: value};
    }, (error) => {
        calls.settled = {rejected: error.message};
    });
    return {source: FakeRelaySource.last(), calls, promise};
};

const flush = () => new Promise((resolve) => setImmediate(resolve));

beforeEach(() => {
    FakeRelaySource.reset();
});

test('a question followed by the done of the pause resolves the pass as a question', async() => {
    const {source, calls} = startPass();
    source.emitMessage(QUESTION);
    source.emitDone();
    await flush();
    assert.deepEqual(calls.settled.resolved.outcome, 'question');
    assert.deepEqual(calls.settled.resolved.data, QUESTION);
    assert.deepEqual(calls.failures, []);
});

test('the source is closed once when the pass ends on a question, even if done arrives right after', async() => {
    const {source} = startPass();
    source.emitMessage(QUESTION);
    source.emitDone();
    await flush();
    assert.equal(source.closed, 1);
});

test('a question that arrives after tool events still resolves the pass', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'tool_call', call_id: 'c1'});
    source.emitMessage({type: 'tool_result', call_id: 'c1'});
    source.emitMessage(QUESTION);
    source.emitDone();
    await flush();
    assert.equal(calls.settled.resolved.outcome, 'question');
});

test('a question alone, with no done after it, resolves the pass', async() => {
    const {source, calls} = startPass();
    source.emitMessage(QUESTION);
    await flush();
    assert.equal(calls.settled.resolved.outcome, 'question');
});

test('the question the service sends again when the stream is opened before the answer ends a second pass the same way', async() => {
    const first = startPass();
    first.source.emitMessage(QUESTION);
    first.source.emitDone();
    await flush();
    const second = startPass();
    second.source.emitMessage(QUESTION);
    second.source.emitDone();
    await flush();
    assert.equal(second.calls.settled.resolved.outcome, 'question');
    assert.notEqual(first.source, second.source);
});

test('a done with no event before it still fails the pass', async() => {
    const {source, calls} = startPass();
    source.emitDone();
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:template_agent_error_ended:undefined');
    assert.deepEqual(calls.failures, ['local_coursegen:template_agent_error_ended:undefined']);
});

test('a done after only tool events still fails the pass', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'tool_call', call_id: 'c1'});
    source.emitDone();
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:template_agent_error_ended:undefined');
});

test('a completed event resolves the pass as completed and the done after it changes nothing', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'completed'});
    source.emitDone();
    await flush();
    assert.equal(calls.settled.resolved.outcome, 'completed');
    assert.deepEqual(calls.failures, []);
});

test('a failure the teacher can try again resolves the pass as a retry and does not close the view', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed_retry', message: 'The provider failed'});
    source.emitDone();
    await flush();
    assert.equal(calls.settled.resolved.outcome, 'retry');
    assert.equal(calls.settled.resolved.data.message, 'The provider failed');
    assert.deepEqual(calls.failures, []);
});

test('a failure with no retry fails the pass with its message', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed', message: 'A fuse ended the run'});
    await flush();
    assert.equal(calls.settled.rejected, 'A fuse ended the run');
    assert.deepEqual(calls.failures, ['A fuse ended the run']);
});

test('a failure with no message gets a default one', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed'});
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:template_agent_error_failed:undefined');
});

test('a failure whose message is an object fails the pass with plain words, never the object marker', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed', message: {string_id: 'stream_generic_error', string: 'The stream broke.'}});
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:stream_generic_error:null');
    assert.deepEqual(calls.failures, ['local_coursegen:stream_generic_error:null']);
});

test('a failure with a known code is told by the sentence of that code', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed', code: 'document_too_long', message: 'Raw words'});
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:template_error_document_too_long:undefined');
});

test('a failure whose message is empty or an unreadable object gets the default one', async() => {
    for (const message of [{}, [], {a: {b: 1}}, '[object Object]', null]) {
        const {source, calls} = startPass();
        source.emitMessage({type: 'failed', message});
        await flush();
        assert.equal(calls.settled.rejected, 'local_coursegen:template_agent_error_failed:undefined');
        assert.equal(calls.failures.length, 1);
    }
});

test('the done that follows a failure does not replace its reason with the vague one', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed', code: 'timeout'});
    source.emitDone();
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:template_error_timeout:undefined');
    assert.equal(calls.failures.length, 1);
});

test('the connection error that follows a failure does not replace its reason either', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'failed', message: 'A fuse ended the run'});
    source.readyState = FakeRelaySource.CLOSED;
    source.onerror();
    await flush();
    assert.equal(calls.settled.rejected, 'A fuse ended the run');
    assert.equal(calls.failures.length, 1);
});

test('a failure after a question, in the same pass, is ignored because the pass already ended', async() => {
    const {source, calls} = startPass();
    source.emitMessage(QUESTION);
    source.emitMessage({type: 'failed', message: 'late'});
    await flush();
    assert.equal(calls.settled.resolved.outcome, 'question');
    assert.deepEqual(calls.failures, []);
});

test('an error while the relay is still connecting is ignored', async() => {
    const {source, calls} = startPass();
    source.emitError();
    await flush();
    assert.equal(calls.settled, null);
    assert.deepEqual(calls.failures, []);
});

test('an error once the connection was open fails the pass', async() => {
    const {source, calls} = startPass();
    source.emitMessage({type: 'tool_call'});
    source.emitError();
    await flush();
    assert.equal(calls.settled.rejected, 'local_coursegen:template_agent_error_connection:undefined');
});

test('a message that is not JSON is ignored and the pass goes on', async() => {
    const {source, calls} = startPass();
    source.fire('message', {data: 'not json'});
    source.emitMessage(QUESTION);
    await flush();
    assert.equal(calls.settled.resolved.outcome, 'question');
});
