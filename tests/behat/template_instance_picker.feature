@local @local_coursegen @javascript
Feature: Reopening the "Add activity from a template" picker
  In order to add course activities generated from an already-marked template
  As a teacher configuring a course template
  I need the picker to always list every currently marked template, even after
  it has already been opened and closed once before

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teena     | Teacher  | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | course | idnumber | name       | section |
      | lesson   | C1     | lesson1  | Lesson one | 1       |
      | lesson   | C1     | lesson2  | Lesson two | 1       |
    And I log in as "teacher1"
    And I visit "/local/coursegen/edit_template.php?courseid=2"

  @SYS-E2E-TEMPLATE-INSTANCE-PICKER
  Scenario: The persistent trigger reflects a template marked after it was last opened
    When I set the field "Actions" to "Use as template" in the "Lesson one" "table_row"
    And I click on "input[value=section]" "css_element" in the "Configure template" "dialogue"
    And I click on "Save" "button" in the "Configure template" "dialogue"
    And I click on ".tpl-section-card[data-number='1'] [data-region='add-instance'] [data-instance-menu-trigger]" "css_element"
    Then I should see "Lesson one" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
    And I should not see "Lesson two" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
    And I press the escape key
    And I set the field "Actions" to "Use as template" in the "Lesson two" "table_row"
    And I click on "input[value=section]" "css_element" in the "Configure template" "dialogue"
    And I click on "Save" "button" in the "Configure template" "dialogue"
    And I click on ".tpl-section-card[data-number='1'] [data-region='add-instance'] [data-instance-menu-trigger]" "css_element"
    Then I should see "Lesson one" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
    And I should see "Lesson two" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"

  @SYS-E2E-TEMPLATE-INSTANCE-PICKER
  Scenario: A row-gap trigger reflects a template marked after it was last opened
    When I set the field "Actions" to "Use as template" in the "Lesson one" "table_row"
    And I click on "input[value=section]" "css_element" in the "Configure template" "dialogue"
    And I click on "Save" "button" in the "Configure template" "dialogue"
    And I click on ".tpl-section-card[data-number='1'] tr[data-region='row-gap'] [data-instance-menu-trigger]" "css_element"
    Then I should see "Lesson one" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
    And I should not see "Lesson two" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
    And I press the escape key
    And I set the field "Actions" to "Use as template" in the "Lesson two" "table_row"
    And I click on "input[value=section]" "css_element" in the "Configure template" "dialogue"
    And I click on "Save" "button" in the "Configure template" "dialogue"
    And I click on ".tpl-section-card[data-number='1'] tr[data-region='row-gap'] [data-instance-menu-trigger]" "css_element"
    Then I should see "Lesson one" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
    And I should see "Lesson two" in the ".tpl-section-card[data-number='1'] .dropdown-menu.show" "css_element"
