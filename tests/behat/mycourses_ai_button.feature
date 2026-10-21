@local @local_coursegen
Feature: Create with AI button on the My courses page
  In order to start creating a course with AI
  As a user allowed to create courses with AI
  I need the "Create with AI" button next to the course actions on My courses

  # These scenarios do NOT need the live DataCurso AI service nor JavaScript:
  # the button is spliced server side into the page HTML by the
  # before_http_headers hook, so it is present in the initial response. On
  # Moodle 4.5/5.0 the course action buttons live in the page header; on
  # Moodle 5.2 they live inside the Course overview block. The assertions below
  # are written without a region so they pass on every supported version.

  Background:
    Given the following config values are set as admin:
      | enablemycourses | 1 |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | creator1 | Cara      | Creator  | creator1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    # The full creation set (ticket #9539) is the editingteacher archetype plus four
    # capabilities; the stock editingteacher role extended with them holds it.
    And the following "role capabilities" exist:
      | role           | moodle/course:create | moodle/course:view | local/coursegen:createcoursewithai | local/coursegen:createactivitywithai |
      | editingteacher | allow                | allow              | allow                              | allow                                |
    And the following "role assigns" exist:
      | user     | role           | contextlevel | reference |
      | creator1 | editingteacher | System       |           |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | admin    | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: Admin with enrolled courses sees the button next to the course actions
    Given I log in as "admin"
    When I visit "/my/courses.php"
    Then "Create with AI" "button" should exist
    And "#local_coursegen_aicourseform" "css_element" should exist
    And "[data-action='local_coursegen/add_ai_course']" "css_element" should exist

  Scenario: Creator without enrolled courses sees the button in the empty state
    Given I log in as "creator1"
    When I visit "/my/courses.php"
    Then "#action_bar" "css_element" should exist
    And "Create with AI" "button" should exist
    And "#local_coursegen_aicourseform" "css_element" should exist

  Scenario: Student without the createcoursewithai capability does not get the button
    Given I log in as "student1"
    When I visit "/my/courses.php"
    Then "Create with AI" "button" should not exist
    And "#local_coursegen_aicourseform" "css_element" should not exist
