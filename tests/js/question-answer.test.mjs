// A teacher with no file answers with words, and only a question that asks for a file offers that way out.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {answerWithoutFile, allowsNoFile, canSendAnswer} from 'local_coursegen/local/courseai/template/question_answer';

test('an answer without a file is a text', () => {
    assert.deepEqual(answerWithoutFile('I do not have a file for this.'), {
        kind: 'text',
        answer: {text: 'I do not have a file for this.'},
    });
});

test('the words keep what the teacher reads, without the blanks around them', () => {
    assert.equal(answerWithoutFile('  No tengo un archivo para esto.  ').answer.text, 'No tengo un archivo para esto.');
});

test('the answer is never a file or a choice', () => {
    const answer = answerWithoutFile('x');
    assert.equal(answer.kind, 'text');
    assert.equal('draftItemId' in answer.answer, false);
    assert.equal('choice' in answer.answer, false);
});

test('only a question that asks for a file offers to say there is none', () => {
    assert.equal(allowsNoFile('file'), true);
    assert.equal(allowsNoFile('text'), false);
    assert.equal(allowsNoFile('choice'), false);
    assert.equal(allowsNoFile(undefined), false);
});

test('a file question can be sent only once a file was picked', () => {
    assert.equal(canSendAnswer('file', {draftItemId: 0}), false);
    assert.equal(canSendAnswer('file', {draftItemId: 4120}), true);
    assert.equal(canSendAnswer('file', {}), false);
});

test('a text question can be sent only with words in it, not blanks', () => {
    assert.equal(canSendAnswer('text', {text: ''}), false);
    assert.equal(canSendAnswer('text', {text: '   \n '}), false);
    assert.equal(canSendAnswer('text', {text: 'Twelve weeks'}), true);
});

test('a choice question can be sent only with an option chosen', () => {
    assert.equal(canSendAnswer('choice', {choice: ''}), false);
    assert.equal(canSendAnswer('choice', {choice: 'Beginner'}), true);
});

test('an unknown kind or a missing answer can never be sent', () => {
    assert.equal(canSendAnswer('other', {text: 'x'}), false);
    assert.equal(canSendAnswer('text', undefined), false);
    assert.equal(canSendAnswer(undefined, undefined), false);
});
