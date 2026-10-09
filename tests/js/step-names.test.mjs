// The steps of the feed name the activity they are about, and the question card says what is missing and for which activity.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import * as Agent from '../../amd/src/local/courseai/template/agent_events.js';
import {turn, resetThread} from '../../amd/src/local/courseai/template/thread.js';
import {drawn, reset as resetDrawn} from './stubs/log.mjs';
import {makeFeed} from './support/fake-feed.mjs';

const NAMED_TOOLS = ['get_activity', 'modify_activity', 'attach_file', 'create_file_for_activity', 'set_link', 'ask_user'];

test('a call about a named activity uses the named label with the name as its argument', () => {
    for (const name of NAMED_TOOLS) {
        const label = Agent.stepLabel({name, activity_name: 'Weekly guide'});
        assert.equal(label.key, `template_agent_tool_${name}_named`, name);
        assert.equal(label.argument, 'Weekly guide', name);
    }
});

test('a call about no named activity uses the plain label and no argument', () => {
    for (const name of NAMED_TOOLS) {
        for (const activityName of [undefined, null, '', '   ', 7, {}, []]) {
            const label = Agent.stepLabel({name, activity_name: activityName});
            assert.equal(label.key, `template_agent_tool_${name}`, `${name} ${String(activityName)}`);
            assert.equal(label.argument, undefined, name);
        }
    }
});

test('a tool that is not about an activity ignores a name it carries', () => {
    for (const name of ['list_template', 'get_draft', 'create_section', 'finish']) {
        const label = Agent.stepLabel({name, activity_name: 'Weekly guide'});
        assert.equal(label.key, `template_agent_tool_${name}`, name);
        assert.equal(label.argument, undefined, name);
    }
});

test('an unknown tool has the generic label even when it carries a name', () => {
    assert.equal(Agent.stepLabel({name: 'something_new', activity_name: 'X'}).key, 'template_agent_tool_generic');
    assert.equal(Agent.stepLabel({name: '__proto__', activity_name: 'X'}).key, 'template_agent_tool_generic');
    assert.equal(Agent.stepLabel({}).key, 'template_agent_tool_generic');
    assert.equal(Agent.stepLabel(null).key, 'template_agent_tool_generic');
});

test('the name is trimmed and a very long one is cut with an ellipsis', () => {
    assert.equal(Agent.stepLabel({name: 'get_activity', activity_name: '  Guide  '}).argument, 'Guide');
    const long = 'A'.repeat(200);
    const label = Agent.stepLabel({name: 'get_activity', activity_name: long});
    assert.equal(label.argument.length, Agent.MAX_NAME_CHARS);
    assert.ok(label.argument.endsWith('…'));
});

test('a name with markup stays text: the label never turns it into elements', () => {
    const label = Agent.stepLabel({name: 'get_activity', activity_name: '<img src=x onerror=alert(1)>'});
    assert.equal(label.argument, '<img src=x onerror=alert(1)>');
    assert.equal(typeof label.argument, 'string');
});

test('the card title says a file is missing for a file question and that more information is needed otherwise', () => {
    assert.equal(Agent.questionTitleKey({ask_for_file: true}), 'template_agent_question_title_file');
    assert.equal(Agent.questionTitleKey({ask_for_file: false}), 'template_agent_question_title');
    assert.equal(Agent.questionTitleKey({options: ['a']}), 'template_agent_question_title');
    assert.equal(Agent.questionTitleKey(null), 'template_agent_question_title');
});

test('the card names the activity the question is about, or nothing', () => {
    assert.equal(Agent.questionActivity({activity_name: ' Weekly guide '}), 'Weekly guide');
    assert.equal(Agent.questionActivity({activity_name: null}), '');
    assert.equal(Agent.questionActivity({}), '');
    assert.equal(Agent.questionActivity(null), '');
    assert.equal(Agent.questionActivity({activity_name: 12}), '');
    assert.equal(Agent.questionActivity({activity_name: 'B'.repeat(300)}).length, Agent.MAX_NAME_CHARS);
});

let feed = null;

beforeEach(() => {
    resetDrawn();
    feed = makeFeed();
    globalThis.document = {getElementById: (id) => (id === 'cgLog' ? feed : null)};
    feed.innerHTML = '';
    resetThread();
});

test('the same step on the same activity is one line with a count', () => {
    turn('ai', 'ai', 'Reviewing the activity «Weekly guide»');
    turn('ai', 'ai', 'Reviewing the activity «Weekly guide»');
    assert.equal(drawn.length, 1);
    assert.equal(feed.children[0].dataset.repeat, '2');
});

test('the same step on another activity is a line of its own', () => {
    turn('ai', 'ai', 'Reviewing the activity «Weekly guide»');
    turn('ai', 'ai', 'Reviewing the activity «Guide page»');
    assert.equal(drawn.length, 2);
});
