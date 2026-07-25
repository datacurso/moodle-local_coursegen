## [1.4.1] - 2025-07-25

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.1**.

### Changed
- **Settings page reorganized into Course AI and Activity AI sections**
  Replaced the single "Feature toggles" heading with two separate sections. Image generation and empty course settings are now hidden when their parent feature is disabled via `hide_if`

### Fixed
- **Server-side enforcement for all admin settings and capabilities**
  All 5 admin settings and 2 capabilities are now validated server-side in web services (`create_mod_stream`, `create_mod`, `create_course`, `plan_course_execute`, `plan_course_message`) and form submission (`after_form_submission`). Previously they were only enforced at the UI level and could be bypassed via direct AJAX calls or DOM manipulation
- **Image generation request without permission now throws an error**
  When a user bypasses the UI and requests image generation without the setting or capability, the server throws a clear error (`error_activity_image_generation_denied` / `error_course_image_generation_denied`) instead of silently downgrading
- **Activity creation crashed when image generation was disabled**
  When `enable_activity_image_generation` was off, the JS crashed trying to read `.value` on a null element because the radio buttons were removed from the DOM. Now defaults to `"0"` when the element is absent
- **Replaced hardcoded error strings with lang strings**
  All server-side error messages now use `get_string()` from the plugin's language packs instead of hardcoded English text
- **Plugin capabilities declared in `db/services.php`**
  Added `local/coursegen:createcoursewithai` and `local/coursegen:createactivitywithai` to the web service declarations alongside the core Moodle capabilities
- **Version bump**
  Internal version bumped to **2026072500** and release version bumped to **1.4.1**

## [1.4.0] - 2025-07-25

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.1**.

### Added
- **Admin feature toggles for all AI features**
  Five new master switches in General settings: Enable AI course creation, Enable AI activity creation, Enable image generation for courses, Enable image generation for activities, and Enable AI generation for empty courses
- **Per-role image generation capabilities**
  Two new capabilities `local/coursegen:generatecourseimages` and `local/coursegen:generateactivityimages` (manager archetype only by default) to control who sees image generation options independently from admin settings
- **AI course creation for empty existing courses**
  The "Create with AI" button now appears on the edit form of existing courses that have no content (only the default Announcements forum), gated by the `enable_empty_course_ai` setting
- **Development settings page**
  Service URL overrides moved to a separate Development settings page, keeping General settings focused on feature toggles
- **Behat test suite**
  Added 23 Behat scenarios across two feature files: `admin_settings.feature` (13 scenarios for master switch toggles) and `capability_access_control.feature` (10 scenarios for per-role access)
- **PHPUnit test suite for hooks**
  Added 15 PHPUnit tests covering `is_course_empty`, `can_create_course`, `can_create_activity`, and image generation capability gating
- **Behat CI step in plugin-ci workflow**
  Added Behat execution with Chrome profile and faildump artifact upload on failure

### Changed
- **Settings page reorganized**
  General settings now contains only feature toggles; service URL fields moved to Development settings
- **Image generation UI gated by dual check**
  Image generation select (course form) and radio buttons (activity modal) are now hidden unless both the admin setting is enabled AND the user has the corresponding capability
- **Version bump**
  Internal version bumped to **2026072404** and release version bumped to **1.4.0**

### Fixed
- **Strict type comparison in empty course detection**
  Fixed `sectionnum` comparison that failed because Moodle returns it as string `"0"` — strict `!== 0` was always true
- **Config defaults not persisted on plugin upgrade**
  Added `db/upgrade.php` step to persist default values for new settings, preventing `get_config()` from returning `false` before admin saves the settings page
- **Forum lib dependency crash during footer hook**
  Replaced `require_once(mod/forum/lib.php)` + `forum_get_course_forum()` with a direct DB query to avoid exceptions when loading forum library during the before_footer hook
- **AI button not found on course edit form**
  Fixed JS submit button selector to find `#fgroup_id_buttonar .form-submit` used by course edit forms, and ensured the click handler is always registered
- **AI button misaligned on course edit form**
  Changed button insertion to place it inside the button group instead of before it, so it aligns with Save and Cancel

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

