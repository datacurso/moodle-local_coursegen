@local @local_coursegen @javascript @coursegen_requires_ai_service
Feature: Complete AI course generation flows end to end
  In order to build complete courses with AI
  As a course creator
  I need the full planning, adjustment, decision and creation flows to work end to end

  # =========================================================================
  # SERVICE-DEPENDENT SCENARIOS — @coursegen_requires_ai_service
  #
  # Every scenario in this file needs the live DataCurso AI service:
  #   - a valid aiprovider_datacurso license key configured on the site, and
  #   - the service URLs reachable (defaults, or the local_coursegen
  #     datacurso_service_url / datacurso_service_url_eu dev overrides).
  #
  # They MUST be excluded in CI:
  #   vendor/bin/behat --tags='~@coursegen_requires_ai_service'
  #
  # They serve as scripted manual / E2E-with-service runs. Generation against
  # the live service takes minutes: run them with an increased step timeout
  # ($CFG->behat_increasetimeout) or follow them manually. The UI-reachable
  # halves of these flows are runnable without the service and live in
  # course_complete_wizard.feature and course_complete_admin.feature.
  # =========================================================================

  @SYS-E2E-001
  Scenario: Happy path creates a complete course from a request
    Given I log in as "admin"
    And I visit "/my/courses.php"
    When I click on "Create with AI" "button"
    Then I should see "What course do you want to create?"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections and two activities per section"
    And I press "Generate"
    # Live planning: sections and activities appear with the progress bar.
    Then "#planningProgressCard" "css_element" should be visible
    And I should see "Progress"
    And I should see "Review your course plan"
    # Accept the plan in the review card.
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    # Final review panel, then course creation.
    Then I should see "Review course details"
    When I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # The summary reports the created activities and sections.
    And I should see "activities were created in"
    And "Open course" "button" should exist
    When I click on "Open course" "button"
    # Manual verification: the opened course contains the sections and
    # activities of the approved plan, in the planned order.

  @SYS-E2E-002
  Scenario: Free-text adjustments produce proposals that update the plan
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    # Move to the compact composer.
    When I click on "Adjust" "button" in the ".cg-decision-card" "css_element"
    And I set the field with xpath "//textarea[@id='compactPromptInput']" to "Remove the last activity and add a quiz to the first section"
    And I press the enter key
    # The free text is interpreted into a proposals card, never executed directly.
    Then I should see "Here is what I understood. Pick the option you want:"
    And "#planProposalsBlock" "css_element" should be visible
    And ".plan-proposal-card" "css_element" should exist
    And I should see "Something else" in the "#planProposalsBlock" "css_element"
    And "Apply selection" "button" should exist
    And "Dismiss suggestions" "button" should exist
    # Selecting a proposal highlights the affected plan elements; destructive
    # proposals carry the deletion badge.
    When I click on ".plan-proposal-card input[name='courseai-proposal-choice']" "css_element"
    Then ".cg-affected" "css_element" should exist
    # Manual verification: a proposal that deletes content shows the
    # "Deletes content" badge (.plan-proposal-destructive-badge) and its
    # highlight uses the destructive style (.cg-affected--destructive).
    When I click on "Apply selection" "button"
    # The plan updates and the flow returns to review; the step is logged in
    # the conversation.
    Then I should see "You applied"
    And I should see "Review your course plan"
    # Manual verification: repeat with a new instruction and use "Something
    # else" (free-text option) and "Dismiss suggestions"; both must be logged
    # in the conversation and dismissing must keep the plan unchanged.

  @SYS-E2E-003
  Scenario: Direct plan edits are surgical and logged in the conversation
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    # Delete an activity confirming the modal; the action is logged.
    When I click on "Adjust" "button" in the ".cg-decision-card" "css_element"
    # Manual step: open the first activity's row controls and choose its
    # delete action (the per-row controls are hover/focus revealed).
    Then I should see "Delete activity"
    And I should see "Are you sure you want to delete this activity from the plan?"
    When I click on "Delete" "button" in the "Delete activity" "dialogue"
    Then I should see "You deleted activity:"
    # Manual verification (remaining direct edits):
    #   1. Regenerate a section with an instruction: it must keep its position
    #      and only that section is regenerated ("You regenerated section:" in
    #      the conversation).
    #   2. Add a section and an activity describing them ("Add section" /
    #      "Add activity" controls with the "Describe the section to add…" /
    #      "Describe the activity to add…" composers); both are logged.
    #   3. Reorder sections and activities dragging the "Drag to reorder"
    #      handles; the new order persists and "You reordered the sections" /
    #      "You reordered the activities" appear in the conversation.
    #   None of these edits may regenerate the rest of the plan.

  @SYS-E2E-004
  Scenario: A course with subsections is planned and materialised end to end
    Given I enable "subsection" "mod" plugin
    And the following config values are set as admin:
      | config            | value | plugin          |
      | enablesubsections | 1     | local_coursegen |
    And I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    # Request subsections while the wizard toggle is OFF: the decision card
    # must pause the flow before planning.
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a course about first aid where each section is organised into subsections by topic"
    And I press "Generate"
    Then I should see "How do you want to organise the course?"
    # The options are radio cards: select, then confirm.
    When I click on "Enable subsections and plan" "text"
    And I click on ".cg-subsections-confirm" "css_element"
    # The subsections toggle in the "+" menu turns on.
    Then "#btnWithSubsections:checked" "css_element" should exist
    And I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    Then I should see "Review course details"
    When I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    When I click on "Open course" "button"
    # The nested plan is materialised as Subsection modules.
    Then I should see "Subsection"
    # Manual verification: each planned subsection exists inside its parent
    # section in the planned order, its description is applied as the
    # delegated section summary, and its nested activities live inside it.

  @SYS-E2E-005
  Scenario: Choosing a flat structure offers real alternatives and respects the choice
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    # Subsections requested with the toggle off (site default: feature off).
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a course about first aid where each section is organised into subsections by topic"
    And I press "Generate"
    Then I should see "How do you want to organise the course?"
    # The options are radio cards: select, then confirm.
    When I click on "Continue with regular sections" "text"
    And I click on ".cg-subsections-confirm" "css_element"
    # Up to 2 alternative flat structures plus the free-text option.
    Then I should see "Another structure: describe it…"
    # Manual verification: at most 2 alternative structures are offered.
    # The back control returns to the previous question.
    When I click on ".cg-subsections-back" "css_element"
    Then I should see "How do you want to organise the course?"
    When I click on "Continue with regular sections" "text"
    And I click on ".cg-subsections-confirm" "css_element"
    # Describe an own structure through the free-text option and confirm it.
    And I click on "Another structure: describe it…" "text"
    And I set the field with xpath "//div[contains(@class, 'cg-subsections-decision')]//textarea" to "One section per emergency type, from most to least frequent"
    Then I should see "Use this structure" in the ".cg-subsections-confirm" "css_element"
    When I click on ".cg-subsections-confirm" "css_element"
    # Planning continues with the chosen flat structure.
    Then I should see "Review your course plan"
    # Manual verification: the planned sections follow the chosen structure
    # and contain no subsections.

  @SYS-E2E-006
  Scenario: A syllabus guides the structure and content of the created course
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "Creation options" "button"
    When I click on "#btnSyllabus" "css_element"
    Then "File picker" "dialogue" should be visible
    # Manual step: upload a valid syllabus PDF through the file picker
    # (Upload a file > choose the fixture > Upload this file).
    And "#chipSyllabus:not(.hidden)" "css_element" should exist
    And "Remove syllabus" "button" should exist in the "#chipSyllabus" "css_element"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create the course following the attached syllabus"
    And I press "Generate"
    Then I should see "Review your course plan"
    # Manual verification: the planned sections reflect the syllabus topics
    # and order.
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    Then I should see "Review course details"
    When I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # Manual verification: open the course and check the generated content is
    # traceable to the syllabus topics (no invented topics).

  @SYS-E2E-007
  Scenario: An institutional guideline accompanies the whole planning
    Given I log in as "admin"
    # Create the guideline in administration (precondition of the case).
    And I navigate to "Plugins > Local plugins > Course Creator AI > Manage system instructions" in site administration
    And I press "Add system instruction"
    And I set the field "System instruction name" to "Institutional style guide"
    And I set the field "System instruction content" to "Always use formal language, include learning objectives per section and cite sources."
    And I press "Save changes"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "Creation options" "button"
    When I click on "#btnDirectrices" "css_element"
    And I click on "Institutional style guide" "button" in the "#guidelineList" "css_element"
    Then "#chipGuideline:not(.hidden)" "css_element" should exist
    # Open the preview before generating.
    When I click on "View guideline" "button" in the "#chipGuideline" "css_element"
    Then I should see "Always use formal language, include learning objectives per section and cite sources." in the "#guidelinePreviewModal" "css_element"
    When I click on ".preview-close" "css_element"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    Then I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # Manual verification: the plan and the generated content respect the
    # guideline (formal language, objectives per section, cited sources).

  @SYS-E2E-008
  Scenario: The plan and the content follow the language chosen in the plus menu
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "Creation options" "button"
    When I click on "#pmLangItem" "css_element"
    Then "#langPopover.open" "css_element" should exist
    # Choose a language different from the interface language (English site).
    When I click on "//ul[@id='langList']//button[@data-lang='es']" "xpath_element"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    Then I should see "Review your course plan"
    # Manual verification: every planned section and activity title and
    # description is in Spanish, and after creating the course the generated
    # activity content is entirely Spanish (the course language setting itself
    # is covered by MDL-INT-025).

  @SYS-E2E-009
  Scenario: Manual image policy produces curated suggestions that govern the final images
    Given I log in as "admin"
    # Precondition: image policy in Manual mode with parts enabled.
    And I navigate to "Plugins > Local plugins > Course Creator AI > Manage image generation" in site administration
    And I click on "[data-mode='manual']" "css_element"
    And I click on "label[for='switch-assign']" "css_element"
    And I click on "label[for='part-assign_intro']" "css_element"
    And I press "Save changes"
    And I should see "Changes saved"
    And I visit "/local/coursegen/aicoursecreation.php"
    # Turn the images toggle on.
    And I click on "Creation options" "button"
    And I click on "#imgToggleTrack" "css_element"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections including assignments"
    And I press "Generate"
    Then I should see "Review your course plan"
    # Image suggestions appear per activity with their controls.
    And I should see "suggested images"
    And "Discard" "button" should exist
    # Manual step: discard one suggestion and regenerate (adjust) another one;
    # "You discarded an image suggestion" / "You asked me to regenerate an
    # image" must appear in the conversation.
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # Manual verification: the created content contains ONLY the images that
    # were not discarded, and the per-part maximums of the manual policy are
    # respected.

  @SYS-E2E-010
  Scenario: With the images toggle off no image is planned or generated
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    # The images toggle is off by default: leave it untouched.
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    Then I should see "Review your course plan"
    And I should not see "suggested images"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # Manual verification: no activity in the created course contains an
    # AI-generated image, regardless of the global image policy.

  @SYS-E2E-011
  Scenario: The images toggle prevails when the site policy was never configured
    # Precondition: fresh site, image generation policy never saved.
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "Creation options" "button"
    And I click on "#imgToggleTrack" "css_element"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    Then I should see "Review your course plan"
    # Correct behaviour: default automatic imaging applies, so suggestions
    # appear even though the policy was never configured.
    And I should see "suggested images"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # Manual verification: the created course includes images following the
    # default automatic behaviour.

  @SYS-E2E-012
  Scenario: Stopping and resuming the generation neither loses nor duplicates content
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a course about first aid with four sections and three activities per section"
    When I press "Generate"
    # While the stream is transmitting, stop it.
    And I click on "#btnStopExec" "css_element"
    # The received content stays frozen on screen.
    Then "#btnResumeExec" "css_element" should be visible
    # Manual step: note the sections/activities already received.
    When I click on "#btnResumeExec" "css_element"
    # The stream continues from the checkpoint.
    Then I should see "Review your course plan"
    # Manual verification: after resuming, the previously received content is
    # intact and nothing is duplicated (each section/activity appears once).

  @SYS-E2E-013
  Scenario: Reloading the page restores each state exactly where it was
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    # 1. Reload during plan review: conversation, plan and pending proposals
    # are restored (the session id is persisted in the URL).
    When I reload the page
    Then I should see "Create a short course about first aid with two sections"
    And I should see "Here is the structure I planned for your course"
    And I should see "Review your course plan"
    # 2. Reload during generation: the stream is reopened without restarting
    # the planning.
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I reload the page
    Then "#planningProgressCard" "css_element" should be visible
    # Manual verification: the progress continues from where it was (the plan
    # is NOT regenerated from scratch).
    # 3. Reload with the course completed: the final screen is restored.
    And I should see "Review course details"
    When I click on "Create course" "button"
    And I should see "Course generated successfully!"
    And I reload the page
    Then I should see "Course generated successfully!"
    And "Open course" "button" should exist
    # Manual verification: language, toggles and the original request are kept
    # after every reload.

  @SYS-E2E-013
  Scenario: Reloading during the subsections decision restores the decision card
    Given I enable "subsection" "mod" plugin
    And the following config values are set as admin:
      | config            | value | plugin          |
      | enablesubsections | 1     | local_coursegen |
    And I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a course about first aid where each section is organised into subsections by topic"
    And I press "Generate"
    And I should see "How do you want to organise the course?"
    When I reload the page
    Then I should see "How do you want to organise the course?"
    And I should see "Enable subsections and plan"
    And I should see "Continue with regular sections"

  @SYS-E2E-014
  Scenario: A dropped connection offers an in-place retry that reopens the stream
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    When I press "Generate"
    # Manual step: while the planning stream is transmitting, cut the
    # connection to the AI service (disconnect the network or block the
    # service URL) until the stream fails fatally.
    Then I should see "Connection error. Please try again."
    And ".cg-retry-btn" "css_element" should exist
    And I should see "Retry" in the ".cg-retry-btn" "css_element"
    # Manual step: restore the connection.
    When I click on ".cg-retry-btn" "css_element"
    # The stream reopens in place, without reloading the page.
    Then I should see "Review your course plan"
    # Manual verification: momentary drops are ignored without flicker; only
    # fatal drops surface the permanent error turn with Retry.

  @SYS-E2E-015
  Scenario: A failing activity does not stop the creation of the rest
    # Precondition: an approved plan where at least one activity will fail on
    # creation (service-side fault injection, e.g. force one activity type to
    # return invalid parameters).
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    # The course is still created with the remaining activities.
    Then I should see "Course generated successfully!"
    When I click on "Open course" "button"
    # Manual verification: every non-failing activity of the plan exists in
    # the course; the failed one is absent and the completion view reports the
    # number of warnings.

  @SYS-E2E-015
  Scenario: The partial-creation notice identifies the failed activities and their reasons
    # Precondition: same fault injection as above, forcing the activity titled
    # "Forced failure activity" to fail during creation.
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    # Manual verification: the creation response reports the warning count
    # (warningscount) and the per-activity detail (activityerrors: type,
    # section, title and reason) naming "Forced failure activity", and the
    # partial warning phrase comes from the language pack
    # (create_course_partial_warning) instead of a fixed English sentence.

  @SYS-E2E-016
  Scenario: Teacher overrides for name, short name and category prevail
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    # The panel comes prefilled by the AI and offers the category selector.
    Then I should see "Review course details"
    And "Course name" "field" should exist
    And "Short name" "field" should exist
    And "Category" "field" should exist
    # Manual verification: the name and short name fields arrive prefilled
    # with the values inferred by the AI, and the category selector offers a
    # search box.
    When I set the field "Course name" to "Behat overridden course"
    And I set the field "Short name" to "behatoverride"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"
    When I click on "Open course" "button"
    Then I should see "Behat overridden course"
    # Manual verification: repeat the flow reusing the same short name — the
    # new course must receive a numbered suffix so the short name stays unique.

  @SYS-E2E-017
  Scenario: Cancelling the final review keeps a visible way to create the course
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I should see "Review course details"
    And I click on "Cancel" "button" in the "#courseReviewPanel" "css_element"
    # Without reloading, a visible control reopens the review panel with the
    # same data so the teacher can still create the course.
    Then "Reopen course review" "button" should be visible
    When I click on "Reopen course review" "button"
    Then I should see "Review course details"
    And "Create course" "button" should be visible

  @SYS-E2E-018
  Scenario: A session can be resumed from the sessions listing and completed
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    And I should see "Review your course plan"
    # Leave the session in review and reopen it from the listing.
    When I visit "/local/coursegen/aicoursecreation.php"
    And I click on "#courseaiMenuTrigger" "css_element"
    And I click on "View all sessions" "button"
    Then I should see "My AI Courses"
    And I should see "Planning" in the ".courseai-session-card-status" "css_element"
    When I click on "Continue" "link" in the ".courseai-session-card" "css_element"
    # The session restores in its exact state and can be completed.
    Then I should see "Review your course plan"
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    And I click on "Create course" "button"
    Then I should see "Course generated successfully!"

  @SYS-E2E-019
  Scenario: Service status messages are localised through their string identifier
    # The service streams { string_id, string, string_args }; Moodle localises
    # by string_id and falls back to the sent text for unknown identifiers.
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    When I press "Generate"
    And I should see "Review your course plan"
    # Stable thread turns rendered from the local string catalog by string_id.
    Then I should see "Here is the structure I planned for your course"
    And I should see "I finished planning your course. Take a look at the plan and tell me if you want any changes."
    When I click on "Accept" "button" in the ".cg-decision-card" "css_element"
    Then I should see "You approved the plan"
    # Manual verification:
    #   1. Repeat with the interface in a language with a complete plugin pack
    #      (e.g. de/fr/pt/ru/id): the same status and progress messages must
    #      show translated, proving they resolve via string_id.
    #   2. Fallback: with a service build emitting an unknown string_id, the
    #      raw text sent by the service must be shown instead of a
    #      placeholder, so there is always readable feedback.
