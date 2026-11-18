// The guideline popover of the template input bar is drawn by the shared guideline handlers, which render the
// list into a wrapper region: the markup has to offer that wrapper and the picker has to hand it over.
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (path) => readFileSync(root + path, 'utf8');

const REGION = 'guideline-list-template';

test('the template popover wraps its list in a region of its own', () => {
    const page = read('templates/courseai_page.mustache');
    const pattern = new RegExp(`data-region="${REGION}">\\s*<ul class="pop-list" id="tplGuidelineList"`);
    assert.match(page, pattern);
    assert.equal(page.split(`data-region="${REGION}"`).length - 1, 1);
});

test('the region differs from the ones of free mode, so each mode renders into its own list', () => {
    const page = read('templates/courseai_page.mustache');
    assert.ok(page.includes('data-region="guideline-list"'));
    assert.ok(page.includes('data-region="guideline-list-compact"'));
    assert.notEqual(REGION, 'guideline-list');
});

test('the picker gives the shared handlers the wrapper, not the bare list', () => {
    const picker = read('amd/src/local/courseai/template/guideline_picker.js');
    assert.ok(picker.includes(`[data-region="${REGION}"]`));
    assert.ok(picker.includes('elements: {guidelineListWrap: listWrap}'));
    assert.equal(picker.includes('guidelineList: list'), false);
});

test('the shared handlers render nothing without a wrapper, which is why the picker needs one', () => {
    const handlers = read('amd/src/local/courseai/context/guideline.js');
    assert.ok(handlers.includes('const {guidelineListWrap, guidelineListCompactWrap} = elements;'));
    assert.match(handlers, /if \(!guidelineListWrap\) \{\s*return;\s*\}/);
});
