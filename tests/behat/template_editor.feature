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
    Given I visit "/local/coursegen/manage_templates.php"
    And I click on "New template" "link"
    And I open the autocomplete suggestions list
    And I click on "Digital marketing" item in the autocomplete list
    When I set the field "Template name" to "Marketing base"
    And I click on "Modify with AI" "radio" in the "Welcome page" "list_item"
    And I set the field "Instruction for the AI (optional)" to "Write it for beginners"
    And I click on "Save template" "button"
    Then I should see "Template saved."
    And I should see "Marketing base"

  Scenario: The instruction box only appears for the activities modified with AI
    Given I visit "/local/coursegen/edit_template.php"
    And I open the autocomplete suggestions list
    And I click on "Digital marketing" item in the autocomplete list
    Then I should not see "Instruction for the AI (optional)"
    And I click on "Modify with AI" "radio" in the "Final quiz" "list_item"
    And I should see "Instruction for the AI (optional)" in the "Final quiz" "list_item"

  Scenario: A template cannot be saved without a name
    Given I visit "/local/coursegen/edit_template.php"
    And I open the autocomplete suggestions list
    And I click on "Digital marketing" item in the autocomplete list
    When I click on "Save template" "button"
    Then I should see "Enter a name for the template."

  Scenario: What was saved is shown again when the template is edited
    Given I visit "/local/coursegen/edit_template.php"
    And I open the autocomplete suggestions list
    And I click on "Digital marketing" item in the autocomplete list
    And I set the field "Template name" to "Marketing base"
    And I click on "Modify with AI" "radio" in the "Welcome page" "list_item"
    And I set the field "Instruction for the AI (optional)" to "Write it for beginners"
    And I click on "Save template" "button"
    When I click on "Marketing base" "link"
    Then the field "Instruction for the AI (optional)" matches value "Write it for beginners"

  Scenario: A template can be deleted
    Given I visit "/local/coursegen/edit_template.php"
    And I open the autocomplete suggestions list
    And I click on "Digital marketing" item in the autocomplete list
    And I set the field "Template name" to "Marketing base"
    And I click on "Save template" "button"
    When I click on "Delete" "button" in the "Marketing base" "table_row"
    And I click on "Delete" "button" in the "Delete" "dialogue"
    Then I should see "There are no templates yet."
