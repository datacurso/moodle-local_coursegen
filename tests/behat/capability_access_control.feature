@local @local_coursegen
Feature: Capabilities control AI feature access per role
  In order to restrict AI features to appropriate roles
  As an admin
  I need capabilities to gate access independently from admin settings

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username  | firstname   | lastname | email                 |
      | manager1  | Manager     | One      | manager1@example.com  |
      | teacher1  | Teacher     | One      | teacher1@example.com  |
      | neteacher | NonEditing  | Teacher  | neteacher@example.com |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | manager1  | C1     | manager        |
      | teacher1  | C1     | editingteacher |
      | neteacher | C1     | teacher        |
    And the following config values are set as admin:
      | enable_course_ai                 | 1 | local_coursegen |
      | enable_activity_ai               | 1 | local_coursegen |
      | enable_empty_course_ai           | 1 | local_coursegen |
      | enable_course_image_generation   | 1 | local_coursegen |
      | enable_activity_image_generation | 1 | local_coursegen |

  # --- AI activity button: editingteacher and manager have createactivitywithai, non-editing teacher does not ---

  @javascript
  Scenario: Non-editing teacher does not see AI activity button on course page
    When I log in as "neteacher"
    And I am on "Course 1" course homepage
    Then "button[data-action='local_coursegen/add_activity_ai']" "css_element" should not exist

  @javascript
  Scenario: Editing teacher sees AI activity button on course page
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    Then "button[data-action='local_coursegen/add_activity_ai']" "css_element" should exist

  @javascript
  Scenario: Manager sees AI activity button on course page
    When I log in as "manager1"
    And I am on "Course 1" course homepage with editing mode on
    Then "button[data-action='local_coursegen/add_activity_ai']" "css_element" should exist

  # --- AI course button: only manager has createcoursewithai ---

  @javascript
  Scenario: Editing teacher does not see AI course button on new course form
    Given the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |
    And the following "permission overrides" exist:
      | capability           | permission | role           | contextlevel | reference |
      | moodle/course:create | Allow      | editingteacher | System       |           |
    When I log in as "teacher1"
    And I visit "/course/edit.php?category=1"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should not exist

  @javascript
  Scenario: Editing teacher does not see AI course button on empty course edit form
    When I am on the "Course 1" "course editing" page logged in as "teacher1"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should not exist

  @javascript
  Scenario: Manager sees AI course button on empty course edit form
    When I am on the "Course 1" "course editing" page logged in as "manager1"
    Then "button[data-action='local_coursegen/add_ai_course']" "css_element" should exist

  # --- Image generation in course form: only manager has generatecourseimages ---

  @javascript
  Scenario: Editing teacher does not see image generation on course edit form
    When I am on the "Course 1" "course editing" page logged in as "teacher1"
    Then I should not see "Images for the course"

  @javascript
  Scenario: Manager sees image generation select on course edit form
    When I am on the "Course 1" "course editing" page logged in as "manager1"
    Then I should see "Images for the course"

  # --- Image generation in activity modal: only manager has generateactivityimages ---

  @javascript
  Scenario: Editing teacher does not see image generation option in activity modal
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "button[data-action='local_coursegen/add_activity_ai']" "css_element"
    And I wait "2" seconds
    Then I should not see "Generate images"

  @javascript
  Scenario: Manager sees image generation option in activity modal
    When I log in as "manager1"
    And I am on "Course 1" course homepage with editing mode on
    And I click on "button[data-action='local_coursegen/add_activity_ai']" "css_element"
    And I wait "2" seconds
    Then I should see "Generate images"
    And I should see "Do not generate images"
