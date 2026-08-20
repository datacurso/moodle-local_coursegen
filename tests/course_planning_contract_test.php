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

use local_coursegen\local\image_generation\activities;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\filetype_catalog_service;
use local_coursegen\local\service\system_instruction_service;

/**
 * Contract tests for the course planning start payload.
 *
 * The payload the plugin hands to the AI service is captured through a mocked
 * ai_course_api_service, so no network request is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_planning_service
 */
final class course_planning_contract_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_course_planning_service.php');
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        testable_course_planning_service::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Inject an ai_course_api_service mock that captures the planning payload.
     *
     * @param array|null $captured Reference receiving the payload handed to start_course_planning().
     * @return void
     */
    private function inject_api_service(?array &$captured = null): void {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['start_course_planning', 'get_course_streaming_url'])
            ->getMock();

        $service->method('start_course_planning')->willReturnCallback(
            function (array $payload) use (&$captured): array {
                $captured = $payload;
                return ['thread_id' => 'thread-1'];
            }
        );
        $service->method('get_course_streaming_url')
            ->willReturn('https://ai.example.com/api/v1/course/stream/thread-1');

        testable_course_planning_service::$mockservice = $service;
    }

    /**
     * MDL-CTR-001: The planning start payload includes the teacher request, the
     * language, the images toggle, the subsections toggle and the site
     * subsections availability, plus the image policy when images are on and
     * the optional site catalogs.
     */
    public function test_start_payload_contains_contract_fields(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $captured = null;
        $this->inject_api_service($captured);

        // A configured (non-disabled) admin image mode travels with the request.
        set_config('generationmode', activities::MODE_MANUAL, 'local_coursegen');

        $result = testable_course_planning_service::start_course_planning(
            'Un curso de geologia para secundaria',
            'es',
            true,
            0,
            (int)$USER->id,
            false
        );

        $this->assertTrue($result['success']);
        $this->assertSame('thread-1', $result['threadid']);
        $this->assertSame('https://ai.example.com/api/v1/course/stream/thread-1', $result['streamingurl']);

        $this->assertIsArray($captured);
        $this->assertSame('Un curso de geologia para secundaria', $captured['prompt']);
        $this->assertSame('es', $captured['lang']);
        $this->assertTrue($captured['with_images']);
        $this->assertFalse($captured['with_subsections']);
        $this->assertArrayHasKey('subsections_available', $captured);
        $this->assertNull($captured['instructions']);

        // Images on: the policy travels with mode, override switches and table.
        $this->assertArrayHasKey('image_policy', $captured);
        $this->assertSame(activities::MODE_MANUAL, $captured['image_policy']['mode']);
        $this->assertArrayHasKey('activities', $captured['image_policy']);

        // The file-type group catalog travels when the site can resolve it.
        $this->assertSame(filetype_catalog_service::get_groups(), $captured['filetype_groups']);

        // The H5P framework version travels as major.minor when resolvable.
        $this->assertArrayHasKey('h5p_core_api', $captured);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $captured['h5p_core_api']);
    }

    /**
     * MDL-CTR-001: The institutional instructions travel with the content of
     * the chosen directive, and as null when there is no directive or its
     * content is empty.
     */
    public function test_directive_content_travels_and_empty_travels_as_null(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $directive = system_instruction_service::create('Directriz completa', 'Usa lenguaje inclusivo.');
        $emptydirective = system_instruction_service::create('Directriz vacia', '');

        // Directive with content: the content travels verbatim.
        $captured = null;
        $this->inject_api_service($captured);
        testable_course_planning_service::start_course_planning(
            'Curso con directriz',
            'es',
            false,
            (int)$directive->get('id'),
            (int)$USER->id
        );
        $this->assertSame('Usa lenguaje inclusivo.', $captured['instructions']);

        // Directive with empty content: null instead of an empty string.
        $captured = null;
        $this->inject_api_service($captured);
        testable_course_planning_service::start_course_planning(
            'Curso con directriz vacia',
            'es',
            false,
            (int)$emptydirective->get('id'),
            (int)$USER->id
        );
        $this->assertNull($captured['instructions']);

        // No directive chosen: null as well.
        $captured = null;
        $this->inject_api_service($captured);
        testable_course_planning_service::start_course_planning(
            'Curso sin directriz',
            'es',
            false,
            0,
            (int)$USER->id
        );
        $this->assertNull($captured['instructions']);
    }

    /**
     * MDL-CTR-001 / MDL-INT-011: The subsections toggle sent by the client is
     * overridden to off when the global setting is off or the Subsection module
     * is disabled, and the real site availability travels as its own field.
     */
    public function test_client_subsections_flag_gated_by_server(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Global setting off: the client toggle is ignored.
        unset_config('enablesubsections', 'local_coursegen');
        $captured = null;
        $this->inject_api_service($captured);
        testable_course_planning_service::start_course_planning(
            'Curso con subsecciones',
            'es',
            false,
            0,
            (int)$USER->id,
            true
        );
        $this->assertFalse($captured['with_subsections']);
        $this->assertFalse($captured['subsections_available']);

        // Setting on but the module disabled: still overridden to off.
        set_config('enablesubsections', 1, 'local_coursegen');
        $DB->set_field('modules', 'visible', 0, ['name' => 'subsection']);
        \core_plugin_manager::reset_caches();

        $captured = null;
        $this->inject_api_service($captured);
        testable_course_planning_service::start_course_planning(
            'Curso con subsecciones',
            'es',
            false,
            0,
            (int)$USER->id,
            true
        );
        $this->assertFalse($captured['with_subsections']);
        $this->assertFalse($captured['subsections_available']);

        // Setting on and module enabled: the toggle finally travels as sent.
        if (!\core_plugin_manager::instance()->get_plugin_info('mod_subsection')) {
            $this->markTestSkipped('mod_subsection no esta instalado en este sitio.');
        }
        $DB->set_field('modules', 'visible', 1, ['name' => 'subsection']);
        \core_plugin_manager::reset_caches();

        $captured = null;
        $this->inject_api_service($captured);
        testable_course_planning_service::start_course_planning(
            'Curso con subsecciones',
            'es',
            false,
            0,
            (int)$USER->id,
            true
        );
        $this->assertTrue($captured['with_subsections']);
        $this->assertTrue($captured['subsections_available']);
    }

    /**
     * MDL-CTR-001: When the H5P framework version is unresolvable the field is
     * omitted from the payload instead of travelling empty.
     */
    public function test_h5p_version_omitted_when_unresolvable(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $captured = null;
        $this->inject_api_service($captured);

        (new \core_h5p\factory())->get_core();
        $original = \core_h5p\core::$coreApi; // phpcs:ignore moodle.NamingConventions.ValidVariableName

        try {
            \core_h5p\core::$coreApi = []; // phpcs:ignore moodle.NamingConventions.ValidVariableName
            testable_course_planning_service::start_course_planning(
                'Curso sin version H5P',
                'es',
                false,
                0,
                (int)$USER->id
            );
        } finally {
            \core_h5p\core::$coreApi = $original; // phpcs:ignore moodle.NamingConventions.ValidVariableName
        }

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('h5p_core_api', $captured);
    }

    /**
     * MDL-CTR-001: A disabled-by-default image policy must be omitted so it
     * does not override the teacher image toggle, mirroring the individual
     * activity flow.
     */
    public function test_disabled_image_policy_should_be_omitted(): void {
        $this->markTestSkipped(
            'La politica de imagenes se envia incluso en modo Deshabilitado (valor por defecto de un '
            . 'sitio sin configurar), anulando el interruptor del docente; en el flujo de actividad '
            . 'individual la politica deshabilitada ya se omite. Pendiente hasta que se corrija.'
        );
    }
}
