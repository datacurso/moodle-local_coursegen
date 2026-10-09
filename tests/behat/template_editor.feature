@local @local_coursegen @javascript
Feature: Configure a template
  In order to tell the AI what to do with each activity of a course
  As an admin
  I need to choose, for each activity, to keep it or to modify it with AI and give it an instruction

  Background:
    Given the following "categories" exist:
      | name      | category | idnumber |
      | Business  | 0        | BUS      |
    And the following "courses" exist:
      | fullname          | shortname | category |
      | Digital marketing | DM1       | BUS      |
    And the following "activities" exist:
      | activity | name         | course | idnumber | section |
      | page     | Welcome page | DM1    | page1    | 1       |
      | quiz     | Final quiz   | DM1    | quiz1    | 1       |
    And I log in as "admin"

  Scenario: Save a template with one activity modified with AI and another kept
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    When I set the field "Template name" to "Marketing base"
    And I set the field "Actions" to "Modify with AI" in the "Welcome page" "table_row"
    And I set the field "Instruction for the AI (optional)" to "Write it for beginners"
    And I click on "Save template" "button"
    Then I should see "Marketing base"

  Scenario: Every activity offers only two choices
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    Then the "Actions" select box in the "Welcome page" "table_row" contains "Keep intact"
    And the "Actions" select box in the "Welcome page" "table_row" contains "Modify with AI"
    And the "Actions" select box in the "Welcome page" "table_row" does not contain "Use as template"
    And the "Actions" select box in the "Welcome page" "table_row" does not contain "Reference only"
    And the "Actions" select box in the "Welcome page" "table_row" does not contain "Do not include"

  Scenario: The instruction box only appears for the activities modified with AI
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    Then I should not see "Instruction for the AI (optional)"
    When I set the field "Actions" to "Modify with AI" in the "Final quiz" "table_row"
    Then I should see "Instruction for the AI (optional)"
    And I set the field "Actions" to "Keep intact" in the "Final quiz" "table_row"
    And I should not see "Instruction for the AI (optional)"

  Scenario: The bulk bar applies the same two choices to the selected activities
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    When I click on "Welcome page" "checkbox"
    And I click on "Final quiz" "checkbox"
    And I set the field "With selected activities…" to "Modify with AI"
    Then the field "Actions" in the "Welcome page" "table_row" matches value "Modify with AI"
    And the field "Actions" in the "Final quiz" "table_row" matches value "Modify with AI"

  Scenario: A template cannot be saved without a name
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    When I click on "Save template" "button"
    Then I should see "Required"

  Scenario: What was saved is shown again when the template is edited
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    And I set the field "Template name" to "Marketing base"
    And I set the field "Actions" to "Modify with AI" in the "Welcome page" "table_row"
    And I set the field "Instruction for the AI (optional)" to "Write it for beginners"
    And I click on "Save template" "button"
    When I open the action menu in "Marketing base" "table_row"
    And I choose "Edit" in the open action menu
    Then the field "Instruction for the AI (optional)" matches value "Write it for beginners"

  Scenario: A template can be deleted
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    And I set the field "Template name" to "Marketing base"
    And I click on "Save template" "button"
    When I open the action menu in "Marketing base" "table_row"
    And I choose "Delete" in the open action menu
    And I click on "Delete" "button" in the "Delete" "dialogue"
    Then I should see "Nothing to display"

  Scenario: The list of templates shows the course and the activities of each template
    Given I visit "/local/coursegen/edit_template.php?courseid=2"
    And I set the field "Template name" to "Marketing base"
    And I set the field "Actions" to "Modify with AI" in the "Welcome page" "table_row"
    And I click on "Save template" "button"
    Then the following should exist in the "reportbuilder-table" table:
      | Name           | Course            | Activities | Modified with AI |
      | Marketing base | Digital marketing | 2          | 1                |
