// The left column of the template flow is one system: its styles never leak into the free mode that shares the markup
// of the feed and of the cards, the review reads in the order of the screen, and the page loads every file of the system.
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (path) => readFileSync(root + path, 'utf8');

const STYLE_FILES = [
    'styles/template_left_column.css',
    'styles/template_left_cards.css',
    'styles/template_agent_question.css',
];

/** The selectors of every rule of a stylesheet, without comments, declarations and at-rule preludes. */
const selectorsOf = (css) => {
    const bare = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const selectors = [];
    const rule = /([^{}]+)\{/g;
    let found = rule.exec(bare);
    while (found !== null) {
        const prelude = found[1].trim();
        if (!prelude.startsWith('@') && !/^[a-z0-9%\s]+$/i.test(prelude)) {
            prelude.split(',').forEach((selector) => selectors.push(selector.trim().replace(/\s+/g, ' ')));
        }
        found = rule.exec(bare);
    }
    return selectors;
};

test('every rule of the left column is scoped to the column of the template mode', () => {
    for (const file of STYLE_FILES) {
        const unscoped = selectorsOf(read(file)).filter((selector) => !selector.includes('.tpl-left-panel') && selector !== '[hidden]' && !selector.endsWith('[hidden]'));
        assert.deepEqual(unscoped, [], file);
    }
});

test('the system never touches the page of the free mode', () => {
    for (const file of STYLE_FILES) {
        const css = read(file);
        assert.doesNotMatch(css, /\.courseai-workspace\.is-planning/, file);
        assert.doesNotMatch(css, /#courseaiContextChat/, file);
    }
});

test('no file of the system goes over the size of a file of this plugin', () => {
    for (const file of STYLE_FILES) {
        const lines = read(file).split('\n').length;
        assert.ok(lines <= 400, `${file} has ${lines} lines`);
    }
});

test('the page loads every file of the system, after the files it builds on', () => {
    const page = read('aicoursecreation.php');
    const order = ['aicoursecreation.css', 'chatui.css', 'template_agent_question.css', 'template_left_column.css', 'template_left_cards.css'];
    const positions = order.map((name) => page.indexOf(`styles/${name}`));
    positions.forEach((position, index) => assert.ok(position > -1, order[index]));
    assert.deepEqual([...positions].sort((a, b) => a - b), positions);
});

test('the review of the course reads left to right as the screen does: the secondary action, then the primary one', () => {
    const page = read('templates/courseai_page.mustache');
    const template = page.slice(page.indexOf('id="templateModeView"'));
    const adjust = template.indexOf('id="cgDecisionAdjust"');
    const accept = template.indexOf('id="cgDecisionAccept"');
    assert.ok(adjust > -1 && accept > -1);
    assert.ok(adjust < accept);
});

test('the free mode keeps the order of its own review buttons', () => {
    const page = read('templates/courseai_page.mustache');
    const freeMode = page.slice(0, page.indexOf('id="templateModeView"'));
    assert.ok(freeMode.indexOf('id="cgDecisionAccept"') < freeMode.indexOf('id="cgDecisionAdjust"'));
});

test('the states of the run are classes of the page that the styles read', () => {
    const feed = read('styles/template_left_column.css');
    for (const state of ['cg-generating', 'cg-generation-paused', 'cg-generation-settled']) {
        assert.match(feed, new RegExp(state), state);
    }
});

test('the meter reads the number the script sets and the script sets that same name', () => {
    const cards = read('styles/template_left_cards.css');
    const checklist = read('amd/src/local/courseai/template/generation_checklist.js');
    assert.match(cards, /--cg-progress/);
    assert.match(checklist, /--cg-progress/);
    assert.match(cards, /@property --cg-progress/);
});

test('a teacher who asked for less motion gets no spinner and no pulse', () => {
    const feed = read('styles/template_left_column.css');
    const topBar = read('styles/template_top_bar.css');
    assert.match(feed, /prefers-reduced-motion: reduce/);
    assert.match(topBar, /prefers-reduced-motion: reduce/);
});

test('every control of the cards shows a focus ring', () => {
    const cards = read('styles/template_left_cards.css');
    const question = read('styles/template_agent_question.css');
    assert.match(cards, /\.cg-decision-btn:focus-visible/);
    assert.match(question, /\.cg-ask-pick:focus-visible/);
});

test('the disabled primary button is readable: its text keeps a dark grey on the grey of its fill', () => {
    const cards = read('styles/template_left_cards.css');
    const disabled = cards.slice(cards.indexOf('.cg-decision-btn:disabled'));
    assert.match(disabled, /color: hsl\(215 14% 40%\)/);
    assert.match(disabled, /opacity: 1/);
});
