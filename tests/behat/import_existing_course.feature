@local @local_coursegen @javascript
Feature: Import an AI-generated course into an existing course
  In order to refresh an existing course with AI-generated content
  As a course creator
  I need the review panel to offer importing into an existing course only when the site allows it

  # The review panel (#courseReviewPanel) is server-rendered on the page and stays
  # hidden until a planning session is approved. Its markup therefore can be
  # asserted on page load without the AI service (scenarios 1 and 2), while the
  # interactive scenarios need the live DataCurso AI service to reach the panel
  # and are tagged @coursegen_requires_ai_service like course_ai_generation.feature.
  # Exclude them in CI with: vendor/bin/behat --tags='~@coursegen_requires_ai_service'

  Background:
    Given the following "categories" exist:
      | name       | category | idnumber |
      | Category A | 0        | CATA     |
      | Category B | 0        | CATB     |
    And the following "courses" exist:
      | fullname  | shortname | category |
      | Course A1 | A1        | CATA     |
      | Course B1 | B1        | CATB     |

  Scenario: The target-mode choice is not rendered when importing is disabled
    Given the following config values are set as admin:
      | enableimportexisting | 0 | local_coursegen |
    And I log in as "admin"
    When I visit "/local/coursegen/aicoursecreation.php"
    Then "#courseReviewPanel" "css_element" should exist
    And "#courseReviewPanel[data-import-existing-enabled='0']" "css_element" should exist
    And "input[name='coursegen-targetmode']" "css_element" should not exist
    And "#importTargetFields" "css_element" should not exist
    And "#reviewCategory" "css_element" should exist
    And "#reviewShortname" "css_element" should exist

  Scenario: The target-mode choice defaults to a new course when importing is enabled
    Given the following config values are set as admin:
      | enableimportexisting | 1 | local_coursegen |
    And I log in as "admin"
    When I visit "/local/coursegen/aicoursecreation.php"
    Then "#courseReviewPanel[data-import-existing-enabled='1']" "css_element" should exist
    And "input[name='coursegen-targetmode'][value='new']" "css_element" should exist
    And "input[name='coursegen-targetmode'][value='import']" "css_element" should exist
    And "//input[@id='targetModeNew'][@checked]" "xpath_element" should exist
    And "//input[@id='targetModeImport'][not(@checked)]" "xpath_element" should exist
    And "#importTargetFields" "css_element" should not be visible
    And "#importCategory" "css_element" should exist
    And "#importCourse" "css_element" should exist

  @coursegen_requires_ai_service
  Scenario: Choosing to import swaps the new-course fields for the target fields
    Given the following config values are set as admin:
      | enableimportexisting | 1 | local_coursegen |
    And I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections."
    And I press "Generate"
    # Planning runs against the live service (several minutes).
    And "Generate course" "button" should exist
    And I press "Generate course"
    And "#courseReviewPanel" "css_element" should be visible
    And "#reviewShortname" "css_element" should be visible
    And "#reviewCategoryGroup" "css_element" should be visible
    And "#importTargetFields" "css_element" should not be visible
    When I click on "Import into an existing course" "radio"
    Then "#reviewShortname" "css_element" should not be visible
    And "#reviewCategoryGroup" "css_element" should not be visible
    And "#importTargetFields" "css_element" should be visible
    And "#importCategoryGroup .form-autocomplete-downarrow" "css_element" should be visible
    And "#importCourseGroup input[data-fieldtype='autocomplete']" "css_element" should be visible
    And the "Create course" "button" should be disabled
    When I click on "Create as a new course" "radio"
    Then "#reviewShortname" "css_element" should be visible
    And "#reviewCategoryGroup" "css_element" should be visible
    And "#importTargetFields" "css_element" should not be visible
    And the "Create course" "button" should be enabled

  @coursegen_requires_ai_service
  Scenario: The target course search lists only courses of the chosen category
    Given the following config values are set as admin:
      | enableimportexisting | 1 | local_coursegen |
    And I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections."
    And I press "Generate"
    # Planning runs against the live service (several minutes).
    And "Generate course" "button" should exist
    And I press "Generate course"
    And "#courseReviewPanel" "css_element" should be visible
    And I click on "Import into an existing course" "radio"
    When I open the autocomplete suggestions list in the "#importCategoryGroup" "css_element"
    And I click on "Category A" "list_item" in the "#importCategoryGroup" "css_element"
    And I click on "#importCourseGroup input[data-fieldtype='autocomplete']" "css_element"
    And I type "Course"
    Then I should see "Course A1 (A1)" in the "#importCourseGroup .form-autocomplete-suggestions" "css_element"
    And I should not see "Course B1 (B1)" in the "#importCourseGroup .form-autocomplete-suggestions" "css_element"
    When I click on "Course A1 (A1)" "list_item" in the "#importCourseGroup" "css_element"
    Then the "Create course" "button" should be enabled
