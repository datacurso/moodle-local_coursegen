# Backend string catalog (`string_id` mirror)

The AI service streams messages as `{ string_id, string, string_args }`. The
client (`amd/src/local/courseai/i18n.js`, `amd/src/courseai/bootstrap/thread-replay.js`)
localises each message, including replayed thread-log entries, by looking up
`string_id` as a `local_coursegen` language string, passing `string_args` as
`$a`, and fall back to the pre-rendered `string` when the key is missing.

Because these keys are resolved dynamically from service output, a static
search of the plugin source never finds a `get_string('<key>', ...)` call for
them. They are nevertheless live and MUST NOT be removed by dead-code sweeps
of `lang/`. Keep this catalog in sync with the service message names. The
`log_*` thread-log identifiers are listed separately in `STRING_KEYS` of
`amd/src/local/courseai/i18n.js`.

> `args` lists the `string_args` keys, declared as `{$a->...}` placeholders in the lang file.

**Proposals (summaries):**
- `proposal_add_section` — args: instruction, position
- `proposal_delete_section` — args: names
- `proposal_reorder_sections` — args: names
- `proposal_replan_section` — args: instruction, names
- `proposal_add_activity` — args: instruction, position
- `proposal_delete_activity` — args: names
- `proposal_reorder_activities` — args: names
- `proposal_replan_activity` — args: instruction, names
- `proposal_full_regeneration` — args: instruction
- `proposal_adjust_all_details` — args: instruction

**Pipeline status (planning):**
- `detecting_activity_types`, `generating_initial_structure`, `generating_detailed_plans`,
  `creating_section`, `replanning_sections`, `sections_reordered`, `creating_activity`,
  `replanning_activities`, `activities_reordered`, `analyzing_feedback`,
  `generating_images`, `images_generated` — no args
- `planning_activity` — args: title, type
- `sections_deleted` — args: count
- `activities_deleted` — args: count

**Status (activity graph):**
- `detecting_activity_type`, `analyzing_activity_plan` — no args

**Generation errors:**
- `error_processing_activity` — args: error, title
- `error_planning_activity` — args: error, title, type

**Review / clarification:**
- `review_plan_detailed`, `review_plan_activity`, `clarification_fallback` — no args
- `clarification` — args: question

**Lifecycle:**
- `course_completed`, `course_failed`, `activity_completed`, `activity_failed` — no args

**HTTP errors:**
- `session_not_found`, `thread_not_found`, `result_not_ready` — no args

**Validation errors (422):**
- `intent_requires_targets` — args: action
- `intent_single_target` — args: action
- `intent_unknown_proposal_targets` — args: targets
- `intent_unknown_targets` — args: kind, targets
- `intent_requires_parent` — args: action
- `intent_targets_outside_parent` — args: targets
- `proposal_not_found` — args: proposal_id
- `proposal_not_executable` — args: reason

**Content generators (intros):**
- `generating_assignment`, `designing_book`, `generating_choice`, `designing_database`,
  `generating_feedback`, `designing_folder`, `designing_forum`, `designing_glossary`,
  `designing_label`, `designing_lesson`, `designing_page`, `designing_quiz`,
  `designing_resource`, `designing_url`, `planning_wiki`, `generating_workshop` — args: title

**Generators (progress / ready):**
- `book_config_ready` — args: total
- `generating_chapter` — args: step, title, total
- `feedback_blueprint_ready` — args: total
- `generating_feedback_question` — args: step, total, type
- `assembling_feedback` — no args
- `folder_ready` — args: name
- `forum_ready` — args: name
- `forum_ready_with_discussions` — args: count, name
- `label_ready` — args: name
- `page_ready` — args: name
- `quiz_config_ready` — args: total
- `generating_quiz_question` — args: question, step, total, type
- `assembling_quiz` — no args
- `transforming_document` — no args
- `drafting_wiki_page` — args: step, title, total
- `wiki_ready` — args: count, name
- `writing_workshop_instructions`, `assembling_workshop_assessment` — no args

**Generators (errors):**
- `failed_assignment_params`, `failed_book_params`, `failed_database_params`,
  `failed_feedback_blueprint`, `failed_folder_params`, `failed_forum_params`,
  `failed_glossary_params`, `failed_label_params`, `failed_lesson_params`,
  `failed_page_params`, `failed_quiz_params`, `failed_resource_params`,
  `failed_url_params`, `failed_wiki_structure`, `failed_workshop_params` — no args
- `failed_choice_params` — args: error
- `error_generating_chapter` — args: error, step
- `error_generating_feedback_question` — args: step
- `error_generating_quiz_question` — args: step
