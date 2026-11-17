// The sections and activities the service adds after the template's own are shown and counted.
import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    mergeAddedStructure,
    notifyStructure,
    setStructureListener,
} from '../../amd/src/local/courseai/template/added_structure.js';
import {
    applyStructureResponse,
    countsForUnits,
    createTemplateState,
} from '../../amd/src/local/courseai/template/state.js';

const loaded = () => {
    const state = createTemplateState();
    applyStructureResponse(state, {
        unitcount: 2,
        maxunits: 50,
        sections: [
            {id: 0, name: 'General', activities: [{id: 'p', name: 'Page', modname: 'page', iconhtml: '<i>p</i>'}]},
            {id: 1, name: 'Unit', activities: [{id: 'q', name: 'Quiz', modname: 'quiz'}]},
        ],
    });
    return state;
};

const event = () => ({
    type: 'template_structure',
    sections: [
        {section: 2, name: 'Unidad 1 - Intro', parent_section: 0, depth: 0},
        {section: 3, name: 'Recursos Educativos', parent_section: 2, depth: 1},
        {section: 4, name: 'Actividades', parent_section: 2, depth: 1},
    ],
    activities: [
        {uid: 'f', name: 'Foro', resource_type: 'forum', section: 2},
        {uid: 'a', name: 'Pagina', resource_type: 'page', section: 3},
        {uid: 'b', name: 'Quiz final', resource_type: 'quiz', section: 4},
        {uid: 'x', name: 'Lost', resource_type: 'page', section: 99},
    ],
});

test('added sections follow the template ones, with their depth and name', () => {
    const state = loaded();
    mergeAddedStructure(state, event());
    assert.deepEqual(state.sections.map((s) => [s.name, s.depth]), [
        ['General', 0], ['Unit', 0], ['Unidad 1 - Intro', 0], ['Recursos Educativos', 1], ['Actividades', 1],
    ]);
    assert.equal(state.sections[2].added, true);
    assert.equal(state.sections[0].added, undefined);
});

test('activities are grouped under their section and labelled as written by the AI', () => {
    const state = loaded();
    mergeAddedStructure(state, event());
    assert.deepEqual(state.sections[2].activities.map((a) => a.generationuid), ['f']);
    assert.deepEqual(state.sections[3].activities.map((a) => a.name), ['Pagina']);
    const forum = state.sections[2].activities[0];
    assert.equal(forum.aigenerated, true);
    assert.equal(forum.isinstance, true);
    assert.equal(forum.modname, 'forum');
    assert.equal(state.sections.flatMap((s) => s.activities).some((a) => a.name === 'Lost'), false);
});

test('an added activity reuses the icon of a template activity of the same type', () => {
    const state = loaded();
    mergeAddedStructure(state, event());
    assert.equal(state.sections[3].activities[0].iconhtml, '<i>p</i>');
    assert.equal(state.sections[2].activities[0].iconhtml, '');
});

test('the counters include the added sections and activities and ignore the units resize', () => {
    const state = loaded();
    assert.deepEqual(countsForUnits(state), {sections: 2, activities: 2});
    mergeAddedStructure(state, event());
    assert.deepEqual(countsForUnits(state), {sections: 5, activities: 5});
});

test('merging again replaces what the first merge added', () => {
    const state = loaded();
    mergeAddedStructure(state, event());
    mergeAddedStructure(state, event());
    assert.equal(state.sections.length, 5);
});

test('an empty or missing event adds nothing and keeps an ordinary template as it was', () => {
    const state = loaded();
    mergeAddedStructure(state, {});
    mergeAddedStructure(state, null);
    assert.equal(state.sections.length, 2);
    assert.equal(state.addedSections, false);
    assert.deepEqual(countsForUnits(state), {sections: 2, activities: 2});
});

test('picking another template forgets the added sections', () => {
    const state = loaded();
    mergeAddedStructure(state, event());
    applyStructureResponse(state, {sections: []});
    assert.equal(state.addedSections, false);
});

test('the listener receives the event, and nothing breaks without one', async() => {
    const seen = [];
    setStructureListener(async(data) => seen.push(data.type));
    await notifyStructure(event());
    setStructureListener(null);
    await notifyStructure(event());
    assert.deepEqual(seen, ['template_structure']);
});
