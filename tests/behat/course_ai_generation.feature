@local @local_coursegen @javascript @coursegen_requires_ai_service
Feature: AI course generation including H5P activities
  In order to build complete courses with AI
  As a manager
  I need the course flow to create H5P activities like the individual flow and tolerate partial failures

  # =========================================================================
  # SERVICE-DEPENDENT SCENARIOS — @coursegen_requires_ai_service
  #
  # This flow needs the live DataCurso AI service (valid aiprovider_datacurso
  # license key and reachable service URLs). It MUST be excluded in CI:
  #   vendor/bin/behat --tags='~@coursegen_requires_ai_service'
  #
  # It serves as a scripted manual / E2E-with-service run. Course generation
  # takes several minutes: run with an increased step timeout
  # ($CFG->behat_increasetimeout) or follow the steps manually.
  # =========================================================================

  @SYS-E2E-006
  Scenario: A generated course creates its H5P activities and reports per-activity errors
    Given I log in as "admin"
    And I visit "/my/courses.php"
    When I click on "Create with AI" "button"
    Then I should see "What course do you want to create?"
    When I set the field with xpath "//textarea[@id='promptInput']" to "Create a short course about first aid with two sections. Include one H5P crossword activity and one H5P question set activity in different sections."
    And I press "Generate"
    # Planning runs against the live service (several minutes).
    Then "Generate course" "button" should exist
    When I press "Generate course"
    And I press "Create course"
    Then I should see "Course generated successfully!"
    And "Open course" "button" should exist
    # Manual verification:
    #   1. Open the course: both H5P activities exist in their planned sections
    #      and play correctly (packages attached, no broken resources).
    #   2. Fault injection (step 2 of SYS-E2E-006) needs service-side control:
    #      force one of the H5P activities to fail during generation and verify
    #      that the failure is reported as a per-activity error while the rest
    #      of the course is still created.
    # NOTA corregido (14/08/2026) (API-CTR-001, related): the course-start
    # request now includes the site H5P framework version on both sides of the
    # contract, so course-flow packages use the site's library set.

  @SYS-E2E-007
  Scenario: Course-flow H5P packages play on sites with different H5P framework versions
    # Environment-dependent companion of the individual-flow scenario in
    # activity_ai_generation.feature: repeat the SYS-E2E-006 scenario above on
    # a Moodle 4.5 site and on a Moodle 5.x site and verify each site receives
    # packages it can play. Kept as a scripted manual run because it needs two
    # differently-versioned sites.
    Given I log in as "admin"
    And I visit "/my/courses.php"
    Then "Create with AI" "button" should exist
    # Manual verification (per site): generate the same activity on both sites
    # and compare the packaged library versions against each site's H5P
    # framework; both must reproduce without errors.
