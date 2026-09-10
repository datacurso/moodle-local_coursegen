@local @local_coursegen @javascript
Feature: Prompt panel in the template-mode activity chooser
  In order to guide the AI when adding an activity to a template course
  As a professor creating a course from a template
  I need to describe the activity before it is added, instead of it being inserted on click

  # The template fixture (a course template with allowed activity types) has no
  # generator steps yet, so this spec documents the expected interaction; it is
  # not executed by the local suite.
  Background:
    Given the following "courses" exist:
      | fullname      | shortname | numsections |
      | Base course 1 | BASE1     | 2           |
    And I log in as "admin"
    And a course template named "Template 1" based on "BASE1" allowing "page" activities exists
    And I visit "/local/coursegen/aicoursecreation.php?mode=template"
    And I set the field "Course template" to "Template 1"

  Scenario: Picking an activity type reveals the prompt panel instead of adding it
    When I click on "[data-action='local_coursegen/template/open-chooser']" "css_element"
    And I click on "[data-action='local_coursegen/template/add-chooser-option'][data-modname='page']" "css_element"
    Then "#tplChooserPromptPanel" "css_element" should be visible
    And I should see "Page" in the "[data-region='local_coursegen/template/chooser-selected-name']" "css_element"
    And "#tplActivityChooserModal" "css_element" should be visible

  Scenario: Confirming the panel adds the activity and closes the modal
    Given I click on "[data-action='local_coursegen/template/open-chooser']" "css_element"
    And I click on "[data-action='local_coursegen/template/add-chooser-option'][data-modname='page']" "css_element"
    When I set the field with xpath "//textarea[@data-region='local_coursegen/template/chooser-prompt']" to "Explain photosynthesis"
    And I click on "[data-region='local_coursegen/template/chooser-generateimages'][value='1']" "css_element"
    And I click on "[data-region='local_coursegen/template/chooser-confirm']" "css_element"
    Then "#tplActivityChooserModal" "css_element" should not be visible
    And I should see "Page" in the "#tplModeStructure" "css_element"

  Scenario: Closing and reopening the chooser resets and hides the prompt panel
    Given I click on "[data-action='local_coursegen/template/open-chooser']" "css_element"
    And I click on "[data-action='local_coursegen/template/add-chooser-option'][data-modname='page']" "css_element"
    And I set the field with xpath "//textarea[@data-region='local_coursegen/template/chooser-prompt']" to "Draft text"
    When I click on "#tplActivityChooserModal .close" "css_element"
    And I click on "[data-action='local_coursegen/template/open-chooser']" "css_element"
    Then "#tplChooserPromptPanel" "css_element" should not be visible
    And the field with xpath "//textarea[@data-region='local_coursegen/template/chooser-prompt']" matches value ""
