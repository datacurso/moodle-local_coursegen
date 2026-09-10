# Course template feature — tasks by phase

Grounded in `plan.md`. Phase 1 is first on purpose: nothing downstream can be tested honestly while the template's own configuration is silently guessed instead of a real decision the admin makes.

## Phase 1 — Template configuration (what the admin can decide, what the AI can/cannot touch)

Goal: configuring a template today means walking through several separate screens and deciding, one by one, what happens to every single activity in the course — for a real course that can mean dozens of individual decisions. The redesign should let the admin get a real template configured in one simple pass, by deciding behavior per KIND of component instead of per individual item, grounded in how a real institutional course actually uses each kind.

- [x] **Collapse the current multi-screen setup into one simple flow.** Today an admin has to move through separate screens for the course, the limits, the section-by-section review, a preview and a summary. Fold this into as few steps as possible — ideally one screen where the admin sees the course, sets the few overall options, and reviews only what needs a decision.
- [x] **Replace the custom "browse categories, then browse courses" picker for choosing the base course with the standard search field Moodle already uses everywhere else.** The current picker (a two-panel category tree plus a separate course list, with its own search box) is a custom-built, fragile screen of its own — it has already broken outright (a real error prevents even reaching the course picker at all). Replace it with the plain, standard pattern already used on Moodle's own "Edit course settings" page: one search field to find and pick a category, then a second search field — populated live for whatever category was picked — to find and pick a course inside it. Both should behave exactly like the familiar category/course search fields everywhere else in Moodle, not like a custom-built browsing screen.
- [x] **Let the admin set a default behavior per kind of component, not per individual activity.** A real course repeats the same kinds of pieces over and over — a welcome/section banner, an informational page, a discussion forum, a graded activity or exam, a file attachment, a closing survey. Instead of reviewing every single one, the admin sets one default per kind (e.g. "banners: always regenerate", "forums: keep as is", "file attachments: keep as reference, never regenerate", "graded activities: regenerate keeping the same grading setup") and only needs to touch the rare exception.
- [ ] **Remove the bulk "default behavior per activity type" panel itself** — the client saw it in practice and didn't want it as a visible, separate control. Keep the underlying idea (each activity still starts out with the sensible default for its own kind, computed automatically the moment the course structure loads — banners and informational pages default to regenerate, forums/attachments/closing surveys default to keep, etc.), just without a dedicated bulk-editing section above the course structure. Any change to that default happens only from the per-activity control in the structure review below, one activity at a time.
- [x] **Support courses that use the grid/tile course layout**, where each module also shows its own picture in the course's overview grid, separate from the banner inside its content. When the generated course ends up with more modules than the original template had, those extra modules must not end up missing that picture while every other module has one. **Simply duplicating an existing module's picture is not acceptable** — those pictures have the module's own name/number baked into them, so a copied picture ends up showing the wrong module name (e.g. a new "Module 6" visibly showing "Module 5"), which is worse than showing nothing. Instead, this picture must be generated (as a simple, lightweight image the AI can produce reliably, not a large photo-like file) using the design of the existing modules' pictures as its reference, so the new module's picture is visually coherent with the rest of the course and correctly labeled. The admin should be able to either upload their own reference image for this to follow, or simply say "keep new modules visually consistent with the existing ones" and let the AI work out the shared style from the pictures the course already has.
- [ ] **Reuse the same "keep new modules visually consistent" approach for anything else regenerated in bulk**, not only this picture — the underlying idea (analyze what already exists, follow it, never invent a name/label that doesn't match the module it's on) is the same principle already used for section banners elsewhere in this plugin's AI generation.
- [x] **Keep the overall section-count limit and the allowed-activity-types choice**, but fold them into the same simple flow instead of a separate step.
- [x] **Write down, in plain terms, the final simplified set of decisions an admin makes** when configuring a template (the overall options, and the default behavior per kind of component) so the next person setting up a template for a different institution has a clear, short reference instead of having to figure it out by trial and error.

## Phase 2 — Support every activity type real templates actually use

Goal: today only a handful of activity types can be regenerated by AI; real templates use several the system doesn't support yet, so "Modify" on those fails silently.

- [ ] **Support AI-regenerated content for the multi-page lesson activity type.** This is the single highest-value type to add — it is by far the most common activity type in the real templates reviewed.
- [ ] **Decide how the closing "course opinion survey" activity behaves**: whether it should always be reused as-is from the base course (since its questions rarely change per course) with only its introductory text open to regeneration, or fully open to AI regeneration like other activities.
- [ ] **Confirm file-type resources are handled purely as "keep" or "use as reference"**, never regenerated — matching how they're already used in every real template reviewed.
- [ ] **Add real example content for the newly-supported activity types to the test data used to validate this feature**, so future changes can be checked against realistic content instead of just the types already supported.
- [ ] **Build a full course end-to-end from a real template** once the above is in place, and confirm the result holds up to the same review a real client-facing course would get — this feature has so far only ever been exercised against toy/demo content, never a real production-shaped template.

## Phase 3 — Keep the visual design consistent across an entire generated course

Goal: when several sections of the same course get their content regenerated independently, they should still look like one coherent, professionally designed course (same banners, same colors, same style) — not like several unrelated pieces stitched together.

- [ ] **Decide whether the visual design is fixed once per template** (reused identically every time someone builds a course from it) **or decided fresh every time a course is built** from that template. Since a template's whole purpose is a consistent, reusable visual identity, fixing it once per template is the more likely right default — but confirm this matches how templates are expected to be reused in practice.
- [ ] **Make sure every regenerated section of a course actually receives and follows that shared visual design**, not just its own isolated instructions — this is what will keep banners, colors and layout consistent across the whole course once real AI regeneration is wired in (Phase 4).
- [ ] This piece only becomes fully testable once Phase 4 (the real AI generation) is in place — the current test/demo content generator does not need this, since it isn't meant to look production-quality.

## Phase 4 — Turn on real AI-generated content (replacing the current test/demo content)

Goal: swap out the placeholder content generator currently used for testing this feature with the real AI content generation service, so what gets built is actually production-quality.

- [ ] **Connect template-based course generation to the real AI content service**, covering every activity type settled in Phase 2.
- [ ] **Reuse the same AI-generation path already used for the "generate a course from scratch" flow**, rather than building a second, separate way of talking to the AI service.
- [ ] **Switch the feature over to the real content generator** once it's ready, replacing the current placeholder used only for testing.

## Phase 5 — Cleanup

Goal: low-risk housekeeping found during this analysis, not blocking anything above.

- [ ] Remove leftover data structures from an earlier, superseded version of this feature that are no longer read by anything.
- [ ] Once Phase 1 ships, set up and use one real template from an actual real course (not a toy/demo course) as the baseline for checking every phase above as it's built.
