// The card of a decision the AI asks for, at the start or in the middle of a run: options with descriptions, the one
// it recommends, a way to answer in other words, and the activity the question is about.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {askQuestions} from '../../amd/src/local/courseai/template/generation_questions.js';
import {buttons, byText, findAll, makeDocument, makeElement, settle} from './fake-dom.mjs';

let host = null;

beforeEach(() => {
    globalThis.document = makeDocument();
    host = makeElement('cgLog');
});

const T = (key) => `local_coursegen:courseai_template_questions_${key}`;
const VIDEO = {uid: 'u7', name: 'Interactive video · Lesson 7', type: 'h5pactivity'};

const videoQuestion = (extra = {}) => ({
    id: 'video',
    kind: 'single_choice',
    prompt: 'No video of 10 minutes or less was found. What should I do?',
    hint: 'The ones there are last 12 to 41 minutes.',
    scope: VIDEO,
    options: [
        {value: 'longer', label: 'Use a longer one', description: 'The 12 minute video.', recommended: true},
        {value: 'skip', label: 'Leave it without video', description: 'The activity keeps only its text.'},
    ],
    allow_other: true,
    required: true,
    ...extra,
});
const topicsQuestion = (extra = {}) => ({
    id: 'topics',
    kind: 'multi_choice',
    prompt: 'Which topics should it cover?',
    scope: {uid: 'u8', name: 'Quiz · Lesson 8', type: 'quiz'},
    options: [{value: 'a', label: 'Anchoring'}, {value: 'b', label: 'Berthing'}, {value: 'c', label: 'Mooring'}],
    allow_other: true,
    ...extra,
});

const open = async(questions, data = {}, options = {}) => {
    const payload = {type: 'questions', phase: 'generation', request_id: 'r1', can_skip: false, questions, ...data};
    const result = askQuestions(payload, {host, pickFile: async() => null, ...options});
    await settle();
    return {card: host.children[host.children.length - 1], result};
};
const sendButton = (card) => byText(card, T('send')).find((node) => node.tag === 'button');
const inputs = (card, type) => findAll(card, (node) => node.tag === 'input' && node.type === type);
const withClass = (root, name) => findAll(root, (node) => (node.className || '').split(' ').includes(name));
const choose = (input, checked = true) => {
    input.checked = checked;
    input.dispatch('change');
};
const write = (box, value) => {
    box.value = value;
    box.dispatch('input');
};
const otherBox = (card) => withClass(card, 'cg-question-other-text')[0];
const errorOf = (card, id) => withClass(withClass(card, 'cg-question').find((b) => b.dataset.question === id), 'cg-question-error')[0];

test('a question in the middle of a run says the AI needs a decision and names the activity it is about', async() => {
    const {card} = await open([videoQuestion()]);
    assert.match(card.className, /cg-log-entry--turn-ai/);
    assert.match(card.className, /cg-question-card--decision/);
    assert.ok(byText(card, T('title_generation')).length, 'the decision title');
    assert.equal(byText(card, T('title')).length, 0, 'not the title of the start');
    const chips = withClass(card, 'cg-question-scope');
    assert.equal(chips.length, 1, 'one activity, said once');
    assert.ok(byText(chips[0], VIDEO.name).length);
    assert.ok(withClass(card, 'cg-question-head')[0].children.includes(chips[0]), 'in the header of the card');
    assert.ok(byText(card, 'The ones there are last 12 to 41 minutes.').length, 'the hint gives the context');
});

test('the activity is named for assistive technology too', async() => {
    const {card} = await open([videoQuestion()]);
    const chip = withClass(card, 'cg-question-scope')[0];
    assert.ok(byText(chip, T('scope')).some((node) => /sr-only/.test(node.className)));
});

test('questions about different activities name each one in its own block', async() => {
    const {card} = await open([videoQuestion(), topicsQuestion()]);
    const blocks = withClass(card, 'cg-question');
    assert.equal(blocks.length, 2);
    assert.ok(byText(withClass(blocks[0], 'cg-question-scope')[0], VIDEO.name).length);
    assert.ok(byText(withClass(blocks[1], 'cg-question-scope')[0], 'Quiz · Lesson 8').length);
    assert.equal(withClass(withClass(card, 'cg-question-head')[0], 'cg-question-scope').length, 0);
});

test('questions about the same activity name it once, in the header', async() => {
    const {card} = await open([videoQuestion(), topicsQuestion({scope: VIDEO})]);
    assert.equal(withClass(card, 'cg-question-scope').length, 1);
});

test('a question about no activity, or about one with no name, shows no chip', async() => {
    for (const scope of [null, undefined, {uid: 'x', name: ''}]) {
        const {card} = await open([videoQuestion({scope})]);
        assert.equal(withClass(card, 'cg-question-scope').length, 0);
    }
});

test('each option is a row with a bold label, its description, and the badge on the recommended one', async() => {
    const {card} = await open([videoQuestion()]);
    const rows = withClass(card, 'cg-question-option').filter((row) => !/--other/.test(row.className));
    assert.equal(rows.length, 2);
    const label = withClass(rows[0], 'cg-question-option-label')[0];
    assert.equal(label.tag, 'strong');
    assert.equal(label.textContent, 'Use a longer one');
    assert.equal(withClass(rows[0], 'cg-question-option-desc')[0].textContent, 'The 12 minute video.');
    assert.equal(byText(rows[0], T('recommended')).length, 1);
    assert.equal(byText(rows[1], T('recommended')).length, 0);
    assert.ok(byText(rows[1], 'The activity keeps only its text.').length);
});

test('nothing is chosen for the person, not even the recommended option', async() => {
    const {card} = await open([videoQuestion()]);
    assert.ok(inputs(card, 'radio').every((radio) => radio.checked === false));
    assert.equal(sendButton(card).disabled, true);
});

test('each row is a real radio button with its own label, and the rows of one question share a name', async() => {
    const {card} = await open([videoQuestion()]);
    const radios = inputs(card, 'radio');
    assert.equal(radios.length, 3, 'two options and Other');
    assert.equal(new Set(radios.map((radio) => radio.name)).size, 1);
    assert.equal(new Set(radios.map((radio) => radio.id)).size, 3);
    const labels = findAll(card, (node) => node.tag === 'label');
    radios.forEach((radio, index) => assert.equal(labels[index].attrs.for, radio.id));
});

test('the row chosen is marked as chosen, and only that one', async() => {
    const {card} = await open([videoQuestion()]);
    const [longer, skip] = inputs(card, 'radio');
    choose(longer);
    assert.match(longer.parent.className, /cg-question-option--chosen/);
    choose(skip);
    assert.doesNotMatch(longer.parent.className, /cg-question-option--chosen/);
    assert.match(skip.parent.className, /cg-question-option--chosen/);
});

test('an option chosen is sent by its value with the Send answer button', async() => {
    const {card, result} = await open([videoQuestion()]);
    choose(inputs(card, 'radio')[0]);
    assert.equal(sendButton(card).disabled, false);
    sendButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: [{question_id: 'video', value: 'longer'}]});
});

test('Other reveals a box for the answer in the person\'s own words, which is sent as other', async() => {
    const {card, result} = await open([videoQuestion()]);
    const other = inputs(card, 'radio')[2];
    assert.ok(byText(other.parent, T('other')).length, 'the row says Other');
    const box = otherBox(card);
    assert.equal(box.parent.hidden, true, 'hidden until Other is chosen');
    assert.equal(box.attrs.placeholder, T('other_placeholder'));
    assert.equal(box.maxLength, 2000);

    choose(other);
    assert.equal(box.parent.hidden, false);
    write(box, '  https://example.com/video  ');
    sendButton(card).click();
    assert.deepEqual(await result, {
        skipped: false,
        answers: [{question_id: 'video', value: {other: 'https://example.com/video'}}],
    });
});

test('Other with nothing written blocks the answer and says why, next to the question', async() => {
    const {card} = await open([videoQuestion({required: false})]);
    choose(inputs(card, 'radio')[2]);
    const error = errorOf(card, 'video');
    assert.equal(sendButton(card).disabled, true);
    assert.equal(error.hidden, false);
    assert.equal(error.textContent, T('other_empty'));
    assert.equal(error.attrs['aria-live'], 'polite');

    write(otherBox(card), '   ');
    assert.equal(sendButton(card).disabled, true, 'blank is nothing written');
    write(otherBox(card), 'a shorter cut');
    assert.equal(sendButton(card).disabled, false);
    assert.equal(error.hidden, true);
});

test('going back from Other to an option hides the box and sends the option', async() => {
    const {card, result} = await open([videoQuestion()]);
    const [, skip, other] = inputs(card, 'radio');
    choose(other);
    write(otherBox(card), 'something');
    choose(skip);
    assert.equal(otherBox(card).parent.hidden, true);
    assert.equal(errorOf(card, 'video').hidden, true);
    sendButton(card).click();
    assert.deepEqual((await result).answers, [{question_id: 'video', value: 'skip'}]);
});

test('several choices with Other send the options in their order and the other answer last', async() => {
    const {card, result} = await open([topicsQuestion()]);
    const [anchoring, , mooring, other] = inputs(card, 'checkbox');
    choose(other);
    write(otherBox(card), 'Towing');
    choose(mooring);
    choose(anchoring);
    sendButton(card).click();
    assert.deepEqual((await result).answers, [{question_id: 'topics', value: ['a', 'c', {other: 'Towing'}]}]);
});

test('several choices with only Other send only the other answer', async() => {
    const {card, result} = await open([topicsQuestion()]);
    choose(inputs(card, 'checkbox')[3]);
    write(otherBox(card), 'Towing');
    sendButton(card).click();
    assert.deepEqual((await result).answers, [{question_id: 'topics', value: [{other: 'Towing'}]}]);
});

test('several choices with Other ticked and nothing written are blocked, even when not required', async() => {
    const {card} = await open([topicsQuestion()]);
    const [anchoring, , , other] = inputs(card, 'checkbox');
    choose(anchoring);
    choose(other);
    assert.equal(sendButton(card).disabled, true);
    assert.equal(errorOf(card, 'topics').hidden, false);
    choose(other, false);
    assert.equal(sendButton(card).disabled, false);
    assert.equal(otherBox(card).parent.hidden, true);
});

test('unticking every box, Other included, leaves the question unanswered', async() => {
    const {card, result} = await open([topicsQuestion()]);
    const other = inputs(card, 'checkbox')[3];
    choose(other);
    write(otherBox(card), 'x');
    choose(other, false);
    sendButton(card).click();
    assert.deepEqual((await result).answers, []);
});

test('a question without allow_other has no Other row', async() => {
    const {card} = await open([videoQuestion({allow_other: false})]);
    assert.equal(inputs(card, 'radio').length, 2);
    assert.equal(withClass(card, 'cg-question-option--other').length, 0);
    assert.equal(otherBox(card), undefined);
});

test('several questions share one Send answer, which waits for every required one', async() => {
    const {card, result} = await open([videoQuestion(), topicsQuestion({required: true})]);
    assert.equal(buttons(card).filter((b) => b.textContent === T('send')).length, 1);
    const send = sendButton(card);
    const note = withClass(card, 'cg-question-required')[0];
    assert.equal(send.disabled, true);
    assert.equal(note.hidden, false);
    assert.equal(note.textContent, T('required'));

    choose(inputs(card, 'radio')[1]);
    assert.equal(send.disabled, true);
    choose(inputs(card, 'checkbox')[1]);
    assert.equal(send.disabled, false);
    assert.equal(note.hidden, true);
    send.click();
    assert.deepEqual((await result).answers, [
        {question_id: 'video', value: 'skip'},
        {question_id: 'topics', value: ['b']},
    ]);
});

test('a decision that cannot be skipped has no skip button', async() => {
    const {card} = await open([videoQuestion()]);
    assert.equal(byText(card, T('skip')).length, 0);
});

test('a decision that can be skipped keeps the way to go on without answering', async() => {
    const {card, result} = await open([videoQuestion({required: false})], {can_skip: true});
    byText(card, T('skip')).find((node) => node.tag === 'button').click();
    assert.deepEqual(await result, {skipped: true, answers: []});
});

test('questions asked at the start in the new shape keep the start title and get the new rows', async() => {
    const {card} = await open([videoQuestion({scope: null})], {phase: 'start'});
    assert.ok(byText(card, T('title')).length);
    assert.equal(byText(card, T('title_generation')).length, 0);
    assert.ok(byText(card, T('continue')).length, 'the start goes on with Continue');
    assert.equal(byText(card, T('recommended')).length, 1);
});

test('the old shape, with no phase, is drawn as before: no badge, no chip, no Other, no decision look', async() => {
    const old = videoQuestion();
    delete old.scope;
    delete old.allow_other;
    old.options = old.options.map(({value, label, description}) => ({value, label, description}));
    const {card} = await open([old], {phase: undefined, request_id: undefined, can_skip: true});
    assert.doesNotMatch(card.className, /cg-question-card--decision/);
    assert.ok(byText(card, T('title')).length);
    assert.equal(byText(card, T('recommended')).length, 0);
    assert.equal(withClass(card, 'cg-question-option').length, 0);
    assert.equal(withClass(card, 'cg-question-head').length, 0);
    assert.equal(inputs(card, 'radio').length, 2, 'no Other row');
    const label = findAll(card, (node) => node.tag === 'label')[0];
    assert.equal(label.textContent, 'Use a longer one');
    assert.match(label.parent.className, /form-check mb-2/);
});

test('a question with a default option still starts with it chosen', async() => {
    const {card} = await open([videoQuestion({default: 'skip'})]);
    const [, skip] = inputs(card, 'radio');
    assert.equal(skip.checked, true);
    assert.match(skip.parent.className, /cg-question-option--chosen/);
    assert.equal(sendButton(card).disabled, false);
});

test('a question in the new shape without a phase still gets its Other row and its badge', async() => {
    const {card} = await open([videoQuestion({scope: undefined})], {phase: undefined});
    assert.equal(inputs(card, 'radio').length, 3);
    assert.equal(byText(card, T('recommended')).length, 1);
    assert.ok(byText(card, T('title')).length, 'without a phase it reads as the start');
});
