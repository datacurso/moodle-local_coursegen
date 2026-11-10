// The card of questions: what it draws for each kind, and what it gives back.
import {test, beforeEach} from 'node:test';
import assert from 'node:assert/strict';

import {askQuestions, summarize} from '../../amd/src/local/courseai/template/generation_questions.js';
import {buttons, byText, findAll, makeDocument, makeElement, settle} from './fake-dom.mjs';

let doc = null;
let host = null;

beforeEach(() => {
    doc = makeDocument();
    globalThis.document = doc;
    host = makeElement('cgLog');
});

const T = (key) => `local_coursegen:courseai_template_questions_${key}`;

const toggleQuestion = (extra = {}) => ({id: 'images', kind: 'toggle', prompt: 'Make pictures?', hint: 'It costs more.', ...extra});
const singleQuestion = () => ({
    id: 'lang',
    kind: 'single_choice',
    prompt: 'Language?',
    options: [{value: 'es', label: 'Spanish', description: 'Latin American'}, {value: 'en', label: 'English'}],
});
const multiQuestion = () => ({
    id: 'units',
    kind: 'multi_choice',
    prompt: 'Units?',
    options: [{value: 'u1', label: 'One'}, {value: 'u2', label: 'Two'}, {value: 'u3', label: 'Three'}],
});
const textQuestion = () => ({id: 'note', kind: 'text', prompt: 'Anything else?'});
const fileQuestion = (extra = {}) => ({id: 'syllabus', kind: 'file', prompt: 'Attach it', ...extra});

const open = async(questions, data = {}, options = {}) => {
    const result = askQuestions({questions, can_skip: true, ...data}, {host, pickFile: async() => null, ...options});
    await settle();
    const card = host.children[host.children.length - 1];
    return {card, result};
};
const continueButton = (card) => byText(card, T('continue')).find((node) => node.tag === 'button');
const skipButton = (card) => byText(card, T('skip')).find((node) => node.tag === 'button');
const inputs = (card, type) => findAll(card, (node) => node.tag === 'input' && node.type === type);

test('the card is one more message of the conversation, with a title and the prompts of the questions', async() => {
    const {card} = await open([toggleQuestion(), singleQuestion()]);
    assert.match(card.className, /cg-log-entry/);
    assert.match(card.className, /cg-log-entry--turn-ai/);
    assert.equal(card.attrs.role, 'group');
    assert.ok(byText(card, T('title')).length);
    assert.ok(byText(card, 'Make pictures?').length);
    assert.ok(byText(card, 'It costs more.').length, 'the hint is shown');
    assert.ok(byText(card, 'Language?').length);
});

test('a switch answers yes or no', async() => {
    const {card, result} = await open([toggleQuestion()]);
    const yes = byText(card, T('yes'))[0];
    const no = byText(card, T('no'))[0];

    yes.click();
    assert.equal(yes.attrs['aria-checked'], 'true');
    assert.equal(no.attrs['aria-checked'], 'false');
    no.click();
    assert.equal(no.attrs['aria-checked'], 'true');

    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: [{question_id: 'images', value: false}]});
});

test('one choice is answered with the option picked', async() => {
    const {card, result} = await open([singleQuestion()]);
    const [spanish, english] = inputs(card, 'radio');
    assert.equal(spanish.value, 'es');
    english.checked = true;
    english.dispatch('change');
    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: [{question_id: 'lang', value: 'en'}]});
    assert.ok(spanish);
});

test('the description of an option is shown next to its label', async() => {
    const {card} = await open([singleQuestion()]);
    assert.ok(byText(card, 'Latin American').length);
});

test('several choices keep the order of the options, whatever order they were ticked in', async() => {
    const {card, result} = await open([multiQuestion()]);
    const [one, two, three] = inputs(card, 'checkbox');
    [three, one].forEach((box) => {
        box.checked = true;
        box.dispatch('change');
    });
    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: [{question_id: 'units', value: ['u1', 'u3']}]});
    assert.ok(two);
});

test('unticking every choice leaves the question unanswered', async() => {
    const {card, result} = await open([multiQuestion()]);
    const [one] = inputs(card, 'checkbox');
    one.checked = true;
    one.dispatch('change');
    one.checked = false;
    one.dispatch('change');
    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: []});
});

test('some text is answered trimmed, and empty text is no answer', async() => {
    const {card, result} = await open([textQuestion()]);
    const box = findAll(card, (node) => node.tag === 'textarea')[0];
    assert.equal(box.maxLength, 2000);
    box.value = '  more detail  ';
    box.dispatch('input');
    box.value = '   ';
    box.dispatch('input');
    box.value = ' final ';
    box.dispatch('input');
    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: [{question_id: 'note', value: 'final'}]});
});

test('a file is picked with the picker, shown by name and can be taken off', async() => {
    const {card, result} = await open([fileQuestion()], {}, {
        pickFile: async() => ({filename: 'guide.pdf', draftitemid: 77}),
    });
    const attach = byText(card, T('attach'))[0];
    const remove = byText(card, T('remove'))[0];
    assert.equal(remove.hidden, true);

    attach.click();
    await settle();
    const name = findAll(card, (node) => /file-name/.test(node.className))[0];
    assert.equal(name.textContent, 'guide.pdf');
    assert.equal(remove.hidden, false);

    remove.click();
    assert.equal(name.textContent, '');

    attach.click();
    await settle();
    continueButton(card).click();
    assert.deepEqual(await result, {
        skipped: false,
        answers: [{question_id: 'syllabus', value: {draftitemid: 77, filename: 'guide.pdf'}}],
    });
});

test('a picker that gives nothing, or no draft item, leaves the question unanswered', async() => {
    for (const picked of [null, {filename: 'x.pdf', draftitemid: 0}, {filename: 'x.pdf'}]) {
        const {card, result} = await open([fileQuestion()], {}, {pickFile: async() => picked});
        byText(card, T('attach'))[0].click();
        await settle();
        continueButton(card).click();
        assert.deepEqual(await result, {skipped: false, answers: []});
    }
});

test('a question with a default starts answered', async() => {
    const {card, result} = await open([toggleQuestion({default: true})]);
    assert.equal(byText(card, T('yes'))[0].attrs['aria-checked'], 'true');
    continueButton(card).click();
    assert.deepEqual((await result).answers, [{question_id: 'images', value: true}]);
});

test('a required question keeps Continue off, says why, and offers no way to skip', async() => {
    const {card, result} = await open([toggleQuestion({required: true})], {can_skip: false});
    const next = continueButton(card);
    assert.equal(next.disabled, true);
    assert.equal(skipButton(card), undefined, 'there is no skip button');
    const note = findAll(card, (node) => /question-required/.test(node.className))[0];
    assert.equal(note.hidden, false);
    assert.equal(note.textContent, T('required'));

    next.click();
    byText(card, T('yes'))[0].click();
    assert.equal(next.disabled, false);
    assert.equal(note.hidden, true);
    next.click();
    assert.deepEqual((await result).answers, [{question_id: 'images', value: true}]);
});

test('Continue on a card with nothing required works with no answers', async() => {
    const {card, result} = await open([toggleQuestion()]);
    assert.equal(continueButton(card).disabled, false);
    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: []});
});

test('skipping goes on without answering, even with answers given', async() => {
    const {card, result} = await open([toggleQuestion(), singleQuestion()]);
    byText(card, T('yes'))[0].click();
    skipButton(card).click();
    assert.deepEqual(await result, {skipped: true, answers: []});
});

test('the card leaves the conversation once it is answered', async() => {
    const {card, result} = await open([toggleQuestion()]);
    assert.equal(host.children.includes(card), true);
    skipButton(card).click();
    await result;
    assert.equal(host.children.includes(card), false);
});

test('the answers come in the order of the questions, not of the clicks', async() => {
    const {card, result} = await open([toggleQuestion(), singleQuestion()]);
    const [spanish] = inputs(card, 'radio');
    spanish.checked = true;
    spanish.dispatch('change');
    byText(card, T('no'))[0].click();
    continueButton(card).click();
    assert.deepEqual((await result).answers.map((a) => a.question_id), ['images', 'lang']);
});

test('a card with no questions is just a way to go on', async() => {
    const {card, result} = await open([]);
    continueButton(card).click();
    assert.deepEqual(await result, {skipped: false, answers: []});
});

test('an unknown kind is asked as text rather than not asked', async() => {
    const {card} = await open([{id: 'odd', kind: 'something_new', prompt: 'Hm?'}]);
    assert.equal(findAll(card, (node) => node.tag === 'textarea').length, 1);
});

test('an answer to every kind at once', async() => {
    const {card, result} = await open(
        [toggleQuestion(), singleQuestion(), multiQuestion(), textQuestion(), fileQuestion()],
        {},
        {pickFile: async() => ({filename: 'g.pdf', draftitemid: 5})}
    );
    byText(card, T('yes'))[0].click();
    const [spanish] = inputs(card, 'radio');
    spanish.checked = true;
    spanish.dispatch('change');
    const [, two] = inputs(card, 'checkbox');
    two.checked = true;
    two.dispatch('change');
    const box = findAll(card, (node) => node.tag === 'textarea')[0];
    box.value = 'ok';
    box.dispatch('input');
    byText(card, T('attach'))[0].click();
    await settle();
    continueButton(card).click();
    assert.deepEqual((await result).answers, [
        {question_id: 'images', value: true},
        {question_id: 'lang', value: 'es'},
        {question_id: 'units', value: ['u2']},
        {question_id: 'note', value: 'ok'},
        {question_id: 'syllabus', value: {draftitemid: 5, filename: 'g.pdf'}},
    ]);
    assert.ok(buttons(card).length >= 5);
});

const text = {yes: 'Yes', no: 'No', skipped: 'No answers'};

test('the summary of the answers reads in the words of the questions and the options', () => {
    const questions = [toggleQuestion(), singleQuestion(), multiQuestion(), textQuestion(), fileQuestion()];
    const reply = {
        skipped: false,
        answers: [
            {question_id: 'images', value: true},
            {question_id: 'lang', value: 'es'},
            {question_id: 'units', value: ['u1', 'u3']},
            {question_id: 'note', value: 'be brief'},
            {question_id: 'syllabus', value: {draftitemid: 1, filename: 'guide.pdf'}},
        ],
    };
    assert.equal(
        summarize(questions, reply, text),
        'Make pictures? Yes\nLanguage? Spanish\nUnits? One, Three\nAnything else? be brief\nAttach it guide.pdf'
    );
});

test('the summary of a toggle that was turned down says no', () => {
    assert.equal(summarize([toggleQuestion()], {skipped: false, answers: [{question_id: 'images', value: false}]}, text),
        'Make pictures? No');
});

test('the summary of nothing answered, or of a skip, says so', () => {
    assert.equal(summarize([toggleQuestion()], {skipped: true, answers: []}, text), 'No answers');
    assert.equal(summarize([toggleQuestion()], {skipped: false, answers: []}, text), 'No answers');
});

test('asking again replaces the card that was left on the page', async() => {
    const first = await open([toggleQuestion()]);
    const second = await open([singleQuestion()]);
    assert.notEqual(first.card, second.card);
    assert.ok(second.card);
});
