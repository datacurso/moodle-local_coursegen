// The texts of the template mode: both languages carry the same strings and the Spanish register stays neutral.
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const SCOPE = /^(template_agent_|courseai_template_|template(answer|adjust|syllabus|nothingtostart|filesnotapplied))/;
const LINE = /^\$string\['([a-z0-9_]+)'\] = '(.*)';$/;
const PLACEHOLDER = /\{\$a(->[a-z]+)?\}/g;
const INFORMAL_POSSESSIVE = /\btus?\b/i;
const FORMAL_FORMS = /\b(usted|ustedes|seleccione|su curso|sus actividades)\b/i;

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

test('the progress feed lines of the AI are written in the first person', () => {
    const everyString = [...spanish];
    const tools = everyString.filter(([key]) => key.startsWith('template_agent_tool_'));
    assert.ok(tools.length >= 10);
    for (const [key, text] of tools) {
        assert.match(text, /^(Estoy|Tengo)\b/, key);
    }
});
