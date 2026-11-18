import {test} from 'node:test';
import assert from 'node:assert/strict';
import {showReviewWarnings, validStartDate} from '../../amd/src/local/courseai/actions/review-warnings.js';

const fakeContainer = () => {
    const container = {
        hidden: true,
        children: [],
        ownerDocument: {createElement: (tag) => ({tag, textContent: ''})},
        replaceChildren(...nodes) {
            this.children = nodes;
        },
    };
    return container;
};

test('each warning is a line of the notice, which is shown', () => {
    const container = fakeContainer();
    showReviewWarnings(container, ['One', 'Two']);
    assert.equal(container.hidden, false);
    assert.deepEqual(container.children.map((node) => [node.tag, node.textContent]), [['li', 'One'], ['li', 'Two']]);
});

test('without warnings the notice stays hidden and empty', () => {
    const container = fakeContainer();
    container.children = [{tag: 'li', textContent: 'old'}];
    container.hidden = false;
    showReviewWarnings(container, []);
    assert.equal(container.hidden, true);
    assert.deepEqual(container.children, []);
});

test('a missing container or list of warnings is tolerated', () => {
    assert.doesNotThrow(() => showReviewWarnings(null, ['x']));
    const container = fakeContainer();
    showReviewWarnings(container, undefined);
    assert.equal(container.hidden, true);
});

test('only a real day is a start date', () => {
    assert.equal(validStartDate('2027-03-15'), '2027-03-15');
    assert.equal(validStartDate(' 2027-03-15 '), '2027-03-15');
    assert.equal(validStartDate('2027-02-31'), '');
    assert.equal(validStartDate('15/03/2027'), '');
    assert.equal(validStartDate(''), '');
    assert.equal(validStartDate(undefined), '');
});
