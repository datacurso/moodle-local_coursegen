# Course template feature — plan

## Where this analysis comes from

Grounded in three things, not guesswork:

1. Live data pulled directly from the local dev Moodle DB (`moodle-45-mysql-1`) for two real courses already restored into the local sandbox:
   - **Course id 397** — `Plantilla Virtual V2` (shortname `LeilaR`), the base/template course. This is the real SEPI/Universidad Latina "Plantilla de Curso Virtual" (see the `#96b1c6` primary color baked into its section-0 banner label — the exact "ESTÁNDAR BUENÍSIMO" brand color documented in that client's Prompt Maestro).
   - **Course id 398** — `Redacción para Medios de Comunicación I` (shortname `REDMECOM_1`), a real course already built by hand from that template.
2. A full read of the current implementation: `classes/local/service/template_course_builder_service.php`, `classes/local/service/mock_template_ai_service.php`, `classes/local/models/{template,template_section,template_activity}.php`, `classes/external/{save_template,get_template_structure,create_course_from_template}.php`, and the JS driving the config UI (`amd/src/local/template/*.js`, `amd/src/local/courseai/template/*.js`).
3. The SEPI scope document produced earlier this session (`/home/buendata/codding/automation/python/clients_requirements/clients/sepi/course_template/course_template_scope.json`), used only to confirm what a real institutional template needs — never to hardcode SEPI-specific field names into this generic plugin feature.

## Current state — what already works

The pipeline itself is solid and already handles the hard part correctly:

- Section-level `behavior`: `custom` (default container), `keep` (import whole section via backup/restore), `exclude` (drop entirely).
- Activity-level `action`: `modify` (regenerate via AI), `keep` (import as-is), `reference` (never materializes, only feeds AI context), `exclude` (drop).
- `useasreference` flag, independent of `action`, controls whether an activity's own content is folded into the AI reference context for itself.
- `keep` content moves through real `backup_controller`/`restore_controller` (`MODE_IMPORT` + `TARGET_EXISTING_ADDING`), the only supported way to clone `course_modules` cross-course — correctly scoped per-section and per-activity via backup settings.
- New sections/activities the professor adds on top of the template are supported, capped by `maxsections`/`nolimit`, gated by `allowedtypes`.
- Failure handling is genuinely fail-soft: a single activity failing to generate is collected in `activityerrors` and reported back, never aborts the whole course build; an orphaned destination course is cleaned up on hard failure.

None of this needs to be redesigned. The gap is elsewhere.

## The gap, verified against real data

### Gap 1 — the AI service only knows 4 of the 7 module types the real template actually uses

`mock_template_ai_service::SUPPORTED = ['page', 'label', 'forum', 'assign']`.

Course 397's real structure, pulled from `mdl_course_modules` + per-mod tables:

| Section | Activities |
|---|---|
| Section 0 (course-level) | label (banner), page x3 (Sobre este curso, Reglas del curso, Guía Didáctica/Semanal, Glosario), resource x2 (Guía Didáctica/Semanal file, Soporte Técnico), forum (Foro del Docente) |
| Módulo #1–#5 | lesson x3, feedback (Envíanos tu opinión) each — Módulo #3 and #5 also carry an extra page/label |

**`lesson`, `feedback` and `resource` are not in `SUPPORTED`.** Any template that marks a `lesson`, `feedback` or `resource` activity as `action=modify` will fail per-activity (caught, reported in `activityerrors`, never fatal — but the content simply never gets produced). Since `lesson` is the entire content vehicle of this template (15 of them) and `feedback`/`resource` cover the mandatory opinion survey and the syllabus file, this is not an edge case — it is the majority of the template's real content. This has to be closed before the feature is usable on real templates like this one, not just on toy `page`/`label` demos.

`resource` deserves a separate note: it is a **file** module. An AI response can produce HTML/text, not a real uploaded file. "Modify" is very unlikely to ever make sense for `resource` — the realistic options are `keep` (reuse the file) or `reference` (feed its text into siblings), never `modify`. This should be reflected in the config UI (don't offer "modify" for `resource`), not solved by inventing a fake file-generation contract.

### Gap 2 — the only "configuration" that exists today is auto-derived from whatever the base course happens to contain, never a real admin decision

From `amd/src/local/template/init.js`:

```js
state.maxSections = state.courseStructure.length;      // never asked, just counted
state.allowedTypes = [...actTypes];                      // whatever modnames already exist in the base course
state.noLimit = false;                                   // never exposed in the UI at all
```

There is no form control anywhere in `classes/form/` for `maxsections`, `nolimit` or `allowedtypes` — `save_template.php` accepts them, but nothing meaningful ever gets typed into them by a human. This is confirmed as a real, not theoretical, problem by comparing 397 → 398:

- Course 398 has **6 module sections**, course 397 (the template) only has **5**. A whole new "Módulo #6" was added by hand — something the current auto-derived `maxSections` (silently frozen at 5, the template's own count) would never have permitted without someone manually raising it through the API directly.
- Course 398 uses **`assign`** ten times (Examen Parcial I/II, several "Tarea"/"Proyecto Final" activities). Course 397, the template, has **zero** `assign` instances anywhere. Since `allowedTypes` is auto-derived only from modnames already present in the base course, `assign` would **never** appear in the allow-list for this template — the exact activity type the real course needed the most would be silently rejected by `process_new_activities()`'s `allowedtypes` check.

So "muy pobre" is accurate and specific: the knobs a real admin needs — how many extra modules a professor may add, which activity types they may add, whether there's a limit at all — exist as DB columns and as an API contract, but not as a real decision anyone gets to make.

This is compounded by how that decision has to be made today: the config UI (`amd/src/local/template/{step_course,step_limits,step_sections,step_preview,step_summary}.js`, driven by a `stepper.js`) is a 5-step wizard, and the section/activity step requires a decision **per individual activity module**. Course 397 alone has ~28 activities across 6 sections — a real admin would have to make ~28 individual keep/modify/exclude/reference calls, through 5 separate screens, just to configure one template. Phase 1 below redesigns this: instead of one decision per activity, the admin sets a default behavior per **kind** of component (banner, forum, file attachment, graded activity, closing survey, lesson content) — grounded in how each kind is actually used across 397/398 — and only reviews the rare exception, in as close to one screen as possible.

### Gap 5 — the grid/tile course format has its own per-section picture, untouched by anything this feature does today

Both course 397 and 398 use Moodle's `grid` course format (confirmed via `mdl_course.format`), which shows each section as a picture tile on the course's main page — a per-section picture stored in `mdl_format_grid_image` (its own table, keyed by `sectionid`/`courseid`, holding the image file's identity plus its `format_grid`-managed file areas), completely separate from the in-content Label banner activity. Course 397 (the template) has none; course 398 (built by hand) has one real row per section, confirming a real admin fills these in by hand today. `template_course_builder_service.php` already copies the course's `format` to the new course, so the *option that a course uses grid* survives — but nothing produces a picture for a section this feature creates fresh (any module beyond the template's original count).

**First attempt, rejected by the client**: literally duplicating the nearest earlier section's picture file/row for a new section. Shown a real screenshot of this in practice, the client correctly rejected it — these pictures have the module's own name baked into the image itself, so a duplicated picture shows the WRONG module's name/number on the new module (a new "Módulo 6" visibly reading "Módulo 5"), which reads as more broken than showing nothing at all. Client's own words: this must be **AI-generated** — as a simple SVG (not a big photo-style file) — using the existing modules' pictures as its style reference, in one of two ways: (a) the admin uploads their own reference image for the new module(s) to follow, or (b) the admin gives a plain instruction ("keep new modules visually consistent with the existing ones") and the AI derives the shared style directly from the pictures the course already has. This mirrors the design-brief pattern already built for section banners in the Python `course_ai` backend (one shared brief derived once, followed by every generated piece) — the same principle, applied to this picture instead of the in-content banner.

Phase 1 below is about fixing all of this — a real, generic set of decisions, made in as few steps as possible, not hardcoded to this one template.

### Gap 3 — one real-world exam pattern already contradicts an assumption made from client documentation alone

Earlier analysis of SEPI's "Prompt Maestro" (their own 47-page internal AI-authoring prompt) concluded exams should be auto-graded GIFT quiz banks, with "no `mod_assign` anywhere." Course 398's real "Examen Parcial I"/"Examen Parcial II" are **`mod_assign`** instances (professor-graded submissions), not `mod_quiz`. Documenting a client's own prompt is not the same as verifying what actually got built — this plan is built off the DB, not off that assumption. No GIFT/quiz-bank generation should be treated as a hard requirement of this feature; `assign` is squarely in scope and already partially supported.

### Gap 6 — the base-course picker is a custom-built screen of its own, and it broke

The PR 1/2/4 redesign (config-ui-redesign) collapsed the multi-step wizard, but the very first step of it — choosing which course to use as a base — is still a fully custom-built two-panel screen (a category tree on one side, a course list on the other, plus its own search box), not a standard Moodle form field. Confirmed broken in practice: a real `Uncaught TypeError: Cannot read properties of null (reading 'addEventListener')` in `init.js` fires on page load and blocks reaching the course picker at all. Root cause aside, the deeper problem is that this custom browsing screen is its own maintenance surface, when Moodle already ships the exact right building block: the "Category" field on Moodle's own "Edit course settings" page is a standard autocomplete search field, and this same pattern — search field for the category, then a second search field (AJAX, scoped to whatever category was picked) for the course inside it — is what this picker should be instead. Simpler, and it stops reinventing something Moodle already gives for free. Treated as its own Phase 1 task below, on top of (not instead of) the other config-UI simplification already landed.

### Gap 4 — stale/orphaned data in the plugin's own tables

`mdl_local_coursegen_template_section` and `mdl_local_coursegen_template_activity` (11 + 20 rows) are **leftover data from an earlier schema iteration** — the current persistent classes point at `local_coursegen_tpl_section` / `local_coursegen_tpl_activity` (both currently empty), and the old tables' `action` values (`modify_ai`) aren't even valid for the current `PARAM_ALPHA` field (which forbids underscores). They reference cmids from an unrelated "Machine Learning" test course, not 397/398. Harmless today (dead code never reads them), but worth a cleanup upgrade step so a future DB inspection doesn't get confused by them again — noted, not urgent.

No template row exists yet for course 397 at all (`mdl_local_coursegen_template` is empty) — this feature has never actually been exercised against a real, production-shaped template. Everything above was found by reading code + real course data side by side, not by testing the live feature end-to-end.

## Direction

1. Close Gap 2 + Gap 5 first (turn `maxsections`/`nolimit`/`allowedtypes` into a real, generic admin decision instead of auto-guessed from the base course; redesign the config UI around a default per kind of component instead of one decision per activity; decide how a section's grid-format picture is handled for sections the original template never had) — this is the literal ask ("definir los parámetros de lo que puede y no puede modificar la IA") and unblocks testing the rest honestly instead of against silently-wrong defaults or a 5-screen wizard nobody wants to use.
2. Close Gap 1 (extend the AI service contract to cover `lesson`/`feedback`, and settle `resource`'s handling as keep/reference-only) — nothing else matters if the majority of the template's own content can't be regenerated at all.
3. Everything else (banner/visual consistency across regenerated sections, richer per-activity prompts) builds on top of 1 and 2 and is sequenced in `tasks.md`.

## Fixture

`ai_response_fixture.json` — a realistic AI-response batch for one real section of course 398 (`Módulo #1`), built from the actual titles/structure pulled from the DB, in the exact `{resource_type, parameters}` shape `template_course_builder_service`/`mock_template_ai_service` already consume. Covers the module types already supported today (`label`, `page`, `forum`, `assign`) so it is usable as a drop-in test fixture right now, without waiting on Gap 1's fix. Once `lesson`/`feedback` are added to the AI service contract (Phase 1 below), extend this fixture with real examples of those two — deliberately not guessed here, since their exact parameter shape is part of that phase's spec, not something to invent ahead of it.
