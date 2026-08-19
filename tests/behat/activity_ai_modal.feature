@local @local_coursegen @javascript
Feature: Add activity or resource with AI modal
  In order to create activities and resources with AI
  As a teacher
  I need to open the AI activity modal from the course page with the right options and defaults

  # Runnable UI-surface coverage for SYS-E2E-001, SYS-E2E-002, SYS-E2E-004,
  # SYS-E2E-005 and SYS-E2E-008. These scenarios do NOT need the live DataCurso
  # AI service. The full generation flows (service-dependent halves of the same
  # spec IDs) live in activity_ai_generation.feature and
  # course_ai_generation.feature, tagged @coursegen_requires_ai_service.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teena     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  @SYS-E2E-001 @SYS-E2E-002
  Scenario: Teacher with the createactivitywithai capability sees the AI button in editing mode
    Given I am on the "Course 1" course page logged in as teacher1
    When I turn editing mode on
    Then "Add an activity or resource" "button" should exist
    And "Add activity or resource with AI" "button" should exist
    And ".local_coursegen-add-activity-ai-button" "css_element" should exist

  @SYS-E2E-001
  Scenario: Student without the createactivitywithai capability does not get the AI button
    When I am on the "Course 1" course page logged in as student1
    Then ".local_coursegen-add-activity-ai-button" "css_element" should not exist

  @SYS-E2E-001
  Scenario: Teacher whose createactivitywithai capability is prohibited does not get the AI button
    Given the following "permission overrides" exist:
      | capability                            | permission | role           | contextlevel | reference |
      | local/coursegen:createactivitywithai  | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "Course 1" course page logged in as teacher1
    When I turn editing mode on
    Then "Add an activity or resource" "button" should exist
    And ".local_coursegen-add-activity-ai-button" "css_element" should not exist

  @SYS-E2E-001 @SYS-E2E-002
  Scenario: The AI modal opens from the course page with the chat interface
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I click on "Add activity or resource with AI" "button"
    Then I should see "Create resource/activity with AI" in the ".modal-title" "css_element"
    And I should see "Tell me what resource or activity you need and I will create it in your course." in the "Create resource/activity with AI" "dialogue"
    And "Describe what you need" "field" should exist
    And "button[title='Send']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And "[data-region='local_coursegen/activity/upload']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And I should see "Powered by" in the "Create resource/activity with AI" "dialogue"

  @SYS-E2E-005
  Scenario: Image generation toggle is present and defaults to not generating images
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I click on "Add activity or resource with AI" "button"
    Then I should see "Do not generate images" in the "Create resource/activity with AI" "dialogue"
    And I should see "Generate images" in the "Create resource/activity with AI" "dialogue"
    And "input[name='generate_images'][value='0'][checked]" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And "input[name='generate_images'][value='1']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And "input[name='generate_images'][value='1'][checked]" "css_element" should not exist in the "Create resource/activity with AI" "dialogue"
    # NOTA (SYS-E2E-005): whether each H5P type honours this toggle is decided
    # by the AI service, not by this UI. By design, image-indispensable types
    # (memory game, flashcards, hotspots, etc.) always generate images; the
    # optional-image types (timeline, presentation, personality quiz incl. its
    # cover, drag&drop categorization, fill in the blanks image mode) honour
    # the toggle (fixed 14-18/08/2026). The admin per-activity-type image
    # configuration now travels in BOTH flows (fixed 14/08/2026).
    # NOTA [Pendiente:skip]: the modal should warn or disable this option when
    # the requested type requires images by design (roadmap). Covered as
    # service-dependent scenarios in activity_ai_generation.feature.

  @SYS-E2E-004
  Scenario: Language selector is present with the current Moodle language preselected
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I click on "Add activity or resource with AI" "button"
    Then "[data-region='local_coursegen/activity/lang']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    # The Behat site language is English, so "en" must be preselected.
    And "[data-region='local_coursegen/activity/lang'] option[value='en'][selected]" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And "[data-region='local_coursegen/activity/lang'] option[value='es']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And "[data-region='local_coursegen/activity/lang'] option[value='fr']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    # Supported codes offered by the selector: es, en, de, ru, pt, fr, id.
    # Whether the generated content follows the selected language is
    # service-dependent (see activity_ai_generation.feature).

  @SYS-E2E-008
  Scenario: A failure to start the generation shows an error in the modal and unlocks the form
    # On a fresh test site the DataCurso AI provider has no license key
    # configured, so starting a generation fails immediately without reaching
    # any external service. This exercises the modal error surface (the same
    # one shown for service errors) without the live AI service.
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    And I click on "Add activity or resource with AI" "button"
    When I set the field "Describe what you need" to "Create a quiz about renewable energy"
    And I click on "button[title='Send']" "css_element" in the "Create resource/activity with AI" "dialogue"
    Then I should see "You asked:" in the "Create resource/activity with AI" "dialogue"
    And "[data-region='local_coursegen/activity/retry-alert']" "css_element" should exist in the "Create resource/activity with AI" "dialogue"
    And I should see "Disconnected from server." in the "Create resource/activity with AI" "dialogue"
    # The form must recover so the teacher can try again.
    And the "Describe what you need" "field" should be enabled
    # NOTA (SYS-E2E-008, documented defect): today the service validation
    # detail is discarded and the teacher only gets a generic message, and
    # init failures are not marked retriable (no Retry button is offered for
    # them). The target behaviour (actionable message plus working retry) is
    # scripted as a service-dependent scenario in activity_ai_generation.feature.
