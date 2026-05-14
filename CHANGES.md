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

