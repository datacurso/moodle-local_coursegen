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

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\create_course_service;

/**
 * Idempotency tests for the activities the AI result carries.
 *
 * A service answer that returns the same unit twice — typically an image-less
 * copy plus an illustrated one, which is what a contradictory image instruction
 * produces — must not land twice in the course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_duplicate_activities_test extends \advanced_testcase {
    /**
     * Make the given course the current one.
     *
     * The module edit form resolves section info through the global $COURSE.
     *
     * @param \stdClass $course Course record.
     * @return void
     */
    private function set_current_course(\stdClass $course): void {
        global $PAGE;
        $PAGE->set_course($course);
    }

    /**
     * Build a planning session record for the current user.
     *
     * @param string $sessionid External session identifier.
     * @return course_session
     */
    private function create_session(string $sessionid): course_session {
        global $USER;

        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => $sessionid,
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Build one generated_activities entry.
     *
     * @param string $modname Module name.
     * @param string $name Activity name.
     * @param string $intro Intro HTML.
     * @param int $section Target section number.
     * @return array
     */
    private function activity(string $modname, string $name, string $intro, int $section = 1): array {
        return [
            'resource_type' => $modname,
            'parameters' => [
                'modulename' => $modname,
                'name' => $name,
                'section' => $section,
                'introeditor' => ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'visibleoncoursepage' => 1,
                'groupmode' => 0,
                'groupingid' => 0,
                'completion' => 0,
                'completiongradeitemnumber' => '',
                'completionview' => 0,
                'completionexpected' => 0,
                'completionpassgrade' => 0,
                'mod_settings' => [],
            ],
        ];
    }

    /**
     * The image-less copy and the illustrated copy of the same activity are the
     * same activity: only one may reach the course, and it must be the
     * illustrated one — enabling images has to produce an illustrated course.
     */
    public function test_illustrated_copy_wins_when_it_comes_last(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course());

        $welcome = '<h3>¡Bienvenidos al Curso de Biotecnología!</h3><p>Le damos una cordial bienvenida.</p>';
        $illustrated = '<p><img src="https://example.com/biotec.png" alt="Biotecnología" /></p>' . $welcome;

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Curso de Biotecnología',
                'shortname' => 'biotec-dup-001',
            ],
            'sections_info' => [
                ['section' => 1, 'name' => 'Introducción y Fundamentos'],
            ],
            'generated_activities' => [
                $this->activity('label', 'Bienvenida', $welcome),
                $this->activity('forum', 'Debate sobre Bioética', '<p>Comparte tu punto de vista.</p>'),
                // The service returned the same unit again, this time illustrated.
                $this->activity('label', 'Bienvenida', $illustrated),
                $this->activity('forum', 'Debate sobre Bioética', '<p>Comparte tu punto de vista.</p>'),
            ],
        ];

        $result = create_course_service::create_course($this->create_session('sess-dup-001'), $resultdata);
        // Two duplicates reported individually, plus the course-level summary.
        $this->assertDebuggingCalledCount(3);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(2, $result['duplicatesskipped']);

        // Skipping a duplicate is a clean creation, not a partially applied course.
        $this->assertFalse($result['partial']);
        $this->assertFalse($result['haswarnings']);
        $this->assertSame(0, $result['warningscount']);

        $labels = $DB->get_records('label', ['course' => $result['courseid']]);
        $this->assertCount(1, $labels);
        $this->assertStringContainsString('<img', reset($labels)->intro, 'The illustrated copy must be the one kept.');
        $this->assertCount(1, $DB->get_records('forum', ['course' => $result['courseid']]));
    }

    /**
     * Selection is by imagery, not by position: the illustrated copy wins even
     * when the image-less one closes the result.
     */
    public function test_illustrated_copy_wins_when_it_comes_first(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course());

        $welcome = '<h3>¡Bienvenidos al Curso de Biotecnología!</h3><p>Le damos una cordial bienvenida.</p>';
        $illustrated = '<p><img src="https://example.com/biotec.png" alt="Biotecnología" /></p>' . $welcome;

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Curso de Biotecnología',
                'shortname' => 'biotec-dup-005',
            ],
            'sections_info' => [
                ['section' => 1, 'name' => 'Introducción y Fundamentos'],
            ],
            'generated_activities' => [
                $this->activity('label', 'Bienvenida', $illustrated),
                $this->activity('label', 'Bienvenida', $welcome),
            ],
        ];

        $result = create_course_service::create_course($this->create_session('sess-dup-005'), $resultdata);
        $this->assertDebuggingCalledCount(2);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(1, $result['duplicatesskipped']);

        $labels = $DB->get_records('label', ['course' => $result['courseid']]);
        $this->assertCount(1, $labels);
        $this->assertStringContainsString('<img', reset($labels)->intro);
    }

    /**
     * A copy with real images outranks a copy that only carries the unresolved
     * `{{image:}}` markers the cleaner would strip anyway.
     */
    public function test_real_images_outrank_unresolved_placeholders(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course());

        $body = '<p>Explora los recursos visuales de cada unidad.</p>';

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Curso de Biotecnología',
                'shortname' => 'biotec-dup-006',
            ],
            'sections_info' => [
                ['section' => 1, 'name' => 'Introducción y Fundamentos'],
            ],
            'generated_activities' => [
                $this->activity('label', 'Metodología', '{{image: microscopio}}' . $body),
                $this->activity('label', 'Metodología', '<p><img src="https://example.com/m.png" alt="" /></p>' . $body),
            ],
        ];

        $result = create_course_service::create_course($this->create_session('sess-dup-006'), $resultdata);
        $this->assertDebuggingCalledCount(2);

        $this->assertSame(1, $result['duplicatesskipped']);

        $labels = $DB->get_records('label', ['course' => $result['courseid']]);
        $this->assertCount(1, $labels);
        $this->assertStringContainsString('<img', reset($labels)->intro);
        $this->assertStringNotContainsString('{{image:', reset($labels)->intro);
    }

    /**
     * Unresolved image placeholders are stripped before comparison too, so the
     * templated copy does not read as a different activity.
     */
    public function test_image_placeholder_copy_is_not_created_twice(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course());

        $intro = '<p>Explora los recursos visuales de cada unidad.</p>';

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Curso de Biotecnología',
                'shortname' => 'biotec-dup-002',
            ],
            'sections_info' => [
                ['section' => 1, 'name' => 'Introducción y Fundamentos'],
            ],
            'generated_activities' => [
                $this->activity('label', 'Metodología', $intro),
                $this->activity('label', 'Metodología', '{{image: microscopio de laboratorio}}' . $intro),
            ],
        ];

        $result = create_course_service::create_course($this->create_session('sess-dup-002'), $resultdata);
        $this->assertDebuggingCalledCount(2);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(1, $result['duplicatesskipped']);
        $this->assertCount(1, $DB->get_records('label', ['course' => $result['courseid']]));
    }

    /**
     * Genuinely different activities must all be created: the guard compares
     * content, so the same activity type repeated with different text stays.
     */
    public function test_distinct_activities_are_all_created(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course());

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Curso de Biotecnología',
                'shortname' => 'biotec-dup-003',
            ],
            'sections_info' => [
                ['section' => 1, 'name' => 'Introducción y Fundamentos'],
            ],
            'generated_activities' => [
                $this->activity('label', '', '<p>Objetivos generales de la unidad.</p>'),
                $this->activity('label', '', '<p>Metodología de trabajo y recomendaciones.</p>'),
                $this->activity('label', '', '<p>Criterios de evaluación.</p>'),
            ],
        ];

        $result = create_course_service::create_course($this->create_session('sess-dup-003'), $resultdata);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(0, $result['duplicatesskipped']);
        $this->assertCount(3, $DB->get_records('label', ['course' => $result['courseid']]));
    }

    /**
     * The same welcome text repeated once per unit is legitimate content: the
     * signature is scoped to the section, so each unit keeps its own copy.
     */
    public function test_same_text_in_different_sections_is_kept(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->set_current_course($this->getDataGenerator()->create_course());

        $intro = '<p>Recuerda revisar la guía del estudiante.</p>';

        $resultdata = [
            'course_configuration' => [
                'fullname' => 'Curso de Biotecnología',
                'shortname' => 'biotec-dup-004',
            ],
            'sections_info' => [
                ['section' => 1, 'name' => 'Unidad 1'],
                ['section' => 2, 'name' => 'Unidad 2'],
            ],
            'generated_activities' => [
                $this->activity('label', 'Recordatorio', $intro, 1),
                $this->activity('label', 'Recordatorio', $intro, 2),
            ],
        ];

        $result = create_course_service::create_course($this->create_session('sess-dup-004'), $resultdata);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(0, $result['duplicatesskipped']);
        $this->assertCount(2, $DB->get_records('label', ['course' => $result['courseid']]));
    }
}
