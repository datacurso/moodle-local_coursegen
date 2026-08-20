@local @local_coursegen @javascript
Feature: Image generation administration and course sessions listing
  In order to govern AI image generation and resume my planning work
  As an administrator and course creator
  I need the image policy page to behave per mode and the sessions list to show every session

  # Runnable coverage for MDL-E2E-003, MDL-E2E-004 and MDL-E2E-005.
  #
  # Tags used by individual scenarios in this file:
  #
  # @coursegen_requires_ai_service — the scenario needs the live DataCurso AI
  # service (valid aiprovider_datacurso license key and reachable service
  # URLs). Such scenarios MUST be excluded in CI:
  #   vendor/bin/behat --tags='~@coursegen_requires_ai_service'
  # They serve as scripted manual / E2E-with-service runs (see
  # course_complete_generation.feature for the full convention).
  #
  # @coursegen_pending_skip — the scenario asserts the CORRECT expected
  # behaviour for a documented gap ([Pendiente:skip] in the test-cases
  # definition). It fails on current code and MUST be excluded from runs until
  # the gap is fixed:
  #   vendor/bin/behat --tags='~@coursegen_pending_skip'

  @MDL-E2E-003
  Scenario: The three global modes are offered and the rules table only shows in Manual mode
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Course Creator AI > Manage image generation" in site administration
    Then I should see "Manage image generation"
    And I should see "Configuration permissions"
    And I should see "Default behavior"
    # The three global mode cards.
    And I should see "Disabled" in the "[data-mode='disabled']" "css_element"
    And I should see "Manual" in the "[data-mode='manual']" "css_element"
    And I should see "Automatic" in the "[data-mode='auto']" "css_element"
    # An unconfigured site defaults to Disabled and hides the per-activity rules.
    And "#radio-disabled[checked]" "css_element" should exist
    And I should not see "Generation rules"
    # Switching to Manual reveals the per-activity rules table.
    When I click on "[data-mode='manual']" "css_element"
    Then I should see "Generation rules"
    And I should see "Activity or resource"
    And I should see "Assignment" in the "[data-region='local_coursegen/image_generation/manual_settings']" "css_element"
    # Switching back to a non-manual mode hides the rules again.
    When I click on "[data-mode='auto']" "css_element"
    Then I should not see "Generation rules"

  @MDL-E2E-003
  Scenario: The part max field follows its checkbox and the parts block collapses with the master toggle
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Course Creator AI > Manage image generation" in site administration
    And I click on "[data-mode='manual']" "css_element"
    # The master toggle expands the activity's parts block.
    When I click on "label[for='switch-assign']" "css_element"
    Then I should see "Generate images for the assignment introduction."
    # With the part checkbox unchecked its max-images field is disabled.
    And the "#content-assign input[data-region='local_coursegen/image_generation/part_maximages']" "css_element" should be disabled
    # Checking the part enables the field; unchecking disables it again.
    When I click on "label[for='part-assign_intro']" "css_element"
    Then the "#content-assign input[data-region='local_coursegen/image_generation/part_maximages']" "css_element" should be enabled
    When I click on "label[for='part-assign_intro']" "css_element"
    Then the "#content-assign input[data-region='local_coursegen/image_generation/part_maximages']" "css_element" should be disabled
    # Turning the master toggle off collapses the whole parts block.
    When I click on "label[for='switch-assign']" "css_element"
    Then I should not see "Generate images for the assignment introduction."

  @MDL-E2E-003
  Scenario: Saving the image generation policy shows the saved notification
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Course Creator AI > Manage image generation" in site administration
    And I click on "[data-mode='manual']" "css_element"
    And I click on "label[for='switch-assign']" "css_element"
    And I click on "label[for='part-assign_intro']" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    # The saved state survives the reload triggered by the save redirect.
    And "#radio-manual[checked]" "css_element" should exist
    And I should see "Generation rules"

  @MDL-E2E-004
  Scenario: The sessions list shows the empty state with the new course button when there are no sessions
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | creator1 | Cora      | Creator  | creator1@example.com |
    And the following "system role assigns" exist:
      | user     | role          |
      | creator1 | coursecreator |
    And the following "permission overrides" exist:
      | capability                         | permission | role          | contextlevel | reference |
      | local/coursegen:createcoursewithai | Allow      | coursecreator | System       |           |
    And I log in as "creator1"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I click on "#courseaiMenuTrigger" "css_element"
    And I click on "View all sessions" "button"
    Then I should see "My AI Courses"
    And I should see "You have no courses yet."
    And "New course" "link" should exist in the ".courseai-sessions-empty" "css_element"

  # ==========================================================================
  # SERVICE-DEPENDENT SCENARIOS — @coursegen_requires_ai_service
  #
  # These need the live DataCurso AI service to create real sessions. They
  # MUST be excluded in CI (see the tag note in the header) and serve as
  # scripted manual / E2E-with-service runs.
  # ==========================================================================

  @MDL-E2E-004 @coursegen_requires_ai_service
  Scenario: The sessions list shows cards with title, status, date and a Continue link
    # A real session can only be created through the live service.
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    And I press "Generate"
    Then I should see "Review your course plan"
    When I visit "/local/coursegen/aicoursecreation.php"
    And I click on "#courseaiMenuTrigger" "css_element"
    And I click on "View all sessions" "button"
    Then I should see "My AI Courses"
    And ".courseai-session-card" "css_element" should exist
    And ".courseai-session-card-title" "css_element" should exist
    And I should see "Planning" in the ".courseai-session-card-status" "css_element"
    And ".courseai-session-card-date" "css_element" should exist
    And I should see "Continue" in the ".courseai-session-card" "css_element"

  @MDL-E2E-004 @coursegen_requires_ai_service
  Scenario: The draggable divider separates the conversation panel from the plan panel
    # The splitter is only rendered in planning mode, which needs a live session.
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections"
    When I press "Generate"
    # The splitter (aria-label "Resize panels") appears between the two panels.
    Then "#cgSplitter" "css_element" should be visible
    # Manual verification: drag the divider left/right (or focus it and use the
    # arrow keys) and check both panels resize and the position is kept while
    # the session stays open.

  # NOTA: [Pendiente:skip] una parte importante de las etiquetas (tarjeta de
  # revision, propuestas, Detener/Reanudar/Reintentar, catalogo de mensajes de
  # estado) no tiene traduccion al espanol y se muestra en ingles; ademas
  # existen traducciones incorrectas (por ejemplo el modo Deshabilitado se
  # traduce como "Discapacitado"). Test omitido hasta completar el paquete de
  # idioma. This scenario asserts the CORRECT expected behaviour (a fully
  # translated assistant) and fails on current code.
  @MDL-E2E-005 @coursegen_pending_skip @coursegen_requires_ai_service
  Scenario: The whole assistant is translated when the interface runs in Spanish
    # The plugin ships its own lang/es strings; the Spanish core language pack
    # must be installed on the site for the surrounding chrome.
    Given the following "users" exist:
      | username | firstname | lastname | email                | lang |
      | creator2 | Carmen    | Creadora | creator2@example.com | es   |
    And the following "system role assigns" exist:
      | user     | role          |
      | creator2 | coursecreator |
    And the following "permission overrides" exist:
      | capability                         | permission | role          | contextlevel | reference |
      | local/coursegen:createcoursewithai | Allow      | coursecreator | System       |           |
    And I log in as "creator2"
    And I visit "/local/coursegen/aicoursecreation.php"
    Then I should see "¿Qué curso quieres crear?"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Crea un curso corto sobre primeros auxilios con dos secciones"
    And I press "generar"
    # Wait until the plan review is reached (translated hint string).
    Then I should see "Aprobar la estructura para continuar o solicitar ajustes."
    # The review card, the proposals, the Stop/Resume/Retry controls and the
    # status message catalog must appear in Spanish: no English fallback text
    # may remain anywhere in the assistant.
    And I should not see "Review your course plan"
    And I should not see "Designing the course structure"
    And I should not see "Stop"
    And I should not see "Resume"
    And I should not see "Retry"
    # Manual verification: walk the whole wizard (adjustments, proposals,
    # subsections decision, final review, completion card) and confirm every
    # label and status message is Spanish and correctly translated (e.g. the
    # Disabled image mode must not read "Discapacitado").
