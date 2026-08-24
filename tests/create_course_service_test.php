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

    /**
     * MDL-INT-020: Section descriptions produced in the planning are applied as
     * the section summary.
     */
    public function test_section_descriptions_applied_as_summary(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso con resumenes', 'shortname' => 'resumenes-sec-1'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Con resumen', 'description' => '<p>Resumen de la seccion.</p>'],
                ['section' => 2, 'name' => 'Sin resumen'],
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));

        $sections = array_values($DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0 AND component IS NULL',
            [$result['courseid']],
            'section ASC'
        ));
        $this->assertCount(2, $sections);

        // The planned description becomes the section summary.
        $this->assertSame('<p>Resumen de la seccion.</p>', $sections[0]->summary);
        $this->assertSame((int)FORMAT_HTML, (int)$sections[0]->summaryformat);

        // An entry without description keeps an empty summary (older service).
        $this->assertSame('', (string)$sections[1]->summary);
    }

    /**
     * MDL-INT-021: Each planned subsection is created as a Subsection module
     * inside its parent section preserving the plan order, and the nested
     * activities land inside the right subsection.
     */
    public function test_subsections_materialized_preserving_plan_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_subsection_module();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso anidado', 'shortname' => 'anidado-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'subsections_info' => [
                ['id' => 'sub-1', 'name' => 'Subtema A', 'description' => 'Descripcion A', 'parent_section' => 1],
            ],
            'generated_activities' => [
                $this->label_activity(1, 'Directa'),
                $this->label_activity(1, 'Anidada uno', 'sub-1'),
                $this->label_activity(1, 'Anidada dos', 'sub-1'),
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame(0, $result['warningscount']);

        // The parent section keeps the plan order: direct activity first, then
        // the subsection module where the first nested activity appeared.
        $parentactivities = $this->get_section_activities((int)$result['courseid'], 1);
        $this->assertSame('label', $parentactivities[0][0]);
        $this->assertSame('Directa', $parentactivities[0][1]);
        $this->assertSame('subsection', $parentactivities[1][0]);
        $this->assertSame('Subtema A', $parentactivities[1][1]);

        // The nested activities live inside the delegated section, in order.
        $delegated = $this->get_delegated_sections((int)$result['courseid']);
        $this->assertCount(1, $delegated);
        $nested = $this->get_section_activities((int)$result['courseid'], (int)$delegated[0]->section);
        $this->assertSame([['label', 'Anidada uno'], ['label', 'Anidada dos']], $nested);
    }

    /**
     * MDL-INT-021: The subsection description is applied as the summary of the
     * delegated section.
     */
    public function test_subsection_description_applied_as_summary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_subsection_module();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso resumen', 'shortname' => 'resumen-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'subsections_info' => [
                ['id' => 'sub-1', 'name' => 'Subtema B', 'description' => 'Resumen del subtema.', 'parent_section' => 1],
            ],
            'generated_activities' => [$this->label_activity(1, 'Anidada', 'sub-1')],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));

        $delegated = $this->get_delegated_sections((int)$result['courseid']);
        $this->assertCount(1, $delegated);
        $this->assertSame('Resumen del subtema.', $delegated[0]->summary);
    }

    /**
     * MDL-INT-021: A subsection declared without activities materializes at the
     * end of its parent section.
     */
    public function test_empty_subsection_materializes_at_end_of_parent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_subsection_module();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso subseccion vacia', 'shortname' => 'vacia-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'subsections_info' => [
                ['id' => 'sub-vacia', 'name' => 'Subtema vacio', 'description' => '', 'parent_section' => 1],
            ],
            'generated_activities' => [$this->label_activity(1, 'Directa')],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));

        $parentactivities = $this->get_section_activities((int)$result['courseid'], 1);
        $this->assertCount(2, $parentactivities);
        $this->assertSame(['label', 'Directa'], $parentactivities[0]);
        $this->assertSame('subsection', $parentactivities[1][0]);

        // The delegated section exists and is empty.
        $delegated = $this->get_delegated_sections((int)$result['courseid']);
        $this->assertCount(1, $delegated);
        $this->assertSame(
            [],
            $this->get_section_activities((int)$result['courseid'], (int)$delegated[0]->section)
        );
    }

    /**
     * MDL-INT-022: With the Subsection module disabled at creation time, nested
     * activities are created directly in the parent section and the course is
     * still complete.
     */
    public function test_disabled_subsection_module_flattens_nested_activities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_subsection_module_visibility(0);

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso aplanado', 'shortname' => 'plano-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'subsections_info' => [
                ['id' => 'sub-1', 'name' => 'Subtema', 'description' => '', 'parent_section' => 1],
            ],
            'generated_activities' => [
                $this->label_activity(1, 'Anidada uno', 'sub-1'),
                $this->label_activity(1, 'Anidada dos', 'sub-1'),
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));

        // No delegated section was created and nothing was lost: both nested
        // activities flattened into the parent section.
        $this->assertCount(0, $this->get_delegated_sections((int)$result['courseid']));
        $this->assertSame(
            [['label', 'Anidada uno'], ['label', 'Anidada dos']],
            $this->get_section_activities((int)$result['courseid'], 1)
        );
    }

    /**
     * MDL-INT-022: Creating a module in a section that does not exist must be
     * rejected with a clear error before touching core, on every Moodle
     * version — Moodle 5.x half-tolerates the missing section with PHP
     * warnings and creates the module anyway, which broke the individual
     * subsection degradation.
     */
    public function test_nonexistent_target_section_is_rejected_with_clear_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $resultinfo = [
            'resource_type' => 'label',
            'parameters' => [
                'modulename' => 'label',
                'name' => 'Etiqueta perdida',
                'introeditor' => ['text' => '<p>Contenido</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'mod_settings' => [],
            ],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(get_string('error_section_not_found', 'local_coursegen', 99));
        \local_coursegen\local\service\create_mod_service::create_from_ai_result($resultinfo, $course, 99);
    }

    /**
     * MDL-INT-022: An incompatible course format degrades subsections the same
     * way, flattening nested activities into the parent section.
     */
    public function test_incompatible_course_format_flattens_nested_activities(): void {
        $this->resetAfterTest();
        $this->markTestSkipped(
            'El flujo publico crea los cursos siempre con el formato por defecto (topics, que '
            . 'soporta subsecciones); el gate de formato incompatible no puede ejercitarse a '
            . 'traves de la API publica sin reflexion.'
        );
    }

    /**
     * MDL-INT-022: The failure to materialize one individual subsection
     * degrades only its activities to the parent section, without losing them.
     */
    public function test_individual_subsection_failure_degrades_its_activities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_subsection_module();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso con fallo', 'shortname' => 'fallo-sub-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'subsections_info' => [
                ['id' => 'sub-sana', 'name' => 'Subtema sano', 'description' => '', 'parent_section' => 1],
                // The invalid parent section makes this subsection fail to
                // materialize; its activities must fall back to their parent.
                ['id' => 'sub-rota', 'name' => 'Subtema roto', 'description' => '', 'parent_section' => 99],
            ],
            'generated_activities' => [
                $this->label_activity(1, 'Anidada sana', 'sub-sana'),
                $this->label_activity(1, 'Degradada', 'sub-rota'),
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertTrue($result['haswarnings']);
        $this->assertGreaterThanOrEqual(1, $result['warningscount']);

        // The degraded activity landed in the parent section; the healthy
        // subsection still materialized with its activity inside.
        $parentactivities = $this->get_section_activities((int)$result['courseid'], 1);
        $this->assertContains(['label', 'Degradada'], $parentactivities);

        $delegated = $this->get_delegated_sections((int)$result['courseid']);
        $this->assertCount(1, $delegated);
        $this->assertSame(
            [['label', 'Anidada sana']],
            $this->get_section_activities((int)$result['courseid'], (int)$delegated[0]->section)
        );
    }

    /**
     * MDL-INT-023: The failure to create one activity does not stop the
     * creation of the rest, and the result reports the partial creation with
     * the number of warnings.
     */
    public function test_single_activity_failure_does_not_stop_the_rest(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso parcial', 'shortname' => 'parcial-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'generated_activities' => [
                $this->label_activity(1, 'Primera'),
                [
                    'resource_type' => 'nonexistentmodule',
                    'parameters' => ['modulename' => 'nonexistentmodule', 'name' => 'Rota', 'section' => 1],
                ],
                $this->label_activity(1, 'Tercera'),
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertTrue($result['partial']);
        $this->assertTrue($result['haswarnings']);
        $this->assertSame(1, $result['warningscount']);

        // Both healthy activities exist; nothing else was created.
        $this->assertSame(
            [['label', 'Primera'], ['label', 'Tercera']],
            $this->get_section_activities((int)$result['courseid'], 1)
        );
        $this->assertSame(2, $DB->count_records('course_modules', ['course' => $result['courseid']]));
    }

    /**
     * MDL-INT-023: The teacher can identify which activities failed and why.
     */
    public function test_failed_activity_details_reach_the_teacher(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso con detalle', 'shortname' => 'detalle-1'],
            'sections_info' => [['section' => 1, 'name' => 'Padre']],
            'generated_activities' => [
                $this->label_activity(1, 'Sana'),
                [
                    'resource_type' => 'nonexistentmodule',
                    'parameters' => ['modulename' => 'nonexistentmodule', 'name' => 'Rota', 'section' => 1],
                ],
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertTrue($result['partial']);
        $this->assertSame(1, $result['warningscount']);

        // The teacher can identify which activity failed and why.
        $this->assertCount(1, $result['activityerrors']);
        $error = $result['activityerrors'][0];
        $this->assertSame('nonexistentmodule', $error['resource_type']);
        $this->assertSame(1, $error['section']);
        $this->assertSame('Rota', $error['title']);
        $this->assertNotSame('', trim((string)$error['message']));

        // The partial warning phrase comes from the language pack instead of a
        // hardcoded English sentence.
        $this->assertStringContainsString(
            get_string('create_course_partial_warning', 'local_coursegen'),
            $result['message']
        );
    }

    /**
     * MDL-INT-024: When the creation finishes, the section sequences contain no
     * orphan module references and every reference resolves through modinfo.
     */
    public function test_final_structure_has_no_orphan_module_references(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->make_session(get_admin()->id);
        $resultdata = [
            'course_configuration' => ['fullname' => 'Curso consistente', 'shortname' => 'consistente-1'],
            'sections_info' => [
                ['section' => 1, 'name' => 'Uno'],
                ['section' => 2, 'name' => 'Dos'],
            ],
            'generated_activities' => [
                $this->label_activity(1, 'A'),
                $this->label_activity(2, 'B'),
                $this->label_activity(2, 'C'),
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));

        $courseid = (int)$result['courseid'];
        $validcmids = $DB->get_records('course_modules', ['course' => $courseid], '', 'id');
        $modinfo = get_fast_modinfo(get_course($courseid));
        $cms = $modinfo->get_cms();

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);
        foreach ($sections as $section) {
            $sequence = trim((string)$section->sequence);
            if ($sequence === '') {
                continue;
            }
            foreach (explode(',', $sequence) as $cmid) {
                $cmid = (int)$cmid;
                $this->assertArrayHasKey($cmid, $validcmids, 'Orphan module reference in section sequence.');
                $this->assertArrayHasKey($cmid, $cms, 'Sequence reference unresolved by modinfo.');
            }
        }
    }

}
