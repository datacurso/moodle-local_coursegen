import {test} from 'node:test';
import assert from 'node:assert/strict';
import {activityMarkdown, sectionMarkdown} from '../../amd/src/local/courseai/template/section_markdown.js';

const labels = {count: (done, total) => `${done} de ${total}`, includes: (names) => `Incluye: ${names}`};

test('an activity is its title in bold and its type in italics', () => {
    assert.equal(activityMarkdown({title: 'Glosario', type: 'Page'}, labels), '**Glosario** _(Page)_');
});

test('an activity with repeat blocks says how many items are written and which they are', () => {
    const text = activityMarkdown({
        title: 'Glosario',
        type: 'Page',
        repeat: {done: 10, total: 50, last: 'Kappa', names: ['Alfa', 'Beta']},
    }, labels);
    assert.equal(text, '**Glosario** _(Page)_\n\n10 de 50 · Kappa\n\nIncluye: Alfa, Beta');
});

test('a finished block does not name a last item', () => {
    const text = activityMarkdown({title: 'A', type: '', repeat: {done: 3, total: 3, last: 'C', names: []}}, labels);
    assert.equal(text, '**A**\n\n3 de 3');
});

test('a section is its activities one after the other', () => {
    const text = sectionMarkdown([{title: 'A', type: 'Page'}, {title: 'B', type: 'Forum'}], labels);
    assert.equal(text, '**A** _(Page)_\n\n**B** _(Forum)_');
});
