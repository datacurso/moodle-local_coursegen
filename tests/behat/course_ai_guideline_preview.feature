@local @local_coursegen @javascript
Feature: Preview an institutional guideline in the course AI creation page
  In order to check what context the AI will receive
  As a course creator
  I need to preview a guideline from the guidelines popover

  # Guidelines are read from the local_coursegen_system_instruction table
  # (aicoursecreation.php), so they can be seeded without the AI service.
  # The preview dialogue is rendered with core/modal, which must work on both
  # Moodle 4.5 (Bootstrap 4) and Moodle 5.0 (Bootstrap 5).

  Background:
    Given the following "local_coursegen > system instructions" exist:
      | name           | content                                   |
      | Quality policy | All courses must include a welcome forum. |

  Scenario: The guideline preview modal shows the name, category and full content
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    When I click on "#btnPlusMenu" "css_element"
    And I click on "#btnDirectrices" "css_element"
    Then I should see "Quality policy" in the "#guidelineList" "css_element"
    When I click on "#guidelineList .pop-eye-btn" "css_element"
    Then I should see "Quality policy" in the ".modal-title" "css_element"
    And I should see "General" in the ".modal-body .preview-cat-badge" "css_element"
    And I should see "Complete content that will be sent to AI as context." in the ".modal-body" "css_element"
    And I should see "All courses must include a welcome forum." in the ".modal-body .preview-desc-box" "css_element"

  Scenario: Closing the preview modal keeps the guidelines popover usable
    Given I log in as "admin"
    And I visit "/local/coursegen/aicoursecreation.php"
    And I click on "#btnPlusMenu" "css_element"
    And I click on "#btnDirectrices" "css_element"
    And I click on "#guidelineList .pop-eye-btn" "css_element"
    And I should see "Quality policy" in the ".modal-title" "css_element"
    When I click on "Close" "button" in the ".modal-header" "css_element"
    Then ".modal-body" "css_element" should not exist
    And I should see "Quality policy" in the "#guidelineList" "css_element"
