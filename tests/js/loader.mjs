// Resolves the Moodle AMD module names of this plugin to their source files, and replaces the
// modules that need a browser with stubs, so the logic of the page bootstrap runs under node.
import {pathToFileURL, fileURLToPath} from 'node:url';
import {existsSync} from 'node:fs';

const SRC = fileURLToPath(new URL('../../amd/src/', import.meta.url));
const STUBS = {
    'local_coursegen/local/courseai/ui/subsections-decision': new URL('./stubs/subsections-decision.mjs', import.meta.url),
    'local_coursegen/local/courseai/ui/plan-transcript': new URL('./stubs/plan-transcript.mjs', import.meta.url),
    'local_coursegen/local/courseai/template/generation_checklist': new URL('./stubs/generation-checklist.mjs', import.meta.url),
    'local_coursegen/local/courseai/template/generation_waiting': new URL('./stubs/generation-waiting.mjs', import.meta.url),
    'local_coursegen/local/courseai/template/agent_steps': new URL('./stubs/agent-steps.mjs', import.meta.url),
    'core/str': new URL('./stubs/core-str.mjs', import.meta.url),
    'local_coursegen/local/courseai/stream/relay-source': new URL('./stubs/relay-source.mjs', import.meta.url),
};

export async function resolve(specifier, context, next) {
    if (STUBS[specifier]) {
        return {url: STUBS[specifier].href, shortCircuit: true, format: 'module'};
    }
    if (specifier.startsWith('local_coursegen/')) {
        const file = SRC + specifier.slice('local_coursegen/'.length) + '.js';
        if (existsSync(file)) {
            return {url: pathToFileURL(file).href, shortCircuit: true, format: 'module'};
        }
    }
    return next(specifier, context);
}
