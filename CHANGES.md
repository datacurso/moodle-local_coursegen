## 2.0.0

**Released on:** 2026-08-14

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Version bump**  
  Internal version bumped to **2026081400** and release bumped to **2.0.0**. Minimum required version of `aiprovider_datacurso` raised to **2026081000**.

## 1.7.3

**Released on:** 2026-08-13

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **Global image settings were ignored in the single-activity flow**  
  When creating a single activity with "Create with AI" and the image generation option enabled, the payload sent to the AI service included only the image toggle (`with_images`) and never the site-wide image generation policy, so the global settings (generation mode, per-activity enables, and per-part image caps) had no effect on standalone activities. The activity payload now includes the same `image_policy` object the full-course flow already sends, built by the shared `image_policy_builder::build()`. Requires the matching AI service change; older services ignore the field.
- **Version bump**  
  Internal version bumped to **2026081300** and release bumped to **1.7.3**.

## 1.7.2

**Released on:** 2026-08-12

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **AI-generated rubric was silently discarded**  
  When the user asked for an assignment graded with a rubric, the AI service generated the full rubric and sent it in `mod_settings.rubric`, but the plugin had no `assign_settings` class, so the rubric was never created and the assignment was left on the rubric grading method with no definition ("rubric not defined"). A new `assign_settings` mod settings class now creates the rubric definition through Moodle's advanced grading API (`gradingform_rubric`), marks it ready, and activates the rubric method only after the definition exists — if creation fails, the assignment degrades to simple direct grading instead of becoming ungradeable.
- **Version bump**  
  Internal version bumped to **2026081202** and release bumped to **1.7.2**.

## 1.7.1

**Released on:** 2026-08-12

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **File-type catalog was missing from the full-course flow**  
  The site file-type group catalog (`filetype_groups`) introduced in 1.7.0 was only attached to the standalone activity payload (`/activity/init`), so assignments generated inside a full course could not restrict accepted file types against the site's real groups. The catalog builder now lives in a shared `filetype_catalog_service` and is attached to the course planning payload as well. Requires the matching AI service change; older services ignore the field.
- **Version bump**  
  Internal version bumped to **2026081201** and release bumped to **1.7.1**.

## 1.7.0

**Released on:** 2026-08-12

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Site file-type catalog sent to the AI service**  
  The activity generation payload (`/activity/init`) now includes `filetype_groups`: the site's real file-type group catalog (group key and its extensions) built from `\core_form\filetypes_util::get_groups_info()`, custom file types included. This lets the AI service infer the accepted file types for an assignment from the described deliverable (e.g. "upload a short video" restricts submissions to the `video` group) and validate the generated value against groups that actually exist on the site, instead of assuming the stock Moodle catalog. If the catalog cannot be resolved, the field is omitted and the service falls back to the standard Moodle groups. Requires the matching AI service change to take effect; older services ignore the field.
- **Version bump**  
  Internal version bumped to **2026081200** and release bumped to **1.7.0**.

## 1.6.0

**Released on:** 2026-06-02

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Course review panel before creation**  
  Added a review step in the streaming UI that shows AI-generated course settings (fullname, shortname, category) before the course is created. Users can override any value. The category selector uses Moodle's `form-autocomplete` with full path display (e.g. "Miscellaneous / My Subcategory").
- **New `get_course_settings` webservice**  
  Added `local_coursegen_get_course_settings` (read, ajax) that returns the AI-generated course fullname, shortname, category, and the full category list with paths — all from the server via `core_course_category::make_categories_list()`.
- **Create course accepts overrides**  
  The `local_coursegen_create_course` webservice now accepts optional `fullname`, `shortname`, and `category` parameters. When provided, they override the AI-generated values.
- **Version bump**  
  Internal version bumped to **2026081101** and release bumped to **1.6.0**.

## Fixed

- **Transcript and plan card described the same activity differently**  
  The plan card showed the detailed description an activity was planned with — what the student submits, the instructions, the criteria — while the transcript beside it showed only the one-line summary written before the activity was detailed. Both now show the detailed one, falling back to the summary for an activity that has not been detailed yet.
- **Markdown shown raw in the plan cards**  
  Activity descriptions, and the chapter and question lines inside an expanded activity, were written into the page as plain text. Anything the model emphasised therefore arrived with its asterisks visible, for example `**[assign] Digital Culture Case Study Analysis**: Students will research…`. All three now render through the bundled `marked`, inline so the markup nests correctly inside the paragraph it already sits in.
- **Rendered plan text is sanitised with DOMPurify**  
  The HTML produced from the model's Markdown was cleaned with regular expressions, which removed dangerous tags and inline event handlers but let a `javascript:` link through. DOMPurify is now bundled as an AMD module (`local_coursegen/purify`) alongside `marked` and cleans against an allow-list, so a link can only carry a safe URL. Moodle ships no sanitiser for JavaScript; the one in `theme_boost` is a theme's private copy of Bootstrap's internals, differs between the themes on a site, and still uses Bootstrap 4's parameter name.
- **Chat transcript rebuilt out of order after a page reload**  
  On reload the conversation was rebuilt by replaying only the user's messages and appending a single closing assistant line. Every instruction the user sent after the plan was therefore printed above the planned-structure card instead of below it, and the assistant's intermediate turns were missing entirely. The transcript is now rebuilt round by round in checkpoint order, closing each answered round with the same message the live stream used, and the feed switches to the post-plan container as soon as the plan card is rebuilt so later turns keep their place.
- **Conversation transcript lost on reload**  
  Reloading rebuilt the conversation from the free text of the user's messages alone, so every turn that depended on what an action actually did came back wrong or not at all: "You applied: move «Basics» after «Advanced»", "You added activity: …", the regenerated subtree of a replan, and the assistant's own turns. The service now records the transcript turn by turn and the plugin replays it, so a reload shows what was on screen. Sessions started before the service records it fall back to the previous rebuild. Requires the matching AI service change.
- **Pending proposals lost on reload**  
  A session paused on a set of proposals (for example a reordering the assistant offered) came back from a reload with the options gone, because the snapshot never carried them. The pending review payload is now read from the session state and the proposals card is re-rendered, so the choice the user was asked to make is still there. Requires the matching AI service change.
- **AI course creation menu entry no longer shown to users who cannot use it**  
  **Site administration > Courses > Create a new course with AI** declared only `local/coursegen:createcoursewithai`, while the page itself requires that capability *and* `moodle/course:create`. Because `admin_externalpage` treats its capability list as OR, a user holding only the plugin capability was shown the entry and then denied access on click. The entry is now registered only when both capabilities are held in the system context.

## 1.5.0

**Released on:** 2026-06-01

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Course creation data now comes from API response**  
  The `create_course` external function is now a thin controller that only validates params and loads the session. All business logic (coursedata parsing, category resolution, course data building) moved to `create_course_service`. Course data is built entirely from the API's `course_configuration` response instead of the session's stored coursedata.
- **Removed restrictive shortname sanitization**  
  Removed `sanitize_shortname_keyword()` which limited shortnames to alphanumeric+hyphens. Shortnames from the API are used directly (trimmed, truncated to 100 chars).
- **Removed `course_identity` references**  
  Renamed `apply_course_identity_to_coursedata` to `build_course_data_from_api` and updated all references from `course_identity` to `course_configuration`.
- **Session coursedata no longer includes course fields**  
  Removed `category`, `fullname`, and `shortname` from the session's `coursedata` payload in `start_course_planning` — these come from the API at creation time.
- **Planning UI spinner-to-checkmark transition**  
  Added `finalizePlanView` to transition the planning loading spinner to a done/checkmark state when streaming completes.
- **Planning overlay hidden on review state**  
  Fixed a bug where the centered planning-loading overlay remained visible during detailed planning review.
- **Version bump**  
  Internal version bumped to **2026060101** and release bumped to **1.5.0**.

## 1.4.0

**Released on:** 2026-05-14

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Refactored webservice layer: thin controllers + service classes**  
  Renamed `wizard_init` to `start_course_planning`, extracting all business logic into `course_planning_service`. The external function now acts as a thin controller that validates params and delegates to services.
- **Full frontend rename: `wizard` → `courseai`**  
  Renamed all AMD modules, Mustache template, CSS selectors, DOM IDs, and JS function/variable names from `wizard` to `courseai` for naming consistency across the codebase.
- **Language string keys renamed**  
  All `wizard_*` language string keys renamed to `courseai_*` across all 7 supported locales.
- **Removed hard-stop for unconfigured API URLs**  
  Removed validation that blocked Generate when `datacurso_service_url` settings were empty, allowing the API client to use its default fallback.
- **New light sidebar for course navigation**  
  Added a light/minimalist sidebar overlay panel that includes:
  - Logo-branded trigger in the navbar (hover reveals menu icon)
  - "New course" shortcut button
  - "Recent" section showing the last 5 in-progress sessions
  - "View all courses" button that switches to a paginated sessions grid (10/page) inside the wizard
  - Backdrop overlay when open, click to close
  - Sidebar slides over the entire page including the navbar (z-index 1040)
  - Separate `sidebar.css` for maintainability
  - New `get_user_inprogress_sessions()` method on `course_session_service`
  - Sidebar closes automatically on any navigation click
- **Version bump**  
  Internal version bumped to **2026051406** and release bumped to **1.4.0**.
- **Refined chat UI during planning/streaming**  
  Full-height sticky left panel with initial prompt shown as a chat history bubble. Compact chat controls are now icon-focused to prevent horizontal scroll. Borders removed from textarea, chat card, toolbar, and message bubble for a cleaner look. A light dividing line separates the chat panel from stream content.
- **Fixed stream content scroll**  
  Removed `max-height: 380px` constraint and `overflow: hidden` on `pc-details-panel` and review cards so the right column scrolls as one unit, no longer clipping stream output.
- **Removed floating sidebar toggle**  
  Removed the absolute-positioned toggle button that caused horizontal page scroll. Sidebar remains accessible via navbar trigger only.

## 1.3.3

**Released on:** 2026-04-10

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Automated prompt-based course creation service**
  Added a dedicated backend automation service to orchestrate end-to-end course creation from prompt context, including planning/execution/result stages and user enrolment when applicable.
- **Centralized result application service**
  Added a dedicated result service to apply remote AI course results in a structured and reusable way.

## Changed
- **More resilient remote automation flow**
  Improved planning stream handling, execute retries, and result polling to better tolerate transient backend/network issues during automated creation.
- **Completion enforcement support in module creation flow**
  Extended module manager parameter handling to support manual completion enforcement during internal automation paths.
- **Version bump**
  Internal version bumped to **2026041000** and release version bumped to **1.3.3**.

## Fixed
- **Static analysis and coding-style compliance**
  Updated PHPDoc parameter annotations and long-line formatting in automation/privacy files to satisfy CI checks (PHPDoc Checker and Codechecker).
- **Language pack consistency cleanup**
  Normalized language files formatting for repository consistency.

## 1.3.2

**Released on:** 2026-01-26

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Optional admin settings for DataCurso service URLs**  
  Added admin settings to optionally override the default DataCurso service base URLs for the **standard** service and the **EU-hosted** service.
- **Translations for service URL settings**  
  Added language strings for `datacurso_service_url` and `datacurso_service_url_eu` across supported locales.
- **CHANGES.md for version history**  
  Added a new **CHANGES.md** file to maintain a clear, versioned history of releases and changes.

## Changed
- **AI API client respects configured service URLs when provided**  
  Updated `ai_course_api` initialization to use the configured DataCurso service URLs when available, falling back to defaults otherwise.
- **Version bump**  
  Internal version bumped to **2026012300** and release version bumped to **1.3.2**.


## 1.3.1

**Released on:** 2025-12-16

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **AI response language selector on the course form**  
  Added a new **AI response language** field to the course generation form (autocomplete from Moodle’s language list), with a help button and a sensible default based on the current user language.
- **Per-course persistence of the selected language**  
  The selected language is stored in the course context record so it can be reused across AI interactions (planning, messaging, and execution).
- **Translations for the language selector**  
  Added language strings across supported locales for the language selector on course form.

## Changed
- **AI request payloads now include `lang` when available**  
  Course planning, message, and execute requests now send the selected language code so the backend can return AI output in the configured language.
- **Course context save flow extended**  
  Updated context saving to persist the selected language alongside context type, system instruction, and prompt/syllabus data.
- **Documentation updated**  
  Updated the README to document the new **AI response language** control in the Datacurso section.
- **Version bump**  
  Internal version bumped to **2025121601** and release version bumped to **1.3.1**.

## 1.3.0

**Released on:** 2025-12-11

 **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Optional image generation support for AI course planning**  
  Added a new course form setting to optionally enable AI image generation for planned courses. The option is disabled by default and, when enabled, is passed as a boolean flag to the course planning API.
- **Translations for image generation controls on the course form**  
  Introduced language strings for the new image generation setting so the course form remains fully localized.

## Changed
- **Course planning API payload extended**  
  The course planning request now includes an `image generation` flag, allowing the backend AI planning service to respect the course-level configuration.
- **Documentation and configuration examples updated**  
  Updated the README to document how to configure and use the new image generation option on the course form.
- **Version bump**  
  Internal version bumped to **2025121100** and release version bumped to **1.3.0**.

## 1.2.1

**Released on:** 2025-12-09

  **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.
 
 ## Fixed
 
  - Fixes an issue where the AI course-creation modal didn’t appear because course view URL validation was too strict.  
  - The previous logic required an exact path match to `/course/view.php`, which failed on subdirectory installs like `https://mysite.com/mymoodle/`.  
  - Updated the detection to use a substring check with `strpos()` for `/course/view.php`, so URL variations and extra path components are handled correctly.

## 1.2.0

**Released on:** 2025-12-05

 **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Optional system instruction support**
  System instructions can now be enabled via a checkbox as an optional complement to other context types, with conditional validation and selection when enabled.
- **Improved navigation for system instruction editing**
  Breadcrumbs/navigation were enhanced to make editing system instructions clearer.

## Changed
- **Terminology and entity rename: “model” → “system instruction”**
  Renamed classes, form fields, parameters, context type constants, DB table references, and API endpoints to use “system instruction” terminology across the codebase.
- **System instruction workflow integrated into context flow**
  System instructions are no longer a standalone context type; they’re integrated as an optional step after choosing a context type.
- **Form UX reordered**
  Reordered fields to: context type selector → custom prompt → syllabus upload → system instruction checkbox/selector.
- **Course planning API call updated**
  Simplified course planning to use the v2 API
- **Version bump**
  Internal version bumped to **2025120500** and release bumped to **1.2.0**.
- **Documentation and translations refreshed**
  Updated README, images, and language strings to match the new system instruction terminology and flow.

## Fixed
- **Help text improved**
  Clarified help text for the custom prompt textarea.
- **Coding standards cleanup**
  Addressed PHPCS line-length and spacing issues.
- **Privacy provider tests aligned**
  Updated privacy provider tests to reference the renamed system instruction table.

## 1.0.3

**Released on:** 2025-12-02

 **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Automated release workflow for the plugin.**  
  A new GitHub Actions workflow was added to streamline/automate Moodle plugin releases.
- **Support from moodle 4.5 to 5.1**  
  Added `$plugin->supported` in `version.php` to declare Moodle compatibility from 4.5 to 5.1

## Changed
- **Release bump to 1.0.3**  
  The plugin release number was updated to **1.0.3**.

