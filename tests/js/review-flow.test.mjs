// The review of a generated template course: the decisions the page takes between the end of the run, the
// preview, the request to adjust and the creation of the course. No browser needed.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import * as Review from 'local_coursegen/local/courseai/template/review_flow';

const COMPLETED = {
    type: 'completed',
    message: 'Course generated',
    result: {
        generated_activities: [
            {uid: '11342', resource_type: 'page', template_behavior: {action: 'modify'}},
            {uid: '11340', resource_type: 'resource', template_behavior: {action: 'modify'}},
        ],
    },
};

test('the activities of the review are the generated ones of the result of the completed event', () => {
    const generated = Review.generatedActivities(COMPLETED);
    assert.deepEqual(generated.map((entry) => entry.uid), ['11342', '11340']);
});

test('a completed event without a result has no activities to review', () => {
    for (const data of [null, undefined, {}, {type: 'completed'}, {result: []}, {result: {generated_activities: 'x'}}]) {
        assert.deepEqual(Review.generatedActivities(data), [], JSON.stringify(data));
    }
});

test('the row of an activity of the template is sent to the service as the draft id of that activity', () => {
    assert.equal(Review.rowAid('11342'), 't:11342');
    assert.equal(Review.rowAid(7), 't:7');
});

test('a row that is not an activity of the template has no draft id', () => {
    for (const value of ['', 'abc', 't:5', null, undefined, '12 3', '-4']) {
        assert.equal(Review.rowAid(value), '', String(value));
    }
});

test('a call id is made of letters and digits only and differs from one request to the next', () => {
    const first = Review.newCallId(() => 0.123456789);
    const second = Review.newCallId(() => 0.987654321);
    assert.match(first, /^[A-Za-z0-9]{8,40}$/);
    assert.match(second, /^[A-Za-z0-9]{8,40}$/);
    assert.notEqual(first, second);
});

test('accepting needs no instruction and sends nothing to the service', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    const outcome = flow.submit({action: 'accept', targetIds: [], instruction: ''});
    assert.equal(outcome.screen, 'accepted');
    assert.equal(outcome.send, undefined);
});

test('a change request without text is refused before it is sent', () => {
    for (const instruction of ['', '   ', '\n\t', null, undefined]) {
        const flow = new Review.ReviewFlow();
        flow.completed(COMPLETED);
        const outcome = flow.submit({action: 'adjust', targetIds: [], instruction});
        assert.equal(outcome.error, 'blank', String(instruction));
        assert.equal(outcome.send, undefined);
    }
});

test('a change request that is too long is refused before it is sent', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    const outcome = flow.submit({action: 'adjust', targetIds: [], instruction: 'x'.repeat(Review.MAX_INSTRUCTION + 1)});
    assert.equal(outcome.error, 'toolong');
    assert.equal(outcome.send, undefined);
});

test('a change request for the whole result is sent trimmed and without an activity', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    const outcome = flow.submit({action: 'adjust', targetIds: [], instruction: '  Make it shorter  '});
    assert.equal(outcome.send.instruction, 'Make it shorter');
    assert.deepEqual(outcome.send.targetIds, []);
    assert.equal(outcome.send.aid, '');
    assert.match(outcome.send.callId, /^[A-Za-z0-9]{8,40}$/);
});

test('a change request from the row of one activity sends the draft id of that activity', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    const outcome = flow.submit({action: 'adjust', targetIds: ['11342'], instruction: 'More examples'});
    assert.deepEqual(outcome.send.targetIds, ['11342']);
    assert.equal(outcome.send.aid, 't:11342');
});

test('a change request that names several activities is sent for the whole result', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    const outcome = flow.submit({action: 'adjust', targetIds: ['11342', '11340'], instruction: 'Rewrite'});
    assert.equal(outcome.send.aid, '');
});

test('a second click while a request is on its way is ignored', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    const first = flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    const second = flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    assert.ok(first.send);
    assert.equal(second.ignored, true);
    assert.equal(second.send, undefined);
});

test('a request the service refused shows the reason, keeps the review and can be sent again', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    const failed = flow.feedbackFailed({message: 'The run already ended'});
    assert.equal(failed.screen, 'review');
    assert.equal(failed.error, 'The run already ended');
    const again = flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    assert.ok(again.send);
});

test('the reason of a refused request falls back to a generic one when the error has no message', () => {
    assert.equal(Review.errorMessage({message: 'Nope'}, 'Fallback'), 'Nope');
    for (const error of [null, undefined, {}, {message: ''}, {message: '  '}, 'text']) {
        assert.equal(Review.errorMessage(error, 'Fallback'), 'Fallback', JSON.stringify(error));
    }
});

test('a request that was stored puts the page in adjusting until the run completes again', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    const stored = flow.feedbackStored();
    assert.equal(stored.screen, 'adjusting');
});

test('when the run completes again the review is shown again with the new activities', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    flow.feedbackStored();
    const refreshed = flow.completed({
        type: 'completed',
        result: {generated_activities: [{uid: '11342', resource_type: 'page'}]},
    });
    assert.equal(refreshed.screen, 'review');
    assert.deepEqual(refreshed.generated.map((entry) => entry.uid), ['11342']);
    const next = flow.submit({action: 'adjust', targetIds: [], instruction: 'Once more'});
    assert.ok(next.send);
});

test('a question that comes while adjusting is shown and, once answered, the page goes on adjusting', () => {
    const flow = new Review.ReviewFlow();
    flow.completed(COMPLETED);
    flow.submit({action: 'adjust', targetIds: [], instruction: 'Change it'});
    flow.feedbackStored();
    assert.equal(flow.question().screen, 'question');
    assert.equal(flow.answered().screen, 'adjusting');
});

test('a page that reloads after the end of the run shows the review', () => {
    const flow = new Review.ReviewFlow();
    const screen = flow.restore({status: 'COMPLETED'}, [{type: 'status'}, COMPLETED]);
    assert.equal(screen.screen, 'review');
    assert.deepEqual(screen.generated.map((entry) => entry.uid), ['11342', '11340']);
});

test('a page that reloads after the end of the run without its event shows the review of nothing', () => {
    const flow = new Review.ReviewFlow();
    const screen = flow.restore({status: 'COMPLETED'}, []);
    assert.equal(screen.screen, 'review');
    assert.deepEqual(screen.generated, []);
});

test('a page that reloads while a change is being made keeps adjusting and not the review of the previous result', () => {
    const flow = new Review.ReviewFlow();
    const events = [COMPLETED, {type: 'tool_call', call_id: 'c9'}];
    const screen = flow.restore({status: 'RUNNING'}, events);
    assert.equal(screen.screen, 'adjusting');
});

test('a page that reloads while the first run goes on is simply running', () => {
    const flow = new Review.ReviewFlow();
    const screen = flow.restore({status: 'RUNNING'}, [{type: 'tool_call', call_id: 'c1'}]);
    assert.equal(screen.screen, 'running');
});

test('a page that reloads with a question pending shows the question', () => {
    const flow = new Review.ReviewFlow();
    assert.equal(flow.restore({status: 'WAITING_USER'}, []).screen, 'question');
    const afterReview = flow.restore({status: 'WAITING_USER'}, [COMPLETED]);
    assert.equal(afterReview.screen, 'question');
});

test('a page that reloads after a failure that can be retried offers the retry and after any other failure ends', () => {
    const flow = new Review.ReviewFlow();
    const retry = flow.restore({status: 'FAILED'}, [{type: 'failed', retryable: true, message: 'x'}]);
    const ended = flow.restore({status: 'FAILED'}, [{type: 'failed', retryable: false, message: 'x'}]);
    const unknown = flow.restore({status: 'FAILED'}, []);
    assert.equal(retry.screen, 'retry');
    assert.equal(ended.screen, 'failed');
    assert.equal(unknown.screen, 'failed');
});

test('a page that reloads with a state it does not know is simply running', () => {
    const flow = new Review.ReviewFlow();
    assert.equal(flow.restore({status: 'SOMETHING'}, []).screen, 'running');
    assert.equal(flow.restore({}, []).screen, 'running');
    assert.equal(flow.restore(null, null).screen, 'running');
});

test('only the activities the AI was asked to change can be adjusted from their row', () => {
    const generated = [
        {uid: '1', template_behavior: {action: 'modify'}},
        {uid: '2', template_behavior: {action: 'keep'}},
        {uid: '3'},
        {uid: '4', template_behavior: null},
        null,
        'text',
    ];
    assert.deepEqual(Review.adjustable(generated).map((entry) => entry.uid), ['1']);
});

test('nothing can be adjusted when the generated activities are not a list', () => {
    for (const value of [null, undefined, {}, 'x', 3]) {
        assert.deepEqual(Review.adjustable(value), [], String(value));
    }
});
