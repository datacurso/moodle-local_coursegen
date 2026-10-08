// The question card of a template run speaks the visual language of the rest of the page: it reuses the classes of the
// decision card, its buttons, its option rows and its file chip, and the progress list never repeats a line.
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (path) => readFileSync(root + path, 'utf8');
const card = read('templates/template_agent_question.mustache');
const retry = read('templates/template_agent_retry.mustache');

const stringOf = (path, key) => {
    const found = read(path).match(new RegExp(`\\$string\\['${key}'\\] = '([^']*)';`));
    return found[1];
};

const plain = (text) => text.toLowerCase().replace(/[.…\s]+$/, '');

test('the card is the decision card of the page, not a stock bootstrap card', () => {
    assert.match(card, /cg-decision-card/);
    assert.match(card, /cg-decision-title/);
    assert.match(card, /cg-decision-subtitle|cg-decision-body/);
    assert.doesNotMatch(card, /border-primary/);
    assert.doesNotMatch(card, /class="([^"]*\s)?card[\s"]/);
});

test('the send button is the primary coral one of Accept and it starts disabled', () => {
    assert.match(card, /cg-decision-btn cg-decision-btn--accept[^>]*data-action="local_coursegen\/template-agent\/send-answer"|data-action="local_coursegen\/template-agent\/send-answer"[^>]*cg-decision-btn--accept/s);
    assert.match(card, /data-action="local_coursegen\/template-agent\/send-answer"[^>]*disabled|disabled[^>]*data-action="local_coursegen\/template-agent\/send-answer"/s);
    assert.doesNotMatch(card, /btn-primary/);
});

test('the secondary actions are the outline button of Adjust, never a bare link', () => {
    assert.match(card, /cg-decision-btn cg-decision-btn--adjust[^>]*data-action="local_coursegen\/template-agent\/no-file"|data-action="local_coursegen\/template-agent\/no-file"[^>]*cg-decision-btn--adjust/s);
    assert.match(card, /cg-decision-btn--adjust[^>]*pick-file|pick-file[^>]*cg-decision-btn--adjust/s);
    assert.doesNotMatch(card, /btn-link|btn-secondary/);
});

test('the picked file shows as the chip of the syllabus with a remove action', () => {
    assert.match(card, /chip chip-syllabus/);
    assert.match(card, /chip-name/);
    assert.match(card, /chip-btn/);
    assert.match(card, /data-action="local_coursegen\/template-agent\/remove-file"/);
});

test('the choices are the option rows of the proposals and the text box is theirs too', () => {
    assert.match(card, /plan-proposals-group/);
    assert.match(card, /plan-proposal-card/);
    assert.match(card, /plan-proposal-radio/);
    assert.match(card, /plan-proposal-summary/);
    assert.match(card, /plan-proposal-other-textarea/);
    assert.doesNotMatch(card, /form-check|form-control/);
});

test('every control keeps the hooks the script binds and is reachable with the keyboard', () => {
    assert.match(card, /data-region="file-name"/);
    assert.match(card, /data-region="text-input"/);
    assert.match(card, /data-region="choice-input"/);
    assert.match(card, /data-region="error"/);
    assert.match(card, /role="group"/);
    assert.match(card, /aria-labelledby=/);
    assert.match(card, /aria-live="polite"/);
});

test('the retry card uses the same buttons and not the stock primary one', () => {
    assert.match(retry, /cg-decision-btn--accept/);
    assert.doesNotMatch(retry, /btn-primary/);
});

test('the progress list never opens with the same words as the first step of the agent', () => {
    for (const lang of ['en', 'es']) {
        const file = `lang/${lang}/local_coursegen.php`;
        const opening = plain(stringOf(file, 'courseai_template_log_starting'));
        const step = plain(stringOf(file, 'template_agent_tool_list_template'));
        assert.notEqual(opening, step, lang);
    }
});
