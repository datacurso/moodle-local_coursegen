<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_coursegen;

use core\context\course;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\create_course_service;
use local_coursegen\tests\api_testcase;

/**
 * Tests for the creation of a Moodle course from an AI planning result.
 *
 * The result payload is handed to the service directly, as the create_course
 * web service does after fetching it, so no network request is performed.
 * Only page and label activities are used: both are created through the
 * generic module path without downloading anything.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\service\create_course_service::class)]
final class create_course_service_test extends api_testcase {
    /**
     * Log the admin in and bind the page to a course with enough sections.
     *
     * The module edit form resolves the target section through the global
     * $COURSE, which in a web request is the course being edited. The created
     * course does not exist yet when the test starts, so any course carrying
     * at least as many sections as the result is used.
     *
     * @return course_session A pending planning session of the admin.
     */
    private function prepare(): course_session {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course(['numsections' => 10]));

        return $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_course_session();
    }

    /**
     * Build a page activity result.
     *
     * @param string $name Activity name.
     * @param int $section Top-level section number.
     * @param string|null $subsectionid Declared subsection the activity belongs to.
     * @return array
     */
    private function page_activity(string $name, int $section, ?string $subsectionid = null): array {
        $activity = [
            'resource_type' => 'page',
            'parameters' => [
                'modulename' => 'page',
                'name' => $name,
                'section' => $section,
                'introeditor' => ['text' => '<p>Intro of ' . $name . '</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'page' => ['text' => '<p>Content of ' . $name . '</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'display' => 0,
                'printintro' => 1,
                'printlastmodified' => 1,
                'visible' => 1,
                'cmidnumber' => '',
                // The AI result always carries mod_settings (empty for modules without extra settings).
                'mod_settings' => [],
            ],
        ];
        if ($subsectionid !== null) {
            $activity['subsection_id'] = $subsectionid;
        }

        return $activity;
    }

    /**
     * Build a label activity result.
     *
     * @param string $text Label text.
     * @param int $section Top-level section number.
     * @param string|null $subsectionid Declared subsection the activity belongs to.
     * @return array
     */
    private function label_activity(string $text, int $section, ?string $subsectionid = null): array {
        $activity = [
            'resource_type' => 'label',
            'parameters' => [
                'modulename' => 'label',
                'name' => $text,
                'section' => $section,
                'introeditor' => ['text' => '<p>' . $text . '</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'cmidnumber' => '',
                // The AI result always carries mod_settings (empty for modules without extra settings).
                'mod_settings' => [],
            ],
        ];
        if ($subsectionid !== null) {
            $activity['subsection_id'] = $subsectionid;
        }

        return $activity;
    }

    /**
     * Section rows of a course keyed by section number.
     *
     * @param int $courseid Course id.
     * @return array<int, \stdClass>
     */
    private function sections(int $courseid): array {
        global $DB;

        return array_column($DB->get_records('course_sections', ['course' => $courseid], 'section ASC'), null, 'section');
    }

    /**
     * Course modules of a course keyed by name, in course order.
     *
     * Labels take their name from their text, so the key is the label text.
     *
     * @param int $courseid Course id.
     * @return array<string, \cm_info>
     */
    private function modules_by_name(int $courseid): array {
        $modules = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            $modules[trim($cm->name)] = $cm;
        }

        return $modules;
    }

    /**
     * Whether subsections can be materialised on this site.
     *
     * @return bool
     */
    private function subsections_available(): bool {
        return array_key_exists('subsection', \core_plugin_manager::instance()->get_enabled_plugins('mod'));
    }

    /**
     * The course takes its identity from the AI configuration and the session is bound to it.
     */
    public function test_course_created_from_configuration(): void {
        global $DB;

        $session = $this->prepare();
        $category = $this->getDataGenerator()->create_category();

        $sink = $this->redirectEvents();
        $result = create_course_service::create_course($session, [
            'course_configuration' => [
                'fullname' => 'Photosynthesis for beginners',
                'shortname' => 'photo101',
                'category' => (int)$category->id,
            ],
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['partial']);
        $this->assertFalse($result['haswarnings']);
        $this->assertSame(0, $result['warningscount']);
        $this->assertSame(get_string('coursecreated', 'local_coursegen'), $result['message']);
        $this->assertSame([], $result['activityerrors']);

        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertSame('Photosynthesis for beginners', $course->fullname);
        $this->assertSame('photo101', $course->shortname);
        $this->assertEquals($category->id, $course->category);
        $this->assertSame($course->shortname, $result['shortname']);
        $this->assertSame($course->fullname, $result['fullname']);
        $this->assertSame(course_get_url($course->id)->out(), $result['courseurl']);

        // The session now points at the created course.
        $stored = new course_session($session->get('id'));
        $this->assertEquals($course->id, $stored->get('courseid'));
        $this->assertEquals(course_session::STATUS_CREATED, $stored->get('status'));

        // The creation is audited in the new course context.
        $applied = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof event\generation_result_applied;
        }));
        $sink->close();
        $this->assertCount(1, $applied);
        $this->assertEquals(course::instance($course->id)->id, $applied[0]->get_context()->id);
        $this->assertEquals($course->id, $applied[0]->other['courseid']);
    }

    /**
     * Without a configuration the course gets the default name, a timestamped shortname
     * and the default category.
     */
    public function test_course_identity_falls_back_to_defaults(): void {
        global $DB;

        $session = $this->prepare();

        $result = create_course_service::create_course($session, ['course_configuration' => ['fullname' => '  ']]);

        $this->assertTrue($result['success'], $result['message']);
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertSame(get_string('createwithai', 'local_coursegen'), $course->fullname);
        $this->assertStringStartsWith('courseai-', $course->shortname);
        $this->assertEquals(\core_course_category::get_default()->id, $course->category);
    }

    /**
     * User overrides win over the AI configuration, and a taken shortname gets a suffix.
     */
    public function test_overrides_take_precedence_and_shortname_is_made_unique(): void {
        global $DB;

        $session = $this->prepare();
        $category = $this->getDataGenerator()->create_category();
        $this->getDataGenerator()->create_course(['shortname' => 'reviewed']);

        $result = create_course_service::create_course(
            $session,
            ['course_configuration' => ['fullname' => 'AI name', 'shortname' => 'ainame', 'category' => 1]],
            ['fullname' => 'Reviewed name', 'shortname' => ' reviewed ', 'category' => (int)$category->id]
        );

        $this->assertTrue($result['success'], $result['message']);
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertSame('Reviewed name', $course->fullname);
        $this->assertSame('reviewed-1', $course->shortname);
        $this->assertEquals($category->id, $course->category);
    }

    /**
     * Sections are created in order with their names and the format knows how many there are.
     */
    public function test_sections_created_in_order_with_names(): void {
        $session = $this->prepare();

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Sectioned course', 'shortname' => 'sectioned'],
            'sections_info' => [
                ['section' => 2, 'name' => 'Second week'],
                ['section' => 1, 'name' => 'First week'],
                ['section' => 3, 'name' => 'Third week'],
            ],
        ]);

        $this->assertTrue($result['success'], $result['message']);

        $sections = $this->sections((int)$result['courseid']);
        $this->assertSame([0, 1, 2, 3], array_keys($sections));
        $this->assertSame('First week', $sections[1]->name);
        $this->assertSame('Second week', $sections[2]->name);
        $this->assertSame('Third week', $sections[3]->name);
        foreach ([1, 2, 3] as $number) {
            $this->assertEquals(1, $sections[$number]->visible);
            $this->assertSame('', (string)$sections[$number]->sequence);
        }
    }

    /**
     * Each activity lands in its section, in result order, and the section sequences are consistent.
     */
    public function test_activities_created_in_their_sections(): void {
        global $DB;

        $session = $this->prepare();

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Course with activities', 'shortname' => 'activities'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Theory'],
                ['section' => 2, 'name' => 'Practice'],
            ],
            'generated_activities' => [
                $this->page_activity('Reading', 1),
                $this->label_activity('Practice intro', 2),
                $this->page_activity('Exercise', 2),
            ],
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['partial']);

        $courseid = (int)$result['courseid'];
        $modules = $this->modules_by_name($courseid);
        $this->assertSame(['Reading', 'Practice intro', 'Exercise'], array_keys($modules));
        $this->assertEquals(1, $modules['Reading']->sectionnum);
        $this->assertEquals(2, $modules['Practice intro']->sectionnum);
        $this->assertEquals(2, $modules['Exercise']->sectionnum);
        $this->assertSame('page', $modules['Reading']->modname);
        $this->assertSame('label', $modules['Practice intro']->modname);

        // The page content reached the module instance.
        $page = $DB->get_record('page', ['id' => $modules['Reading']->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('Content of Reading', $page->content);

        // Section sequences list exactly the modules of the section, in result order.
        $sections = $this->sections($courseid);
        $this->assertSame('', (string)$sections[0]->sequence);
        $this->assertSame((string)$modules['Reading']->id, $sections[1]->sequence);
        $this->assertSame(
            $modules['Practice intro']->id . ',' . $modules['Exercise']->id,
            $sections[2]->sequence
        );
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * Declared subsections are materialised where their first activity appears, carrying
     * name and description, and the nested activities are created inside them.
     */
    public function test_subsections_materialised_in_presentation_order(): void {
        global $DB;

        if (!$this->subsections_available()) {
            $this->markTestSkipped('mod_subsection is not available on this site.');
        }

        $session = $this->prepare();

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Course with subsections', 'shortname' => 'subsections'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Week 1'],
            ],
            'subsections_info' => [
                ['id' => 'sub-lab', 'name' => 'Lab work', 'description' => '<p>Hands on</p>', 'parent_section' => 1],
                ['id' => 'sub-empty', 'name' => 'Further reading', 'description' => '', 'parent_section' => 1],
            ],
            'generated_activities' => [
                $this->page_activity('Lecture', 1),
                $this->page_activity('Lab sheet', 1, 'sub-lab'),
                $this->label_activity('Lab note', 1, 'sub-lab'),
                $this->label_activity('Wrap up', 1),
            ],
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['partial']);

        $courseid = (int)$result['courseid'];
        $modules = $this->modules_by_name($courseid);
        $delegated = $DB->get_records('course_sections', ['course' => $courseid, 'component' => 'mod_subsection'], 'section ASC');
        $this->assertCount(2, $delegated);
        $delegatedbyname = array_column($delegated, null, 'name');
        $this->assertArrayHasKey('Lab work', $delegatedbyname);
        $this->assertArrayHasKey('Further reading', $delegatedbyname);
        $this->assertStringContainsString('Hands on', $delegatedbyname['Lab work']->summary);

        // Nested activities live in the delegated section of their subsection.
        $labsection = (int)$delegatedbyname['Lab work']->section;
        $this->assertEquals($labsection, $modules['Lab sheet']->sectionnum);
        $this->assertEquals($labsection, $modules['Lab note']->sectionnum);
        $this->assertSame(
            $modules['Lab sheet']->id . ',' . $modules['Lab note']->id,
            $delegatedbyname['Lab work']->sequence
        );
        $this->assertSame('', (string)$delegatedbyname['Further reading']->sequence);

        // In the parent section the subsection module sits where its first activity appeared,
        // and the empty subsection is appended at the end.
        $subsectioncms = [];
        foreach ($modules as $cm) {
            if ($cm->modname === 'subsection') {
                $subsectioncms[$cm->name] = $cm;
            }
        }
        $this->assertEquals(1, $modules['Lecture']->sectionnum);
        $this->assertEquals(1, $modules['Wrap up']->sectionnum);
        $this->assertSame(
            implode(',', [
                $modules['Lecture']->id,
                $subsectioncms['Lab work']->id,
                $modules['Wrap up']->id,
                $subsectioncms['Further reading']->id,
            ]),
            $this->sections($courseid)[1]->sequence
        );
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * When mod_subsection is disabled the nested activities flatten into the parent section in order.
     */
    public function test_subsections_flatten_when_module_disabled(): void {
        global $DB;

        if (!$this->subsections_available()) {
            $this->markTestSkipped('mod_subsection is not available on this site.');
        }

        $session = $this->prepare();
        $DB->set_field('modules', 'visible', 0, ['name' => 'subsection']);
        \core_plugin_manager::reset_caches(true);

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Flat course', 'shortname' => 'flat'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Week 1'],
            ],
            'subsections_info' => [
                ['id' => 'sub-lab', 'name' => 'Lab work', 'description' => '', 'parent_section' => 1],
            ],
            'generated_activities' => [
                $this->page_activity('Lecture', 1),
                $this->page_activity('Lab sheet', 1, 'sub-lab'),
                $this->label_activity('Wrap up', 1),
            ],
        ]);
        // The degradation is reported to developers once.
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['partial'], 'Flattening is not an activity error.');

        $courseid = (int)$result['courseid'];
        $this->assertSame(0, $DB->count_records('course_sections', ['course' => $courseid, 'component' => 'mod_subsection']));
        $subsectionmoduleid = $DB->get_field('modules', 'id', ['name' => 'subsection'], MUST_EXIST);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $courseid, 'module' => $subsectionmoduleid]));

        $modules = $this->modules_by_name($courseid);
        $this->assertSame(['Lecture', 'Lab sheet', 'Wrap up'], array_keys($modules));
        foreach ($modules as $cm) {
            $this->assertEquals(1, $cm->sectionnum);
        }
        $this->assertSame(
            implode(',', [$modules['Lecture']->id, $modules['Lab sheet']->id, $modules['Wrap up']->id]),
            $this->sections($courseid)[1]->sequence
        );
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * A failing activity is collected as an error while the rest of the course is still created.
     */
    public function test_activity_failure_is_collected_without_aborting(): void {
        $session = $this->prepare();

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Partially created', 'shortname' => 'partial'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Week 1'],
            ],
            'generated_activities' => [
                $this->page_activity('Before failure', 1),
                [
                    'resource_type' => 'nonexistentmodule',
                    'parameters' => [
                        'modulename' => 'nonexistentmodule',
                        'name' => 'Broken activity',
                        'section' => 1,
                        'mod_settings' => [],
                    ],
                ],
                $this->label_activity('After failure', 1),
            ],
        ]);
        // One debugging call for the skipped module and one for the course summary.
        $this->assertDebuggingCalledCount(2);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertTrue($result['partial']);
        $this->assertTrue($result['haswarnings']);
        $this->assertSame(1, $result['warningscount']);
        $this->assertSame(
            get_string('coursecreated', 'local_coursegen') . ' ' . get_string('coursecreated_partial', 'local_coursegen'),
            $result['message']
        );
        $this->assertSame([
            [
                'resource_type' => 'nonexistentmodule',
                'section' => 1,
                'message' => get_string('error_activity_creation_failed', 'local_coursegen'),
                'title' => 'Broken activity',
            ],
        ], $result['activityerrors']);

        $courseid = (int)$result['courseid'];
        $modules = $this->modules_by_name($courseid);
        $this->assertSame(['Before failure', 'After failure'], array_keys($modules));
        $this->assertEquals(course_session::STATUS_CREATED, (new course_session($session->get('id')))->get('status'));
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * A failure while building the course structure marks the session failed and reports
     * the localized error without technical detail.
     */
    public function test_structure_failure_marks_session_failed(): void {
        global $DB;

        $session = $this->prepare();
        $coursesbefore = $DB->count_records('course');

        $sink = $this->redirectEvents();
        // Two rows for the same section number violate the course section uniqueness.
        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Broken structure', 'shortname' => 'broken'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Week 1'],
                ['section' => 1, 'name' => 'Week 1 again'],
            ],
        ]);
        $debuggings = $this->getDebuggingMessages();
        $this->resetDebugging();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['courseid']);
        $this->assertSame(get_string('error_course_creation_failed', 'local_coursegen'), $result['message']);
        $this->assertFalse($result['partial']);
        $this->assertNotEmpty($debuggings, 'The technical detail is kept in developer debugging.');

        $this->assertEquals(course_session::STATUS_FAILED, (new course_session($session->get('id')))->get('status'));

        $failed = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof event\generation_failed;
        }));
        $sink->close();
        $this->assertCount(1, $failed);

        // The course itself was created before the structure failed: it stays, bound to the session.
        $this->assertSame($coursesbefore + 1, $DB->count_records('course'));
    }

    /**
     * Run a callback once the module named $marker is created, before the course
     * structure is finalised.
     *
     * The callback receives the course module id and the course id. It runs from a
     * course_module_created observer, so it is the only seam between the activity
     * creation and the structure check of create_course().
     *
     * @param string $marker Name of the activity whose creation triggers the callback.
     * @param callable $callback function (int $cmid, int $courseid): void
     * @return void
     */
    private function on_module_created(string $marker, callable $callback): void {
        \core\event\manager::phpunit_replace_observers([
            [
                'eventname' => '\core\event\course_module_created',
                'callback' => function (\core\event\course_module_created $event) use ($marker, $callback): void {
                    if ((string)($event->other['name'] ?? '') === $marker) {
                        $callback((int)$event->objectid, (int)$event->courseid);
                    }
                },
            ],
        ]);
    }

    /**
     * A result with two sections and three page/label activities, the last one named "Exercise".
     *
     * @param string $shortname Course shortname.
     * @return array
     */
    private function result_with_activities(string $shortname): array {
        return [
            'course_configuration' => ['fullname' => 'Course ' . $shortname, 'shortname' => $shortname],
            'sections_info' => [
                ['section' => 1, 'name' => 'Theory'],
                ['section' => 2, 'name' => 'Practice'],
            ],
            'generated_activities' => [
                $this->page_activity('Reading', 1),
                $this->label_activity('Practice intro', 2),
                $this->page_activity('Exercise', 2),
            ],
        ];
    }

    /**
     * A module id that does not exist, left in a section sequence, is removed before the
     * course is handed back, and the repair is reported to developers.
     */
    public function test_orphaned_sequence_reference_is_removed(): void {
        global $DB;

        $session = $this->prepare();
        $this->on_module_created('Exercise', static function (int $cmid, int $courseid) use ($DB): void {
            $sectionid = $DB->get_field('course_modules', 'section', ['id' => $cmid], MUST_EXIST);
            $sequence = $DB->get_field('course_sections', 'sequence', ['id' => $sectionid], MUST_EXIST);
            $DB->set_field('course_sections', 'sequence', $sequence . ',999999', ['id' => $sectionid]);
        });

        $result = create_course_service::create_course($session, $this->result_with_activities('orphanref'));
        $this->assertDebuggingCalled(
            'local_coursegen: repaired the course structure while creating course ' . $result['courseid'] . '. Repairs: 1'
        );

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['partial']);
        $courseid = (int)$result['courseid'];
        $modules = $this->modules_by_name($courseid);
        $this->assertSame(
            $modules['Practice intro']->id . ',' . $modules['Exercise']->id,
            $this->sections($courseid)[2]->sequence
        );
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * A module dropped from every section sequence is put back into the section its
     * course_modules row points to.
     */
    public function test_module_missing_from_sequences_is_restored(): void {
        global $DB;

        $session = $this->prepare();
        $this->on_module_created('Exercise', static function (int $cmid, int $courseid) use ($DB): void {
            $sectionid = $DB->get_field('course_modules', 'section', ['id' => $cmid], MUST_EXIST);
            $sequence = $DB->get_field('course_sections', 'sequence', ['id' => $sectionid], MUST_EXIST);
            $ids = array_diff(explode(',', $sequence), [(string)$cmid]);
            $DB->set_field('course_sections', 'sequence', implode(',', $ids), ['id' => $sectionid]);
        });

        $result = create_course_service::create_course($session, $this->result_with_activities('missingref'));
        $this->assertDebuggingCalled(
            'local_coursegen: repaired the course structure while creating course ' . $result['courseid'] . '. Repairs: 1'
        );

        $this->assertTrue($result['success'], $result['message']);
        $courseid = (int)$result['courseid'];
        $modules = $this->modules_by_name($courseid);
        $this->assertEquals(2, $modules['Exercise']->sectionnum);
        $this->assertSame(
            $modules['Practice intro']->id . ',' . $modules['Exercise']->id,
            $this->sections($courseid)[2]->sequence
        );
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * A module whose course_modules.section points to another section than the one listing
     * it is re-pointed to the section of its sequence.
     */
    public function test_module_section_column_is_realigned_with_sequence(): void {
        global $DB;

        $session = $this->prepare();
        $this->on_module_created('Exercise', static function (int $cmid, int $courseid) use ($DB): void {
            $wrongsection = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => 1], MUST_EXIST);
            $DB->set_field('course_modules', 'section', $wrongsection, ['id' => $cmid]);
        });

        $result = create_course_service::create_course($session, $this->result_with_activities('wrongsection'));
        // The column is realigned by the integrity check that rebuild_course_cache() runs after the
        // module creation, which reports it as a developer notice; the service-side check then has
        // nothing left to repair. Only the outcome is asserted here.
        $this->resetDebugging();

        $this->assertTrue($result['success'], $result['message']);
        $courseid = (int)$result['courseid'];
        $sections = $this->sections($courseid);
        $modules = $this->modules_by_name($courseid);
        $this->assertEquals($sections[2]->id, $modules['Exercise']->section);
        $this->assertEquals($sections[2]->id, $DB->get_field('course_modules', 'section', ['id' => $modules['Exercise']->id]));
        $this->assert_sequences_consistent($courseid);
    }

    /**
     * When a listed module cannot be resolved by modinfo even after the integrity repair
     * (its module type was disabled meanwhile), the creation fails and the session is marked failed.
     */
    public function test_unresolvable_module_fails_the_creation(): void {
        global $DB;

        $session = $this->prepare();
        $this->on_module_created('Exercise', static function (int $cmid, int $courseid) use ($DB): void {
            $DB->set_field('modules', 'visible', 0, ['name' => 'page']);
            \core_plugin_manager::reset_caches(true);
        });

        $result = create_course_service::create_course($session, $this->result_with_activities('unresolvable'));
        $this->resetDebugging();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['courseid']);
        $this->assertSame(get_string('error_course_creation_failed', 'local_coursegen'), $result['message']);
        $this->assertEquals(course_session::STATUS_FAILED, (new course_session($session->get('id')))->get('status'));
    }

    /**
     * Assert that every section sequence of the course references existing modules of that
     * section only, and that modinfo resolves each of them.
     *
     * @param int $courseid Course id.
     * @return void
     */
    private function assert_sequences_consistent(int $courseid): void {
        global $DB;

        $cms = get_fast_modinfo($courseid)->get_cms();
        $seen = [];
        foreach ($this->sections($courseid) as $section) {
            if (trim((string)$section->sequence) === '') {
                continue;
            }
            foreach (explode(',', $section->sequence) as $cmid) {
                $cmid = (int)$cmid;
                $this->assertArrayHasKey($cmid, $cms, "Module $cmid listed in section {$section->section} must resolve.");
                $this->assertEquals($section->id, $cms[$cmid]->section, "Module $cmid must belong to its section.");
                $this->assertArrayNotHasKey($cmid, $seen, "Module $cmid must appear in one sequence only.");
                $seen[$cmid] = true;
            }
        }
        $this->assertCount($DB->count_records('course_modules', ['course' => $courseid]), $seen);
    }
}
