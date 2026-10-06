// Tests of the pure helpers that read the events of the template agent: no browser needed.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import * as Agent from 'local_coursegen/local/courseai/template/agent_events';

test('the row of an activity of the template is its course module id', () => {
    assert.equal(Agent.rowUid('t:11342'), '11342');
    assert.equal(Agent.rowUid('t:7'), '7');
});

test('an activity the run created has no row', () => {
    assert.equal(Agent.rowUid('n:c3:0'), '');
    assert.equal(Agent.rowUid('s:2'), '');
});

test('an id that is missing or not text has no row', () => {
    for (const value of [undefined, null, 0, 12, {}, [], true, '', 't:', 't:abc', 'x:11342', 't:11342:1']) {
        assert.equal(Agent.rowUid(value), '', String(value));
    }
});

test('an event with an aid of the template gets the uid of its row and keeps its title as the name', () => {
    const event = Agent.normalizeEvent({type: 'activity_progress_start', aid: 't:11342', title: 'Guide', modname: 'page'});
    assert.equal(event.uid, '11342');
    assert.equal(event.name, 'Guide');
});

test('an event that has a uid already keeps it', () => {
    const event = Agent.normalizeEvent({type: 'activity_progress_done', aid: 't:1', uid: '99'});
    assert.equal(event.uid, '99');
});

test('normalizing never changes the event it is given', () => {
    const original = {type: 'activity_progress_start', aid: 't:5', title: 'A'};
    Agent.normalizeEvent(original);
    assert.deepEqual(original, {type: 'activity_progress_start', aid: 't:5', title: 'A'});
});

test('an event that is not an object is returned as an empty one', () => {
    for (const value of [null, undefined, 'x', 3, []]) {
        assert.deepEqual(Agent.normalizeEvent(value), {}, String(value));
    }
});

test('a known tool has its own label and an unknown one has the generic label', () => {
    assert.equal(Agent.toolLabelKey('modify_activity'), 'template_agent_tool_modify_activity');
    assert.equal(Agent.toolLabelKey('ask_user'), 'template_agent_tool_ask_user');
    assert.equal(Agent.toolLabelKey('something_new'), 'template_agent_tool_generic');
    assert.equal(Agent.toolLabelKey(undefined), 'template_agent_tool_generic');
    assert.equal(Agent.toolLabelKey('__proto__'), 'template_agent_tool_generic');
});

test('a question asking for a file is a file question, even with options', () => {
    assert.equal(Agent.questionKind({ask_for_file: true, options: ['a']}), 'file');
});

test('a question with options is a choice and any other is a text', () => {
    assert.equal(Agent.questionKind({options: ['Unit 1', 'Unit 2']}), 'choice');
    assert.equal(Agent.questionKind({options: []}), 'text');
    assert.equal(Agent.questionKind({}), 'text');
    assert.equal(Agent.questionKind(null), 'text');
    assert.equal(Agent.questionKind({options: 'x'}), 'text');
});

test('the options of a question are the non-empty texts, trimmed, each once', () => {
    assert.deepEqual(Agent.questionOptions({options: [' A ', 'B', '', 'A', 3, null, 'B ']}), ['A', 'B']);
    assert.deepEqual(Agent.questionOptions({}), []);
    assert.deepEqual(Agent.questionOptions(null), []);
});

test('the same tool call or question is accepted once and a different one is accepted too', () => {
    const seen = Agent.createSeen();
    assert.equal(seen.accept({type: 'tool_call', call_id: 'c1'}), true);
    assert.equal(seen.accept({type: 'tool_call', call_id: 'c1'}), false);
    assert.equal(seen.accept({type: 'tool_result', call_id: 'c1'}), true);
    assert.equal(seen.accept({type: 'tool_result', call_id: 'c1'}), false);
    assert.equal(seen.accept({type: 'tool_call', call_id: 'c2'}), true);
    assert.equal(seen.accept({type: 'question', call_id: 'c4'}), true);
    assert.equal(seen.accept({type: 'question', call_id: 'c4'}), false);
});

test('progress events of an activity are accepted once per kind', () => {
    const seen = Agent.createSeen();
    assert.equal(seen.accept({type: 'activity_progress_start', aid: 't:1'}), true);
    assert.equal(seen.accept({type: 'activity_progress_start', aid: 't:1'}), false);
    assert.equal(seen.accept({type: 'activity_progress_done', aid: 't:1'}), true);
    assert.equal(seen.accept({type: 'activity_progress_done', aid: 't:1'}), false);
});

test('events that have no identity are always accepted', () => {
    const seen = Agent.createSeen();
    assert.equal(seen.accept({type: 'status', message: {}}), true);
    assert.equal(seen.accept({type: 'status', message: {}}), true);
    assert.equal(seen.accept({type: 'token', text: 'a'}), true);
    assert.equal(seen.accept({type: 'completed'}), true);
});

test('a tool event without a call id is always accepted', () => {
    const seen = Agent.createSeen();
    assert.equal(seen.accept({type: 'tool_call'}), true);
    assert.equal(seen.accept({type: 'tool_call'}), true);
});

test('a value that is not an event is not accepted', () => {
    const seen = Agent.createSeen();
    for (const value of [null, undefined, 4, 'x', []]) {
        assert.equal(seen.accept(value), false, String(value));
    }
});

test('forgetting what was seen accepts the events again', () => {
    const seen = Agent.createSeen();
    seen.accept({type: 'tool_call', call_id: 'c1'});
    seen.reset();
    assert.equal(seen.accept({type: 'tool_call', call_id: 'c1'}), true);
});

test('replaying a long list is cheap and keeps the order of the accepted events', () => {
    const seen = Agent.createSeen();
    const events = [];
    for (let index = 0; index < 5000; index++) {
        events.push({type: 'tool_call', call_id: 'c' + (index % 1000)});
    }
    const accepted = events.filter((event) => seen.accept(event));
    assert.equal(accepted.length, 1000);
    assert.equal(accepted[0].call_id, 'c0');
    assert.equal(accepted[999].call_id, 'c999');
});

test('unicode in names and ids is kept as it is', () => {
    const event = Agent.normalizeEvent({type: 'activity_progress_start', aid: 't:12', title: 'Guía 🙂 didáctica'});
    assert.equal(event.name, 'Guía 🙂 didáctica');
});

test('an id that could break a selector is not used as a row id', () => {
    assert.equal(Agent.safeId('a"]'), '');
    assert.equal(Agent.safeId("x'y"), '');
    assert.equal(Agent.safeId('n:c3:0'), 'n:c3:0');
    assert.equal(Agent.safeId(5), '');
    assert.equal(Agent.normalizeEvent({type: 'activity_progress_start', aid: 'n:c3:0'}).uid, 'n:c3:0');
    assert.equal(Agent.normalizeEvent({type: 'activity_progress_start', aid: 'a"]x'}).uid, '');
    assert.equal(Agent.normalizeEvent({type: 'activity_progress_start', uid: '"><b>'}).uid, '');
});

test('a failure the teacher can try again has the outcome retry', () => {
    assert.equal(Agent.failureOutcome({type: 'failed', retryable: true}), 'retry');
});

test('a failure that is final, or does not say, has the outcome failed', () => {
    for (const value of [{type: 'failed', retryable: false}, {type: 'failed'}, {retryable: 'yes'}, {retryable: 1}, null, undefined, 'x']) {
        assert.equal(Agent.failureOutcome(value), 'failed', String(value));
    }
});
