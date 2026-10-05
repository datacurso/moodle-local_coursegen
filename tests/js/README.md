# Page bootstrap tests

Plain `node:test` tests for the parts of the course page that decide how a reloaded page is drawn.
They need no browser and no Moodle:

    node --import ./tests/js/register.mjs --test tests/js/*.test.mjs

The loader resolves `local_coursegen/...` module names to `amd/src` and stubs the modules that need a
browser.

`template-state.test.mjs` covers the logic of the template editor: the two choices of an activity, the checks before saving, the payload and the detection of unsaved changes.
