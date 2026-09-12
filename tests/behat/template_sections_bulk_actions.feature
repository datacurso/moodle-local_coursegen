@local @local_coursegen @javascript
Feature: Bulk-editing activity actions in the create-template course sections review
  In order to configure many activities of a template at once
  As an admin creating a course template
  I need per-row action selects, selection checkboxes and one global bulk action bar

  # Spec-only for now: the create-template screen has no dedicated Behat
  # fixtures/steps yet, so this documents the expected interaction contract of
  # amd/src/local/template/sections_events.js; it is not executed by the
  # local suite.
  Background:
    Given the following "courses" exist:
      | fullname      | shortname | numsections |
      | Base course 1 | BASE1     | 2           |
    And the following "activities" exist:
      | activity | course | section | name           |
      | page     | BASE1  | 1       | Welcome page   |
      | page     | BASE1  | 1       | Reading page   |
      | chat     | BASE1  | 1       | Weekly chat    |
    And I log in as "admin"
    And I visit "/local/coursegen/edit_template.php"
    And I set the field "Category" to "Category 1"
    And I set the field "Course" to "Base course 1 (BASE1)"

  Scenario: A row's action select updates the action saved for that activity
    When I set the field with xpath "//tr[@data-for='cmitem'][.//span[text()='Welcome page']]//select[@data-region='activity-action']" to "Keep intact"
    And I set the field "Template name" to "Template 1"
    And I click on "[data-action='save']" "css_element"
    Then the saved template action for "Welcome page" should be "keep"

  Scenario: The section select-all checkbox toggles every row of its own section only
    When I click on "[data-region='select-all']" "css_element" in the "Section 1" "local_coursegen > Template section card"
    Then the "[data-region='activity-select'][aria-label='Welcome page']" "css_element" should be checked
    And the "[data-region='activity-select'][aria-label='Reading page']" "css_element" should be checked
    And the "[data-region='activity-select'][aria-label='Weekly chat']" "css_element" should be checked
    # The card-header checkbox mirrors the same all-checked state.
    And the "[data-region='section-select-all']" "css_element" should be checked

  Scenario: The card-header checkbox selects the whole section without collapsing it
    # A sibling of the collapse toggle: checking it never collapses the card.
    When I click on "[data-region='section-select-all']" "css_element"
    Then "[data-region='activity-action']" "css_element" should be visible
    And the "[data-region='activity-select'][aria-label='Welcome page']" "css_element" should be checked
    And the "[data-region='activity-select'][aria-label='Reading page']" "css_element" should be checked
    And the "[data-region='activity-select'][aria-label='Weekly chat']" "css_element" should be checked
    And the "[data-region='select-all']" "css_element" should be checked
    # Unchecking one row drops both aggregates out of the checked state
    # (they show the indeterminate/mixed visual, checkbox.indeterminate).
    When I click on "[data-region='activity-select'][aria-label='Welcome page']" "css_element"
    Then the "[data-region='section-select-all']" "css_element" should not be checked
    And the "[data-region='select-all']" "css_element" should not be checked
    # It stays usable while the section is collapsed.
    When I click on ".tpl-section-toggle" "css_element" in the "Section 1" "local_coursegen > Template section card"
    And I click on "[data-region='section-select-all']" "css_element"
    Then the "//select[@data-region='bulk-action']" "xpath_element" should be enabled

  Scenario: The global bulk select stays disabled until an activity is checked anywhere
    # Exactly one bulk bar renders, below all the section cards.
    Then "//select[@data-region='bulk-action']" "xpath_element" should exist
    And I should see "With selected activities…" in the "[data-region='bulk-bar']" "css_element"
    And the "//select[@data-region='bulk-action']" "xpath_element" should be disabled
    When I click on "[data-region='activity-select'][aria-label='Welcome page']" "css_element"
    Then the "//select[@data-region='bulk-action']" "xpath_element" should be enabled
    When I click on "[data-region='activity-select'][aria-label='Welcome page']" "css_element"
    Then the "//select[@data-region='bulk-action']" "xpath_element" should be disabled

  Scenario: Applying a bulk action updates every checked row across sections and resets the bulk select
    Given I click on "[data-region='activity-select'][aria-label='Welcome page']" "css_element"
    And I click on "[data-region='activity-select'][aria-label='Weekly chat']" "css_element"
    When I set the field with xpath "//select[@data-region='bulk-action']" to "Modify with AI"
    # AI-supported row follows the bulk choice.
    Then the field with xpath "//tr[.//span[text()='Welcome page']]//select[@data-region='activity-action']" matches value "Modify with AI"
    # Unsupported type (chat) degrades modify to keep — its select has no modify option.
    And the field with xpath "//tr[.//span[text()='Weekly chat']]//select[@data-region='activity-action']" matches value "Keep intact"
    # Unchecked rows are untouched.
    And the field with xpath "//tr[.//span[text()='Reading page']]//select[@data-region='activity-action']" matches value "Modify with AI"
    # The bulk select goes back to its placeholder ("Choose...").
    And the field with xpath "//select[@data-region='bulk-action']" matches value ""

  Scenario: Sections collapse and expand from their header toggle
    When I click on ".tpl-section-toggle" "css_element" in the "Section 1" "local_coursegen > Template section card"
    Then "[data-region='activity-action']" "css_element" in the "Section 1" "local_coursegen > Template section card" should not be visible
    When I click on ".tpl-section-toggle" "css_element" in the "Section 1" "local_coursegen > Template section card"
    Then "[data-region='activity-action']" "css_element" in the "Section 1" "local_coursegen > Template section card" should be visible
