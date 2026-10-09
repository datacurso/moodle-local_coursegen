// The top bar of the template flow: the activities pill and its panel, and the controls that moved up from the right column.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

import {
    closeActivitiesPanel,
    initTopBar,
    toggleActivitiesPanel,
} from '../../amd/src/local/courseai/template/top_bar.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (path) => readFileSync(root + path, 'utf8');

const makeToggle = () => {
    const toggle = {
        attributes: {'aria-expanded': 'false'},
        focused: 0,
        setAttribute(name, value) {
            toggle.attributes[name] = value;
        },
        focus() {
            toggle.focused += 1;
        },
        addEventListener: (name, handler) => {
            toggle.handlers[name] = handler;
        },
        handlers: {},
        contains: (node) => node === toggle,
    };
    return toggle;
};

const makePanel = (inside = []) => ({hidden: true, contains: (node) => inside.includes(node)});

let toggle = null;
let panel = null;
let listeners = {};

const installPage = ({withPanel = true} = {}) => {
    toggle = makeToggle();
    panel = makePanel();
    listeners = {};
    const nodes = {tplTopActivitiesToggle: toggle};
    if (withPanel) {
        nodes.tplTopActivitiesPanel = panel;
    }
    globalThis.document = {
        getElementById: (id) => nodes[id] || null,
        addEventListener: (name, handler) => {
            listeners[name] = handler;
        },
    };
};

beforeEach(() => installPage());

test('the panel starts closed and the first press opens it', () => {
    toggleActivitiesPanel();
    assert.equal(panel.hidden, false);
    assert.equal(toggle.attributes['aria-expanded'], 'true');
});

test('a second press closes it again', () => {
    toggleActivitiesPanel();
    toggleActivitiesPanel();
    assert.equal(panel.hidden, true);
    assert.equal(toggle.attributes['aria-expanded'], 'false');
});

test('closing an already closed panel changes nothing', () => {
    closeActivitiesPanel();
    assert.equal(panel.hidden, true);
    assert.equal(toggle.attributes['aria-expanded'], 'false');
});

test('a page without the pill is left alone, whichever way the panel is driven', () => {
    globalThis.document = {getElementById: () => null, addEventListener: () => undefined};
    assert.doesNotThrow(() => toggleActivitiesPanel());
    assert.doesNotThrow(() => closeActivitiesPanel());
});

test('a pill without its panel is left alone', () => {
    installPage({withPanel: false});
    assert.doesNotThrow(() => toggleActivitiesPanel());
    assert.equal(toggle.attributes['aria-expanded'], 'false');
});

test('a press anywhere outside the pill and the panel closes the panel', () => {
    initTopBar();
    toggleActivitiesPanel();
    listeners.click({target: {}});
    assert.equal(panel.hidden, true);
});

test('a press inside the panel keeps it open', () => {
    const row = {};
    panel = makePanel([row]);
    globalThis.document.getElementById = (id) => ({tplTopActivitiesToggle: toggle, tplTopActivitiesPanel: panel}[id] || null);
    initTopBar();
    toggleActivitiesPanel();
    listeners.click({target: row});
    assert.equal(panel.hidden, false);
});

test('a press on the pill itself is left to its own button, not closed by the outside rule', () => {
    initTopBar();
    toggleActivitiesPanel();
    listeners.click({target: toggle});
    assert.equal(panel.hidden, false);
});

test('Escape closes an open panel and gives the focus back to the pill', () => {
    initTopBar();
    toggleActivitiesPanel();
    listeners.keydown({key: 'Escape'});
    assert.equal(panel.hidden, true);
    assert.equal(toggle.focused, 1);
});

test('Escape with the panel already closed does not steal the focus', () => {
    initTopBar();
    listeners.keydown({key: 'Escape'});
    assert.equal(toggle.focused, 0);
});

test('another key leaves the panel open', () => {
    initTopBar();
    toggleActivitiesPanel();
    listeners.keydown({key: 'Tab'});
    assert.equal(panel.hidden, false);
});

test('starting the bar wires the pill to the panel', () => {
    initTopBar();
    toggle.handlers.click();
    assert.equal(panel.hidden, false);
    toggle.handlers.click();
    assert.equal(panel.hidden, true);
});

// ── The markup ──────────────────────────────────────────────────────────────

const page = read('templates/courseai_page.mustache');
const topbar = page.slice(page.indexOf('<header class="courseai-topbar"'), page.indexOf('</header>'));
const templateView = page.slice(page.indexOf('id="templateModeView"'));

test('the top bar owns the activities pill, the stats chip and the course preview, for the template mode only', () => {
    for (const id of ['id="courseaiChecklist"', 'id="tplTopActivitiesToggle"', 'id="tplTopActivitiesPanel"',
        'id="courseaiChecklistList"', 'id="courseaiChecklistCount"', 'id="tplModeLimits"', 'id="tplModeStats"',
        'id="tplPreviewCourse"']) {
        const at = topbar.indexOf(id);
        assert.ok(at > -1, id);
        // Inside a template-mode section: the nearest opening comes after the nearest closing before it.
        assert.ok(topbar.lastIndexOf('{{#templatemodeactive}}', at) > topbar.lastIndexOf('{{/templatemodeactive}}', at), id);
    }
});

test('nothing of the moved pieces is left in the columns, so no id is repeated', () => {
    for (const id of ['id="courseaiChecklist"', 'id="tplModeLimits"', 'id="tplModeStats"', 'id="tplPreviewCourse"',
        'id="courseaiChecklistCount"']) {
        assert.equal(templateView.includes(id), false, id);
    }
});

test('the free mode keeps its own checklist and gets nothing of the new bar', () => {
    const freeMode = page.slice(page.indexOf('</header>'), page.indexOf('id="templateModeView"'));
    assert.ok(freeMode.includes('id="courseaiChecklist"'));
    assert.equal(freeMode.includes('tpl-topbar'), false);
});

test('the pill is a button that controls the panel and says whether it is open', () => {
    const button = topbar.slice(topbar.indexOf('id="tplTopActivitiesToggle"') - 200, topbar.indexOf('id="tplTopActivitiesToggle"') + 400);
    assert.match(button, /<button/);
    assert.match(button, /aria-controls="tplTopActivitiesPanel"/);
    assert.match(button, /aria-expanded="false"/);
    assert.match(button, /aria-haspopup="true"/);
});

test('the panel is closed in the markup and the pill with its panel is hidden until a run counts activities', () => {
    assert.match(topbar, /id="tplTopActivitiesPanel"[^>]*hidden/);
    assert.match(topbar, /class="[^"]*\bhidden\b[^"]*" id="courseaiChecklist"|id="courseaiChecklist"[^>]*class="[^"]*\bhidden\b/);
});

test('the preview link keeps its place in the hidden-until-a-run rule', () => {
    assert.match(topbar, /id="tplPreviewCourse"[^>]*hidden/);
});

test('the preview link has a name for a screen when only its icon is visible', () => {
    const at = topbar.indexOf('id="tplPreviewCourse"');
    const link = topbar.slice(at - 100, at + 700);
    assert.match(link, /aria-label=|tpl-topbar-link-text/);
    assert.match(link, /title=/);
});

// ── The styles ──────────────────────────────────────────────────────────────

const css = read('styles/template_top_bar.css');
const selectorsOf = (source) => {
    const bare = source.replace(/\/\*[\s\S]*?\*\//g, '');
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

test('every rule of the top bar belongs to the bar of the template mode', () => {
    const outside = selectorsOf(css).filter((selector) => !selector.includes('.tpl-topbar')
        && !/^\d+%$|^from$|^to$/.test(selector));
    assert.deepEqual(outside, []);
});

test('the top bar styles stay a small file', () => {
    assert.ok(css.split('\n').length <= 400);
});

test('the page loads the top bar styles after the files they build on', () => {
    const source = read('aicoursecreation.php');
    const after = source.indexOf('styles/template_left_cards.css');
    const own = source.indexOf('styles/template_top_bar.css');
    assert.ok(own > after);
});

test('the old progress card of the left column is gone from its styles', () => {
    const cards = read('styles/template_left_cards.css');
    assert.equal(cards.includes('#courseaiChecklist'), false);
    assert.equal(cards.includes('courseai-checklist-item'), false);
});

test('the preview link and the activities pill honour the hidden attribute their scripts use', () => {
    assert.match(css, /\.tpl-topbar-preview\[hidden\]/);
    assert.match(css, /\.tpl-topbar-panel\[hidden\]/);
});

test('nothing turns for a professor who asked for less motion', () => {
    assert.match(css, /prefers-reduced-motion: reduce/);
});
