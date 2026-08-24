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

use aiprovider_datacurso\httpclient\ai_course_api;
use core_course_category;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\create_course_service;

/**
 * Tests for course creation from the AI planning result.
 *
 * The AI result data is crafted in each test, so no network request is ever
 * performed: create_course_service only processes an already-fetched result.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_service_test extends \advanced_testcase {
    /**
     * Give the front page the section rows a real site has.
     *
     * The module edit form used during activity creation resolves section info
     * against the page course; without these rows the target section cannot
     * resolve (same mitigation as create_mod_permissions_test).
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/course/lib.php');
        require_once(__DIR__ . '/fixtures/testable_create_course_service.php');
        course_create_sections_if_missing(get_site(), range(0, 8));
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        testable_create_course_service::$forcedunresolvedcount = null;
        parent::tearDown();
    }

    /**
     * Create a planning session persistent owned by the given user.
     *
     * @param int $userid Owner user id.
     * @param array $coursedata Stored wizard data for the session.
     * @return course_session
     */
    private function make_session(int $userid, array $coursedata = []): course_session {
        $session = new course_session(0, (object)[
            'userid' => $userid,
            'session_id' => 'thread-' . uniqid(),
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode($coursedata),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Build a minimal label activity entry as produced by the AI result.
     *
     * @param int $section Parent section number.
     * @param string $name Activity name.
     * @param string|null $subsectionid Optional declared subsection id.
     * @return array
     */
    private function label_activity(int $section, string $name, ?string $subsectionid = null): array {
        $activity = [
            'resource_type' => 'label',
            'parameters' => [
                'modulename' => 'label',
                'name' => $name,
                'introeditor' => ['text' => '<p>' . $name . '</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'cmidnumber' => '',
                'section' => $section,
                'mod_settings' => [],
            ],
        ];
        if ($subsectionid !== null) {
            $activity['subsection_id'] = $subsectionid;
        }

        return $activity;
    }

    /**
     * Change the enabled state of the subsection activity module.
     *
     * @param int $visible 1 to enable the module, 0 to disable it.
     * @return void
     */
    private function set_subsection_module_visibility(int $visible): void {
        global $DB;

        $DB->set_field('modules', 'visible', $visible, ['name' => 'subsection']);
        \core_plugin_manager::reset_caches();
    }

    /**
     * Skip the current test when mod_subsection is not installed.
     *
     * @return void
     */
    private function require_subsection_module(): void {
        if (!\core_plugin_manager::instance()->get_plugin_info('mod_subsection')) {
            $this->markTestSkipped('mod_subsection no esta instalado en este sitio.');
        }
        $this->set_subsection_module_visibility(1);
    }

    /**
     * Get the delegated (subsection) section records of a course, ordered.
     *
     * @param int $courseid Course id.
     * @return array
     */
    private function get_delegated_sections(int $courseid): array {
        global $DB;

        return array_values($DB->get_records(
            'course_sections',
            ['course' => $courseid, 'component' => 'mod_subsection'],
            'section ASC'
        ));
    }

    /**
     * Get the ordered activity names of one section of a course.
     *
     * @param int $courseid Course id.
     * @param int $sectionnum Section number.
     * @return array Array of [modname, name] pairs in sequence order.
     */
    private function get_section_activities(int $courseid, int $sectionnum): array {
        $modinfo = get_fast_modinfo(get_course($courseid));
        $sections = $modinfo->get_sections();

        $result = [];
        foreach ($sections[$sectionnum] ?? [] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $result[] = [$cm->modname, $cm->get_name()];
        }

        return $result;
    }

    /**
     * MDL-INT-019: The fullname, shortname and category modified by the teacher
     * prevail over the values generated by the AI.
     */
    public function test_teacher_overrides_prevail_over_ai_values(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $session = $this->make_session(get_admin()->id);

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Nombre generado por la IA',
                'shortname' => 'ia-corto',
                'category' => (int)core_course_category::get_default()->id,
            ],
        ];
        $overrides = [
            'fullname' => 'Nombre del docente',
            'shortname' => 'docente-corto',
            'category' => (int)$category->id,
        ];

        $result = create_course_service::create_course($session, $resultdata, $overrides);

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertSame('Nombre del docente', $course->fullname);
        $this->assertSame('docente-corto', $course->shortname);
        $this->assertSame((int)$category->id, (int)$course->category);
    }

    /**
     * MDL-INT-019: Without an AI course configuration the fallback values are
     * used: generic name, timestamped shortname and the site default category.
     */
    public function test_fallback_values_without_ai_configuration(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);

        $result = create_course_service::create_course($session, []);

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertSame(get_string('createwithai', 'local_coursegen'), $course->fullname);
        $this->assertStringStartsWith('courseai-', $course->shortname);
        $this->assertSame((int)core_course_category::get_default()->id, (int)$course->category);
    }

    /**
     * MDL-INT-019: The fullname is truncated to 255 characters and the
     * shortname to 100, verified through the public settings preview used by
     * the final review panel.
     */
    public function test_fullname_and_shortname_truncated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);

        $settings = create_course_service::get_course_settings($session, [
            'course_configuration' => [
                'fullname' => str_repeat('A', 300),
                'shortname' => str_repeat('B', 150),
            ],
        ]);

        $this->assertSame(str_repeat('A', 255), $settings['fullname']);
        $this->assertSame(str_repeat('B', 100), $settings['shortname']);
    }

    /**
     * MDL-INT-019: An already existing shortname is made unique by appending
     * numbered suffixes, so the shortname is always unique on the site.
     */
    public function test_existing_shortname_gets_numbered_suffix(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_course(['shortname' => 'dup']);
        $resultdata = ['course_configuration' => ['fullname' => 'Curso duplicado', 'shortname' => 'dup']];

        $first = create_course_service::create_course($this->make_session(get_admin()->id), $resultdata);
        $second = create_course_service::create_course($this->make_session(get_admin()->id), $resultdata);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertSame('dup-1', $DB->get_field('course', 'shortname', ['id' => $first['courseid']]));
        $this->assertSame('dup-2', $DB->get_field('course', 'shortname', ['id' => $second['courseid']]));
    }

    /**
     * MDL-INT-020: Every planned section is created with its name in the right
     * position and the section count matches the approved plan.
     */
    public function test_sections_created_with_names_positions_and_count(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso con secciones', 'shortname' => 'secciones-1'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Introduccion'],
                ['section' => 2, 'name' => 'Desarrollo'],
                ['section' => 3, 'name' => 'Cierre'],
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));

        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0 AND component IS NULL',
            [$result['courseid']],
            'section ASC'
        );
        $this->assertCount(3, $sections);

        $names = array_map(static function (\stdClass $section): array {
            return [(int)$section->section, $section->name];
        }, array_values($sections));
        $this->assertSame([[1, 'Introduccion'], [2, 'Desarrollo'], [3, 'Cierre']], $names);
    }

}
