// Tests of what happens to a row of the template editor when its action changes, without a browser.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {applyRowAction} from 'local_coursegen/local/template/row_action';
import Selectors from 'local_coursegen/local/template/selectors';
import {ACTION, CLASS} from 'local_coursegen/local/template/constants';

// A row with its action select and, when asked, the row that holds its instruction.
const fakeRow = (cmid, {withSelect = true, withInstructionRow = true} = {}) => {
    const select = {value: ACTION.KEEP};
    const instructionRow = {
        hidden: true,
        classList: {
            toggle: (name, force) => {
                assert.equal(name, CLASS.HIDDEN);
                instructionRow.hidden = force;
            },
        },
    };
    const row = {
        dataset: {id: String(cmid)},
        parentElement: {
            querySelector: (selector) => {
                if (withInstructionRow && selector === Selectors.rows.instructionOf(cmid)) {
                    return instructionRow;
                }
                return null;
            },
        },
        querySelector: (selector) => {
            if (withSelect && selector === Selectors.regions.activityActionSelect) {
                return select;
            }
            return null;
        },
    };
    return {row, select, instructionRow};
};

const freshState = () => ({activityAction: {}, activityInstruction: {}});

test('the instruction of an activity is found by its course module id', () => {
    assert.equal(Selectors.rows.instructionOf(42), '[data-for="instruction"][data-id="42"]');
});

test('choosing AI sets the state and the select and shows the instruction', () => {
    const {row, select, instructionRow} = fakeRow(7);
    const state = freshState();

    applyRowAction(row, ACTION.AI, state);

    assert.equal(state.activityAction[7], ACTION.AI);
    assert.equal(select.value, ACTION.AI);
    assert.equal(instructionRow.hidden, false);
});

test('choosing keep hides the instruction', () => {
    const {row, select, instructionRow} = fakeRow(7);
    const state = freshState();
    applyRowAction(row, ACTION.AI, state);

    applyRowAction(row, ACTION.KEEP, state);

    assert.equal(state.activityAction[7], ACTION.KEEP);
    assert.equal(select.value, ACTION.KEEP);
    assert.equal(instructionRow.hidden, true);
});

test('every value that is not "ai" keeps the activity', () => {
    for (const value of ['', 'template', 'space', 'exclude', 'AI', undefined, null, 0, {}]) {
        const {row, instructionRow} = fakeRow(7);
        const state = freshState();

        applyRowAction(row, value, state);

        assert.equal(state.activityAction[7], ACTION.KEEP, String(value));
        assert.equal(instructionRow.hidden, true, String(value));
    }
});

test('the typed instruction stays in memory when the activity goes back to keep', () => {
    const {row} = fakeRow(7);
    const state = freshState();
    state.activityInstruction[7] = 'Write it for beginners';
    applyRowAction(row, ACTION.AI, state);

    applyRowAction(row, ACTION.KEEP, state);
    applyRowAction(row, ACTION.AI, state);

    assert.equal(state.activityInstruction[7], 'Write it for beginners');
});

test('a row without a valid id changes nothing', () => {
    for (const id of ['', 'x', '0', undefined]) {
        const {row, select} = fakeRow(7);
        row.dataset.id = id;
        const state = freshState();

        applyRowAction(row, ACTION.AI, state);

        assert.deepEqual(state.activityAction, {}, String(id));
        assert.equal(select.value, ACTION.KEEP, String(id));
    }
});

test('a row without its select or without its instruction row still updates the state', () => {
    const noSelect = fakeRow(7, {withSelect: false});
    const noInstruction = fakeRow(8, {withInstructionRow: false});
    const state = freshState();

    applyRowAction(noSelect.row, ACTION.AI, state);
    applyRowAction(noInstruction.row, ACTION.AI, state);

    assert.equal(state.activityAction[7], ACTION.AI);
    assert.equal(state.activityAction[8], ACTION.AI);
    assert.equal(noSelect.instructionRow.hidden, false);
    assert.equal(noInstruction.select.value, ACTION.AI);
});

test('five hundred rows change quickly and independently', () => {
    const rows = [];
    for (let cmid = 1; cmid <= 500; cmid++) {
        rows.push(fakeRow(cmid));
    }
    const state = freshState();

    for (const entry of rows) {
        applyRowAction(entry.row, ACTION.AI, state);
    }
    applyRowAction(rows[249].row, ACTION.KEEP, state);

    assert.equal(Object.keys(state.activityAction).length, 500);
    assert.equal(state.activityAction[250], ACTION.KEEP);
    assert.equal(state.activityAction[251], ACTION.AI);
    assert.equal(rows[249].instructionRow.hidden, true);
    assert.equal(rows[250].instructionRow.hidden, false);
});

test('a course module id with extra characters is read as its number', () => {
    const {row} = fakeRow(7);
    row.dataset.id = '7abc';
    const state = freshState();

    applyRowAction(row, ACTION.AI, state);

    assert.equal(state.activityAction[7], ACTION.AI);
});
