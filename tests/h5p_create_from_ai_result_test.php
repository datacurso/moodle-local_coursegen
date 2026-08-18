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
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\create_mod_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once(__DIR__ . '/fixtures/h5p_package_fixture.php');

/**
 * Tests for H5P activity creation from an AI service result.
 *
 * The AI HTTP client is replaced through the api_client_factory seam, so no
 * network request is ever performed. The downloaded package is simulated
 * with a real stored_file created in the current user draft area.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_mod_service
 */
final class h5p_create_from_ai_result_test extends \advanced_testcase {

    /**
     * Always remove the injected factory test double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Make the given course the current one.
     *
     * The module edit form resolves section info through the global $COURSE.
     * In a web request require_login() binds the page (and $COURSE) to the
     * course; without it, the theme initialisation triggered by the form
     * falls back to the site course and the target section cannot resolve.
     *
     * @param \stdClass $course Course record.
     * @return void
     */
    private function set_current_course(\stdClass $course): void {
        global $PAGE;
        $PAGE->set_course($course);
    }

    /**
     * Create a real stored_file in the current user's draft area, as the real
     * ai_course_api::download_file() does.
     *
     * @param string $filename Package file name.
     * @param string|null $content Package bytes; a structurally valid .h5p by default.
     * @return \stored_file
     */
    private function create_draft_package_file(string $filename, ?string $content = null): \stored_file {
        global $USER;

        $fs = get_file_storage();
        $record = (object) [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
        ];

        return $fs->create_file_from_string($record, $content ?? h5p_package_fixture::bytes());
    }

    /**
     * Inject an ai_course_api mock whose download_file() returns a real draft file.
     *
     * @param string|null $capturedendpoint Reference that receives the endpoint passed to download_file().
     * @param string|null $capturedfilename Reference that receives the file name passed to download_file().
     * @param string|null $content Package bytes; a structurally valid .h5p by default.
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function inject_download_client(
        ?string &$capturedendpoint = null,
        ?string &$capturedfilename = null,
        ?string $content = null
    ): \PHPUnit\Framework\MockObject\MockObject {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();

        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename) use (&$capturedendpoint, &$capturedfilename, $content): \stored_file {
                $capturedendpoint = $endpoint;
                $capturedfilename = $filename;
                return $this->create_draft_package_file($filename, $content);
            }
        );

        api_client_factory::set_test_client($mock);

        return $mock;
    }

    /**
     * Build an AI result payload for an H5P activity, as returned by the service.
     *
     * @param array $paramoverrides Overrides merged into the parameters section.
     * @param array $modsettingsoverrides Overrides merged into mod_settings.
     * @return array Result info payload.
     */
    private function h5p_resultinfo(array $paramoverrides = [], array $modsettingsoverrides = []): array {
        $modsettings = array_merge([
            'file_path' => 'generated/packages/sample-activity.h5p',
            'file_name' => 'sample-activity.h5p',
        ], $modsettingsoverrides);

        $parameters = array_merge([
            'modulename' => 'h5pactivity',
            'name' => 'AI generated H5P',
            'introeditor' => ['text' => '<p>AI generated intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'visible' => 1,
            'cmidnumber' => '',
            'grade' => 100,
            'grademethod' => 1,
            'gradepass' => 70,
            'enabletracking' => 1,
            'reviewmode' => 1,
            'mod_settings' => $modsettings,
        ], $paramoverrides);

        return [
            'resource_type' => 'h5pactivity',
            'parameters' => $parameters,
        ];
    }

    /**
     * MDL-UNIT-001: The canonical H5P resource type resolves to the parameters
     * handler that downloads and attaches the package.
     */
    public function test_canonical_type_resolves_to_download_handler(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $this->inject_download_client();

        $newcm = create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);

        $this->assertSame('h5pactivity', $newcm->modulename);

        // The handler ran: the downloaded package is attached to the module.
        $context = \context_module::instance($newcm->coursemodule);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $this->assertCount(1, $files);
    }

    /**
     * MDL-UNIT-001 / MDL-INT-005: A resource type whose module does not exist on
     * the site (for example the "h5p" alias) is rejected with a clear error
     * before anything is created.
     */
    public function test_nonexistent_module_type_is_rejected_with_clear_error(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->inject_download_client();

        $resultinfo = $this->h5p_resultinfo(['modulename' => 'h5p']);
        $resultinfo['resource_type'] = 'h5p';

        try {
            create_mod_service::create_from_ai_result($resultinfo, $course, 1);
            $this->fail('An exception was expected for a nonexistent module type.');
        } catch (\Exception $e) {
            $this->assertSame(
                get_string('error_invalid_resource_type', 'local_coursegen', 'h5p'),
                $e->getMessage()
            );
        }

        // Nothing was created.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity'));
    }

    /**
     * MDL-UNIT-001: When the module exists but the parameters class does not
     * resolve (stale class map after deployment), creation must fail with a
     * diagnostic error instead of silently producing an H5P activity without
     * content.
     *
     * The scenario is reproduced with a module that has no parameters handler
     * while the result carries a package-type contract (mod_settings with
     * file_path/file_name): exactly what a stale class map looks like at run
     * time for h5pactivity.
     */
    public function test_unresolved_parameter_class_produces_diagnostic_error(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $this->inject_download_client();

        $resultinfo = $this->h5p_resultinfo(['modulename' => 'page']);
        $resultinfo['resource_type'] = 'page';

        try {
            create_mod_service::create_from_ai_result($resultinfo, $course, 1);
            $this->fail('A diagnostic exception was expected when the parameters handler does not resolve.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('page_parameters', $e->getMessage());
        }

        // No contentless activity was created.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('page'));
    }

    /**
     * MDL-INT-001: The generated package is downloaded from the service and
     * attached as the package of the created H5P activity, which is created in
     * the requested section and position with its title and description.
     */
    public function test_package_downloaded_and_attached_to_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->set_current_course($course);
        $capturedendpoint = null;
        $this->inject_download_client($capturedendpoint);

        $newcm = create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 2);

        // Activity record exists with the requested title and description.
        $record = $DB->get_record('h5pactivity', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame('AI generated H5P', $record->name);
        $this->assertStringContainsString('<p>AI generated intro</p>', $record->intro);

        // Created in the requested section.
        $sectionid = $DB->get_field('course_modules', 'section', ['id' => $newcm->coursemodule], MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
        $this->assertEquals(2, (int) $section->section);

        // The download used the remote path sent by the service, URL-encoded
        // as a single query value (see MDL-INT-002).
        $this->assertSame(
            '/files/download?path=' . rawurlencode('generated/packages/sample-activity.h5p'),
            $capturedendpoint
        );

        // The file is stored by the Moodle File API in the module package area.
        $context = \context_module::instance($newcm->coursemodule);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('sample-activity.h5p', $file->get_filename());
        $this->assertSame(h5p_package_fixture::bytes(), $file->get_content());
    }

    /**
     * MDL-INT-001: The requested position (beforemod) is honoured inside the section.
     */
    public function test_activity_created_in_requested_position(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->set_current_course($course);
        $this->inject_download_client();

        $first = create_mod_service::create_from_ai_result($this->h5p_resultinfo(['name' => 'First']), $course, 1);
        $second = create_mod_service::create_from_ai_result(
            $this->h5p_resultinfo(['name' => 'Second']),
            $course,
            1,
            $first->coursemodule
        );

        $sequence = $DB->get_field('course_sections', 'sequence', ['course' => $course->id, 'section' => 1], MUST_EXIST);
        $this->assertSame([(string) $second->coursemodule, (string) $first->coursemodule], explode(',', $sequence));
    }

    /**
     * MDL-INT-002: Remote path encoding, file name cleaning and missing-field
     * validation for the package download.
     */
    public function test_package_path_and_filename_are_sanitized(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $capturedendpoint = null;
        $capturedfilename = null;
        $this->inject_download_client($capturedendpoint, $capturedfilename);

        $resultinfo = $this->h5p_resultinfo([], [
            'file_path' => 'generated/packages/mi actividad (v2).h5p',
            'file_name' => '../sub/mi actividad: "final".h5p',
        ]);

        create_mod_service::create_from_ai_result($resultinfo, $course, 1);

        // The remote path travels URL-encoded as a single query value.
        $this->assertSame(
            '/files/download?path=' . rawurlencode('generated/packages/mi actividad (v2).h5p'),
            $capturedendpoint
        );

        // The file name is reduced to a valid Moodle file name: no directory
        // components and no characters invalid in a Moodle file name.
        $this->assertSame('mi actividad final.h5p', $capturedfilename);
    }

    /**
     * MDL-INT-002: A result whose mod_settings misses file_path or file_name is
     * rejected with a clear error before any download or creation is attempted.
     */
    public function test_missing_package_fields_are_validated(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $capturedendpoint = null;
        $this->inject_download_client($capturedendpoint);

        $missingcases = [
            'file_path' => $this->h5p_resultinfo(),
            'file_name' => $this->h5p_resultinfo(),
        ];
        unset($missingcases['file_path']['parameters']['mod_settings']['file_path']);
        unset($missingcases['file_name']['parameters']['mod_settings']['file_name']);

        foreach ($missingcases as $missingfield => $resultinfo) {
            try {
                create_mod_service::create_from_ai_result($resultinfo, $course, 1);
                $this->fail('An exception was expected for a result without ' . $missingfield . '.');
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(
                    get_string('error_missing_package_info', 'local_coursegen'),
                    $e->getMessage(),
                    'Missing ' . $missingfield . ' must surface the package info error.'
                );
            }
        }

        // No download was attempted and nothing was created.
        $this->assertNull($capturedendpoint);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity'));
    }

    /**
     * MDL-INT-003: The downloaded package must be validated (extension and H5P
     * content check) at creation time instead of failing when a student opens it.
     */
    public function test_downloaded_package_is_validated_before_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        // Corrupt content: not a readable zip archive.
        $endpoint = null;
        $filename = null;
        $this->inject_download_client($endpoint, $filename, 'corrupt bytes, not a zip archive');
        try {
            create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);
            $this->fail('An exception was expected for a corrupt package.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('error_invalid_package', 'local_coursegen', 'sample-activity.h5p'),
                $e->getMessage()
            );
        }

        // Readable zip, but without the h5p.json manifest of an H5P package.
        $this->inject_download_client($endpoint, $filename, h5p_package_fixture::bytes_without_manifest());
        try {
            create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);
            $this->fail('An exception was expected for a zip without h5p.json.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('error_invalid_package', 'local_coursegen', 'sample-activity.h5p'),
                $e->getMessage()
            );
        }

        // Wrong extension: the package must be a .h5p file.
        $this->inject_download_client();
        $resultinfo = $this->h5p_resultinfo([], [
            'file_path' => 'generated/packages/sample-activity.zip',
            'file_name' => 'sample-activity.zip',
        ]);
        try {
            create_mod_service::create_from_ai_result($resultinfo, $course, 1);
            $this->fail('An exception was expected for a package without the .h5p extension.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('error_invalid_package', 'local_coursegen', 'sample-activity.zip'),
                $e->getMessage()
            );
        }

        // No half-created activity remains in the course.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity'));
    }

    /**
     * MDL-INT-004: A network/service failure during the package download produces
     * a clear error and leaves no half-created activity in the course.
     */
    public function test_download_failure_produces_clear_error_without_residue(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willThrowException(
            new \moodle_exception('curlerror', 'aiprovider_datacurso', '', 'Connection refused')
        );
        api_client_factory::set_test_client($mock);

        try {
            create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);
            $this->fail('An exception was expected when the package download fails.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }

        // No half-created activity remains in the course.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity'));
    }

    /**
     * MDL-INT-004: In the full-course flow a failed H5P activity is registered as
     * an error and the rest of the course keeps being created.
     */
    public function test_course_flow_continues_after_h5p_download_failure(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        // First download fails, second succeeds.
        $calls = 0;
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename) use (&$calls): \stored_file {
                $calls++;
                if ($calls === 1) {
                    throw new \moodle_exception('curlerror', 'aiprovider_datacurso', '', 'Connection refused');
                }
                return $this->create_draft_package_file($filename);
            }
        );
        api_client_factory::set_test_client($mock);

        // The mod edit form resolves section visibility through the global
        // $COURSE; the AI course flow places these activities in section 0,
        // so any current course with a section 0 satisfies the form.
        $this->set_current_course($this->getDataGenerator()->create_course());

        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-int004',
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'AI course with partial failure',
                'shortname' => 'aicourse-int004',
            ],
            'generated_activities' => [
                $this->h5p_resultinfo(['name' => 'Failing H5P']),
                $this->h5p_resultinfo(['name' => 'Surviving H5P']),
            ],
        ];

        $result = create_course_service::create_course($session, $resultdata);
        // One debugging call for the skipped module and one for the course summary.
        $this->assertDebuggingCalledCount(2);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['partial']);
        $this->assertSame(1, $result['warningscount']);
        $this->assertSame('h5pactivity', $result['activityerrors'][0]['resource_type']);
        $this->assertSame('Failing H5P', $result['activityerrors'][0]['title']);

        // Only the surviving activity was created in the new course.
        $records = $DB->get_records('h5pactivity', ['course' => $result['courseid']]);
        $this->assertCount(1, $records);
        $this->assertSame('Surviving H5P', reset($records)->name);
    }

    /**
     * MDL-INT-005: If the H5P activity module is uninstalled, creation is
     * rejected with a clear error.
     */
    public function test_uninstalled_module_type_is_rejected(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->inject_download_client();

        $resultinfo = $this->h5p_resultinfo(['modulename' => 'fakemodule']);
        $resultinfo['resource_type'] = 'fakemodule';

        try {
            create_mod_service::create_from_ai_result($resultinfo, $course, 1);
            $this->fail('An exception was expected for an uninstalled module type.');
        } catch (\Exception $e) {
            $this->assertSame(
                get_string('error_invalid_resource_type', 'local_coursegen', 'fakemodule'),
                $e->getMessage()
            );
        }

        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * MDL-INT-005: A module that exists on disk but is disabled by the
     * administrator must also be rejected with a clear error.
     */
    public function test_disabled_module_is_rejected(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->inject_download_client();

        $DB->set_field('modules', 'visible', 0, ['name' => 'h5pactivity']);

        try {
            create_mod_service::create_from_ai_result($this->h5p_resultinfo(), $course, 1);
            $this->fail('An exception was expected for a disabled module type.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('error_module_disabled', 'local_coursegen', 'h5pactivity'),
                $e->getMessage()
            );
        }

        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity'));
    }

    /**
     * MDL-INT-006: The created H5P activity keeps the documented settings:
     * maximum grade 100, grading method "highest grade", passing grade from the
     * fixture, and attempt tracking and participant review enabled.
     */
    public function test_created_activity_settings_match_result(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        $this->inject_download_client();

        $resultinfo = $this->h5p_resultinfo(['gradepass' => 80]);
        $newcm = create_mod_service::create_from_ai_result($resultinfo, $course, 1);

        $record = $DB->get_record('h5pactivity', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertEquals(100, (int) $record->grade);
        $this->assertEquals(1, (int) $record->grademethod, 'Grading method must be highest grade.');
        $this->assertEquals(1, (int) $record->enabletracking, 'Attempt tracking must be enabled.');
        $this->assertEquals(1, (int) $record->reviewmode, 'Participants must be able to review their attempts.');

        // The passing grade from the result reaches the grade item.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'h5pactivity',
            'iteminstance' => $newcm->instance,
            'courseid' => $course->id,
        ]);
        $this->assertNotEmpty($gradeitem);
        $this->assertEquals(80.0, (float) $gradeitem->gradepass);
        $this->assertEquals(100.0, (float) $gradeitem->grademax);
    }
}
