import assert from 'node:assert/strict';
import test from 'node:test';
import {showSyllabusChip} from '../../amd/src/courseai/bootstrap/resume-syllabus-chip.js';

const element = (hidden = false) => {
    const classes = new Set();
    if (hidden) {
        classes.add('hidden');
    }
    return {
        textContent: '',
        style: {display: 'none'},
        classList: {remove: (name) => classes.delete(name), contains: (name) => classes.has(name)},
    };
};

const page = () => {
    const nodes = {
        chipSyllabus: element(true),
        chipSyllabusName: element(),
        chipsRow: element(),
        compactChipSyllabus: element(true),
        compactChipSyllabusName: element(),
        compactChipsRow: element(),
    };
    return {nodes, root: {getElementById: (id) => nodes[id] || null}};
};

test('the chip shows the file name and the row that holds it', () => {
    const {nodes, root} = page();
    showSyllabusChip(root, 'Marketing syllabus.pdf');
    assert.equal(nodes.compactChipSyllabusName.textContent, 'Marketing syllabus.pdf');
    assert.equal(nodes.compactChipSyllabus.classList.contains('hidden'), false);
    assert.equal(nodes.compactChipsRow.style.display, 'flex');
});

test('the chips of the start form are shown too, because the chat input copies its own from them', () => {
    const {nodes, root} = page();
    showSyllabusChip(root, 'Marketing syllabus.pdf');
    assert.equal(nodes.chipSyllabusName.textContent, 'Marketing syllabus.pdf');
    assert.equal(nodes.chipSyllabus.classList.contains('hidden'), false);
    assert.equal(nodes.chipsRow.style.display, 'flex');
});

test('an empty name leaves the chip hidden', () => {
    const {nodes, root} = page();
    showSyllabusChip(root, '');
    assert.equal(nodes.compactChipSyllabus.classList.contains('hidden'), true);
    assert.equal(nodes.compactChipsRow.style.display, 'none');
});

test('a missing name is treated as no syllabus', () => {
    const {nodes, root} = page();
    showSyllabusChip(root, undefined);
    assert.equal(nodes.compactChipSyllabus.classList.contains('hidden'), true);
});

test('a page without the chip elements does not fail', () => {
    assert.doesNotThrow(() => showSyllabusChip({getElementById: () => null}, 'a.pdf'));
});

test('the name of a syllabus is shown as text, never as markup', () => {
    const {nodes, root} = page();
    showSyllabusChip(root, '<img src=x onerror=alert(1)>.pdf');
    assert.equal(nodes.compactChipSyllabusName.textContent, '<img src=x onerror=alert(1)>.pdf');
    assert.equal(nodes.compactChipSyllabusName.innerHTML, undefined);
});
