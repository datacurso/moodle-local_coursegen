// The texts of the template mode: both languages carry the same strings and the Spanish register stays neutral.
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const SCOPE = /^(template_agent_|courseai_template_|template(answer|adjust|syllabus|nothingtostart|filesnotapplied))/;
const LINE = /^\$string\['([a-z0-9_]+)'\] = '(.*)';$/;
const PLACEHOLDER = /\{\$a(->[a-z]+)?\}/g;
const INFORMAL_POSSESSIVE = /\btus?\b/i;
const FORMAL_FORMS = /\b(usted|ustedes|seleccione|su|sus)\b/i;
const CHATTY_SPANISH = /\b(estoy|tengo|puedo|pude|quiero|quise|me|mi|mis|te|terminé|creé|encontré|preparé|continúo)\b/i;
const CHATTY_ENGLISH = /\b(I|I'm|me|my|we|us|our)\b/;
// What the AI says in its own voice, like the chat bubbles of the free mode, or what the teacher says: first person is fine.
const OWN_VOICE = new Set([
    'courseai_template_log_review_ready',
    'template_agent_question_nofile_answer',
    'template_agent_question_nofile_button',
]);
const GERUND_SPANISH = /^[A-ZÁÉÍÓÚ][a-záéíóúñ]+(ando|endo|iendo)\b/;
const GERUND_ENGLISH = /^[A-Z][a-z]+ing\b/;
const NAMED_TOOLS = ['get_activity', 'modify_activity', 'attach_file', 'create_file_for_activity', 'set_link', 'ask_user'];

// Read the strings of one language that belong to the template mode.
const readScope = (language) => {
    const path = new URL(`../../lang/${language}/local_coursegen.php`, import.meta.url);
    const strings = new Map();
    for (const line of readFileSync(path, 'utf8').split('\n')) {
        const found = LINE.exec(line);
        if (found && SCOPE.test(found[1])) {
            strings.set(found[1], found[2]);
        }
    }
    return strings;
};

// The placeholders a string interpolates, in a stable order.
const placeholdersOf = (text) => {
    const found = text.match(PLACEHOLDER) || [];
    return found.sort().join(',');
};

const english = readScope('en');
const spanish = readScope('es');

test('both languages carry the same template strings', () => {
    const spanishKeys = [...spanish.keys()].sort();
    const englishKeys = [...english.keys()].sort();
    assert.ok(english.size > 40);
    assert.deepEqual(spanishKeys, englishKeys);
});

test('every string keeps the same placeholders in both languages', () => {
    for (const [key, text] of english) {
        const spanishText = spanish.get(key);
        const spanishPlaceholders = placeholdersOf(spanishText);
        const englishPlaceholders = placeholdersOf(text);
        assert.equal(spanishPlaceholders, englishPlaceholders, key);
    }
});

test('the three connection failure messages exist in both languages', () => {
    const keys = ['template_agent_error_failed', 'template_agent_error_ended', 'template_agent_error_connection'];
    for (const key of keys) {
        assert.ok(english.has(key), key);
        assert.ok(spanish.has(key), key);
    }
});

test('the Spanish texts never use the informal possessive', () => {
    for (const [key, text] of spanish) {
        const informal = INFORMAL_POSSESSIVE.test(text);
        assert.equal(informal, false, `${key}: ${text}`);
    }
});

test('the Spanish texts never address the teacher as usted', () => {
    for (const [key, text] of spanish) {
        const formal = FORMAL_FORMS.test(text);
        assert.equal(formal, false, `${key}: ${text}`);
    }
});

test('no template text is a chatty first person remark, except the chat bubbles and the teacher\'s own words', () => {
    for (const [key, text] of spanish) {
        if (!OWN_VOICE.has(key)) {
            assert.equal(CHATTY_SPANISH.test(text), false, `${key}: ${text}`);
        }
    }
    for (const [key, text] of english) {
        if (!OWN_VOICE.has(key)) {
            assert.equal(CHATTY_ENGLISH.test(text), false, `${key}: ${text}`);
        }
    }
});

test('the progress feed lines are professional statements that start with a gerund', () => {
    const tools = [...spanish].filter(([key]) => key.startsWith('template_agent_tool_'));
    assert.ok(tools.length >= 10);
    for (const [key, text] of tools) {
        assert.match(text, GERUND_SPANISH, key);
    }
    const englishTools = [...english].filter(([key]) => key.startsWith('template_agent_tool_'));
    for (const [key, text] of englishTools) {
        assert.match(text, GERUND_ENGLISH, key);
    }
});

test('every tool that is about an activity has a named line with the name, and a plain line without it', () => {
    for (const tool of NAMED_TOOLS) {
        for (const language of [english, spanish]) {
            const named = language.get(`template_agent_tool_${tool}_named`);
            const plain = language.get(`template_agent_tool_${tool}`);
            assert.ok(named, `${tool} named`);
            assert.ok(plain, `${tool} plain`);
            assert.match(named, /\{\$a\}/, tool);
            assert.doesNotMatch(plain, /\{\$a\}/, tool);
        }
    }
});

test('the named lines put the name between quotation marks', () => {
    for (const tool of NAMED_TOOLS) {
        assert.match(spanish.get(`template_agent_tool_${tool}_named`), /«\{\$a\}»/, tool);
        assert.match(english.get(`template_agent_tool_${tool}_named`), /["“«]\{\$a\}["”»]/, tool);
    }
});

test('the question card says what is missing and for which activity', () => {
    for (const language of [english, spanish]) {
        assert.ok(language.get('template_agent_question_title'));
        assert.ok(language.get('template_agent_question_title_file'));
        assert.notEqual(language.get('template_agent_question_title'), language.get('template_agent_question_title_file'));
        assert.match(language.get('template_agent_question_about'), /\{\$a\}/);
    }
    assert.equal(spanish.get('template_agent_question_title'), 'Se necesita más información');
    assert.equal(spanish.get('template_agent_question_title_file'), 'Falta un archivo');
});

test('the waiting step of the feed is a state, not a chat line', () => {
    assert.equal(spanish.get('template_agent_log_waiting'), 'Esperando información para continuar.');
});
