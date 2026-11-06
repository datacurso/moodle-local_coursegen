@local @local_coursegen
Feature: Create course with AI is offered only to users holding the full creation permission set
  In order to only start course creations the site will accept
  As a user who may or may not create a complete course with AI
  I need the "Create with AI" button and page to follow the full creation permission set

  # Ticket #9539. The full set is a fixed list of four capabilities:
  # moodle/course:create, moodle/course:view, local/coursegen:createcoursewithai
  # and local/coursegen:createactivitywithai. It does not depend on the
  # editingteacher archetype, so the "aionly" role below, with no archetype and
  # only those four, holds the full set; the "partial" role lacks
  # moodle/course:view, enough for the old two-capability check and nothing more.
  # These scenarios need neither the live AI service nor JavaScript: the button
  # is spliced server side into My courses and the page refuses before rendering.

  Background:
    Given the following config values are set as admin:
      | enablemycourses | 1 |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | creator1 | Cara      | Creator  | creator1@example.com |
      | partial1 | Pat       | Partial  | partial1@example.com |
    And the following "roles" exist:
      | shortname | name               | archetype |
      | aionly    | AI creator         |           |
      | partial   | Partial AI creator |           |
    And the following "role capabilities" exist:
      | role   | moodle/course:create | moodle/course:view | local/coursegen:createcoursewithai | local/coursegen:createactivitywithai |
      | aionly | allow                | allow              | allow                              | allow                                |
    And the following "role capabilities" exist:
      | role    | moodle/course:create | local/coursegen:createcoursewithai | local/coursegen:createactivitywithai |
      | partial | allow                | allow                              | allow                                |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | creator1 | aionly  | System       |           |
      | partial1 | partial | System       |           |

  Scenario: A user holding only part of the creation set gets neither the button nor the page
    Given I log in as "partial1"
    When I visit "/my/courses.php"
    Then "Create with AI" "button" should not exist
    And "#local_coursegen_aicourseform" "css_element" should not exist
    When I visit "/local/coursegen/aicoursecreation.php"
    Then I should see "Sorry, but you do not currently have permissions to do that"

  Scenario: A user holding the full creation set sees the button and opens the page
    Given I log in as "creator1"
    When I visit "/my/courses.php"
    Then "Create with AI" "button" should exist
    And "#local_coursegen_aicourseform" "css_element" should exist
    When I visit "/local/coursegen/aicoursecreation.php"
    Then I should not see "Sorry, but you do not currently have permissions to do that"
    And I should see "Create with AI"
