@local @local_coursegen
Feature: Admin settings control AI feature visibility
  In order to manage which AI features are available to users
  As an admin
  I need to toggle individual plugin features from the settings page

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |

  @javascript
  Scenario: Admin settings page shows all feature toggles
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Course Creator AI > General settings" in site administration
    Then I should see "Enable AI course creation"
    And I should see "Enable AI activity creation"
    And I should see "Enable image generation for courses"
    And I should see "Enable image generation for activities"
    And I should see "Enable AI generation for empty courses"

  @javascript
  Scenario: Development settings page shows service URL fields
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Course Creator AI > Development settings" in site administration
    Then I should see "DataCurso service URL"

  @javascript
  Scenario: AI course button appears on new course form when enabled
    Given the following config values are set as admin:
      | enable_course_ai | 1 | local_coursegen |
    When I log in as "admin"
    And I navigate to "Courses > Manage courses and categories" in site administration
    And I click on "Create new course" "link"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should exist

  @javascript
  Scenario: AI course button hidden on new course form when disabled
    Given the following config values are set as admin:
      | enable_course_ai | 0 | local_coursegen |
    When I log in as "admin"
    And I navigate to "Courses > Manage courses and categories" in site administration
    And I click on "Create new course" "link"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should not exist

  @javascript
  Scenario: Image generation select visible on course form when enabled
    Given the following config values are set as admin:
      | enable_course_ai               | 1 | local_coursegen |
      | enable_course_image_generation  | 1 | local_coursegen |
    When I log in as "admin"
    And I navigate to "Courses > Manage courses and categories" in site administration
    And I click on "Create new course" "link"
    Then I should see "Images for the course"

  @javascript
  Scenario: Image generation select hidden on course form when disabled
    Given the following config values are set as admin:
      | enable_course_ai               | 1 | local_coursegen |
      | enable_course_image_generation  | 0 | local_coursegen |
    When I log in as "admin"
    And I navigate to "Courses > Manage courses and categories" in site administration
    And I click on "Create new course" "link"
    Then I should not see "Images for the course"

  @javascript
  Scenario: AI course button appears on empty course edit form when enabled
    Given the following config values are set as admin:
      | enable_empty_course_ai | 1 | local_coursegen |
    When I am on the "Course 1" "course editing" page logged in as "admin"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should exist

  @javascript
  Scenario: AI course button hidden on empty course edit form when disabled
    Given the following config values are set as admin:
      | enable_empty_course_ai | 0 | local_coursegen |
    When I am on the "Course 1" "course editing" page logged in as "admin"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should not exist

  @javascript
  Scenario: AI course button hidden on non-empty course even when empty course setting is on
    Given the following config values are set as admin:
      | enable_empty_course_ai | 1 | local_coursegen |
    And the following "activities" exist:
      | activity | course | name       |
      | page     | C1     | Test page  |
    When I am on the "Course 1" "course editing" page logged in as "admin"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should not exist

  @javascript
  Scenario: AI activity button appears on course page when enabled
    Given the following config values are set as admin:
      | enable_activity_ai | 1 | local_coursegen |
    And I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    Then "button[data-action='local_coursegen/add_activity_ai']" "css_element" should exist

  @javascript
  Scenario: AI activity button hidden on course page when disabled
    Given the following config values are set as admin:
      | enable_activity_ai | 0 | local_coursegen |
    And I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    Then "button[data-action='local_coursegen/add_activity_ai']" "css_element" should not exist

  @javascript
  Scenario: Activity image options visible in AI modal when enabled
    Given the following config values are set as admin:
      | enable_activity_ai               | 1 | local_coursegen |
      | enable_activity_image_generation  | 1 | local_coursegen |
    And I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    When I click on "button[data-action='local_coursegen/add_activity_ai']" "css_element"
    And I wait "2" seconds
    Then I should see "Generate images"
    And I should see "Do not generate images"

  @javascript
  Scenario: Activity image options hidden in AI modal when disabled
    Given the following config values are set as admin:
      | enable_activity_ai               | 1 | local_coursegen |
      | enable_activity_image_generation  | 0 | local_coursegen |
    And I log in as "admin"
    And I am on "Course 1" course homepage with editing mode on
    When I click on "button[data-action='local_coursegen/add_activity_ai']" "css_element"
    And I wait "2" seconds
    Then I should not see "Generate images"
    And I should not see "Do not generate images"
