@local @local_coursegen @javascript
Feature: AI course creation wizard form and creation options menu
  In order to start planning a complete course with AI
  As a course creator
  I need the initial form to validate my request and the "+" options menu to offer the creation settings

  # Runnable UI-surface coverage for MDL-E2E-001 and MDL-E2E-002. These
  # scenarios do NOT need the live DataCurso AI service, except the one
  # explicitly tagged @coursegen_requires_ai_service (see the tag note in
  # course_complete_generation.feature: it MUST be excluded in CI with
  #   vendor/bin/behat --tags='~@coursegen_requires_ai_service').
  # The wizard requires BOTH capabilities at system level:
  # moodle/course:create and local/coursegen:createcoursewithai.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | creator1 | Cora      | Creator  | creator1@example.com |
    And the following "system role assigns" exist:
      | user     | role          |
      | creator1 | coursecreator |
    And the following "permission overrides" exist:
      | capability                         | permission | role          | contextlevel | reference |
      | local/coursegen:createcoursewithai | Allow      | coursecreator | System       |           |

  @MDL-E2E-001
  Scenario: The wizard opens from My courses with the Create with AI button
    Given I log in as "creator1"
    And I visit "/my/courses.php"
    When I click on "Create with AI" "button"
    Then I should see "What course do you want to create?"
    And "Generate" "button" should exist
    And "#promptInput" "css_element" should exist

  @MDL-E2E-001
  Scenario: A request shorter than 10 characters is not sent and focus returns to the field
    Given I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Too short"
    Then the "Generate" "button" should be disabled
    # Pressing Enter runs the same submit path as the Generate button: with an
    # invalid request nothing is sent and the focus goes back to the field.
    When I click on "#promptInput" "css_element"
    And I press the enter key
    Then I should see "What course do you want to create?"
    And the focused element is "#promptInput" "css_element"
    And the "Generate" "button" should be disabled

  @MDL-E2E-001
  Scenario: Shift+Enter adds a line break without sending the request
    Given I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I click on "#promptInput" "css_element"
    And I type "Create a Python course"
    And I press the shift enter key
    And I type "for beginners"
    Then the field "promptInput" matches multiline:
      """
      Create a Python course
      for beginners
      """
    # The form was not submitted: the wizard is still on the context step.
    And I should see "What course do you want to create?"

  # ==========================================================================
  # SERVICE-DEPENDENT — @coursegen_requires_ai_service (see the header note).
  # Enter submits the request, which starts a planning session against the
  # live DataCurso AI service. Without the service the init call fails and the
  # wizard reverts to the context step, so this half of MDL-E2E-001 can only
  # be verified with the service reachable.
  # ==========================================================================
  @MDL-E2E-001 @coursegen_requires_ai_service
  Scenario: Enter sends a valid request
    Given I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I click on "#promptInput" "css_element"
    And I type "Create a short course about first aid with two sections"
    And I press the enter key
    # The request is sent: the wizard leaves the context step and the sent
    # prompt becomes the first turn of the conversation thread.
    Then I should see "Create a short course about first aid with two sections"

  @MDL-E2E-002
  Scenario: The plus menu offers the five creation options when subsections are available
    Given I enable "subsection" "mod" plugin
    And the following config values are set as admin:
      | config             | value | plugin          |
      | enablesubsections  | 1     | local_coursegen |
    And I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I click on "Creation options" "button"
    Then "#plusMenuPanel.open" "css_element" should exist
    And I should see "Syllabus" in the "#plusMenuPanel" "css_element"
    And I should see "Guidelines" in the "#plusMenuPanel" "css_element"
    And I should see "Language" in the "#plusMenuPanel" "css_element"
    And I should see "Images" in the "#plusMenuPanel" "css_element"
    And I should see "Subsections" in the "#plusMenuPanel" "css_element"

  @MDL-E2E-002
  Scenario: The subsections toggle is hidden when the global setting is off
    # Default site: the "Enable subsections" admin setting is off, so the
    # toggle must not be offered in the menu regardless of the module state.
    Given I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I click on "Creation options" "button"
    Then I should see "Images" in the "#plusMenuPanel" "css_element"
    And I should not see "Subsections" in the "#plusMenuPanel" "css_element"
    And "#btnWithSubsections" "css_element" should not exist

  @MDL-E2E-002
  Scenario: Toggle switches keep the menu open and action options close it
    Given I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "Creation options" "button"
    # A toggle row (Images) keeps the menu open so several settings can be
    # flipped in one visit.
    When I click on "#imgToggleTrack" "css_element"
    Then "#plusMenuPanel.open" "css_element" should exist
    # An action option (Language) closes the menu and opens its own popover.
    When I click on "#pmLangItem" "css_element"
    Then "#plusMenuPanel.open" "css_element" should not exist
    And "#langPopover.open" "css_element" should exist
    # The syllabus action also closes the menu, opening the core file picker.
    When I click on "#langPopoverClose" "css_element"
    And I click on "Creation options" "button"
    And I click on "#btnSyllabus" "css_element"
    Then "#plusMenuPanel.open" "css_element" should not exist
    And "File picker" "dialogue" should be visible

  @MDL-E2E-002
  Scenario: Choosing a guideline shows a removable chip with a content preview
    # Guidelines are created in the Manage system instructions admin page; the
    # plugin ships no Behat generator, so the guideline is created via the UI.
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Course Creator AI > Manage system instructions" in site administration
    And I press "Add system instruction"
    And I set the field "System instruction name" to "Institutional style guide"
    And I set the field "System instruction content" to "Always use formal language and cite sources."
    And I press "Save changes"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "Creation options" "button"
    When I click on "#btnDirectrices" "css_element"
    Then "#guidelinesPopover.open" "css_element" should exist
    And I should see "You can only apply one guideline per course."
    When I click on "Institutional style guide" "button" in the "#guidelineList" "css_element"
    Then "#chipGuideline:not(.hidden)" "css_element" should exist
    And I should see "Institutional style guide" in the "#chipGuidelineName" "css_element"
    # The eye button previews the full guideline content sent to the AI.
    When I click on "View guideline" "button" in the "#chipGuideline" "css_element"
    Then I should see "Institutional style guide" in the "#guidelinePreviewModal" "css_element"
    And I should see "Always use formal language and cite sources." in the "#guidelinePreviewModal" "css_element"
    And I should see "Complete content that will be sent to AI as context." in the "#guidelinePreviewModal" "css_element"
    When I click on ".preview-close" "css_element"
    # The chip is removable and clears the selection.
    And I click on "Remove guideline" "button" in the "#chipGuideline" "css_element"
    Then "#chipGuideline:not(.hidden)" "css_element" should not exist
    # NOTA (MDL-E2E-002, step 3): attaching a syllabus through the core file
    # picker upload form cannot be driven with standard steps (the wizard uses
    # a programmatic filepicker, not a filemanager form element). The syllabus
    # chip ("Remove syllabus" control) is verified in the service-dependent
    # syllabus scenario (SYS-E2E-006) in course_complete_generation.feature.
