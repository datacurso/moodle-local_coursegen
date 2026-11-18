@local @local_coursegen @javascript
Feature: Start screen of the AI course creation page
  In order to pick how a course is created
  As a user who can create courses in both ways
  I need the page to open on two cards and a way back to them

  Background:
    Given I log in as "admin"

  Scenario: A fresh visit opens on the two cards
    When I visit "/local/coursegen/aicoursecreation.php"
    Then I should see "How do you want to start?"
    And I should see "Free creation"
    And I should see "From template"
    And I should not see "What course do you want to create?"

  Scenario: Picking free creation opens its workspace and names the path in the top bar
    Given I visit "/local/coursegen/aicoursecreation.php"
    When I click on "Free creation" "link"
    Then I should see "What course do you want to create?"
    And I should see "Create with AI"
    And I should not see "How do you want to start?"

  Scenario: Picking from template opens the template column
    Given I visit "/local/coursegen/aicoursecreation.php"
    When I click on "From template" "link"
    Then I should see "Create course from template"
    And I should not see "How do you want to start?"

  Scenario: The old template link lands straight on the template column
    When I visit "/local/coursegen/aicoursecreation.php?mode=template"
    Then I should see "Create course from template"
    And I should not see "How do you want to start?"

  Scenario: The top bar leads back to the cards
    Given I visit "/local/coursegen/aicoursecreation.php?mode=free"
    When I click on "Create with AI" "button"
    Then I should see "How do you want to start?"
