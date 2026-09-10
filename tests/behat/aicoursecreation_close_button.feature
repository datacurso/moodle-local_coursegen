@local @local_coursegen @javascript
Feature: Close button on the AI course creation page
  In order to leave the AI course creation view
  As a user creating courses with AI
  I need a close button that returns me to My courses

  Background:
    Given I log in as "admin"

  Scenario: The close button returns to My courses from the free creation view
    Given I visit "/local/coursegen/aicoursecreation.php"
    When I click on "#courseaiCloseBtn" "css_element"
    Then I should see "My courses"

  Scenario: The close button returns to My courses from the template creation view
    Given I visit "/local/coursegen/aicoursecreation.php?mode=template"
    When I click on "#courseaiCloseBtn" "css_element"
    Then I should see "My courses"
