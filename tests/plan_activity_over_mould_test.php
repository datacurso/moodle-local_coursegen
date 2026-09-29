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
use local_coursegen\local\preview\plan_activity;

/**
 * Unit tests for plan_activity::over_mould().
 *
 * The mould it reads comes from the session's own stored payload, never
 * from re-deriving the template's course live: these tests give it a mould
 * that exists only in that stored payload, with no matching course/template
 * record anywhere, to prove it never tries to look one up.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\plan_activity
 *
 * @runTestsInSeparateProcesses
 */
final class plan_activity_over_mould_test extends \advanced_testcase {
    /**
     * A non-lesson modname is returned unchanged: only a lesson draft is
     * ever laid over a mould.
     */
    public function test_non_lesson_modname_returns_parameters_unchanged(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $session = $this->create_session_with_payload($this->payload_with_mould(501, []));
        $parameters = ['name' => 'A page'];

        $result = plan_activity::over_mould($parameters, 'page', 501, $session);

        $this->assertSame($parameters, $result);
    }

    /**
     * A sourcecmid of 0 - no mould at all - is returned unchanged.
     */
    public function test_zero_sourcecmid_returns_parameters_unchanged(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $session = $this->create_session_with_payload($this->payload_with_mould(501, []));
        $parameters = ['name' => 'A lesson'];

        $result = plan_activity::over_mould($parameters, 'lesson', 0, $session);

        $this->assertSame($parameters, $result);
    }

    /**
     * A drafted page overwrites the mould page it fills, matched by id; a
     * mould page the plan never drafted still comes through untouched.
     */
    public function test_drafted_page_overlays_its_mould_page(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $mouldpages = [
            $this->mould_page(24399, 'Mould title', '<p>Mould content</p>', '0', '24400'),
            $this->mould_page(24400, 'Untouched title', '<p>Untouched content</p>', '24399', '0'),
        ];
        $session = $this->create_session_with_payload($this->payload_with_mould(501, $mouldpages));
        $parameters = [
            'mod_settings' => [
                'pages' => [
                    ['id' => 24399, 'title' => 'Draft title', 'content_html' => '<p>Draft content</p>'],
                ],
            ],
        ];

        $result = plan_activity::over_mould($parameters, 'lesson', 501, $session);
        $pages = $result['mod_settings']['pages'];

        $this->assertSame('Draft title', $pages[0]['title']);
        $this->assertSame('<p>Draft content</p>', $pages[0]['content_html']);
        $this->assertSame('Untouched title', $pages[1]['title']);
    }

    /**
     * A session whose stored coursedata has no payload key - the shape it
     * had before the run's payload was persisted alongside it - degrades to
     * returning the parameters unchanged, not an error.
     */
    public function test_session_with_no_stored_payload_returns_parameters_unchanged(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $session = $this->create_session_with_payload(null);
        $parameters = ['name' => 'A lesson'];

        $result = plan_activity::over_mould($parameters, 'lesson', 501, $session);

        $this->assertSame($parameters, $result);
    }

    /**
     * A sourcecmid absent from the stored payload's own activities means
     * there is no mould to lay anything over.
     */
    public function test_sourcecmid_absent_from_payload_returns_parameters_unchanged(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $session = $this->create_session_with_payload($this->payload_with_mould(501, []));
        $parameters = ['name' => 'A lesson'];

        $result = plan_activity::over_mould($parameters, 'lesson', 999, $session);

        $this->assertSame($parameters, $result);
    }

    /**
     * A course_session whose stored coursedata is exactly what
     * start_template_generation persists: templateid alongside the run's
     * own payload, or null to simulate the pre-fix, payload-less shape.
     *
     * @param array|null $payload
     * @return course_session
     */
    private function create_session_with_payload(?array $payload): course_session {
        global $USER;

        $coursedata = ['templateid' => 12];
        if ($payload !== null) {
            $coursedata['payload'] = $payload;
        }

        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => 'test-thread',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode($coursedata),
        ]);
        $session->create();
        return $session;
    }

    /**
     * A payload whose only activity is a lesson mould at the given cmid,
     * with the given already-built pages.
     *
     * @param int $cmid
     * @param array $pages From mould_page().
     * @return array
     */
    private function payload_with_mould(int $cmid, array $pages): array {
        return [
            'activities' => [
                [
                    'cmid' => $cmid,
                    'resource_type' => 'lesson',
                    'parameters' => [
                        'name' => 'Mould lesson',
                        'structure' => [
                            'lesson' => [
                                ['pages' => [['page' => $pages]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * One raw lesson backup page node, as kept_activity::to_parameters()
     * reads it.
     *
     * @param int $id
     * @param string $title
     * @param string $contenthtml
     * @param string $prevpageid
     * @param string $nextpageid
     * @return array
     */
    private function mould_page(
        int $id,
        string $title,
        string $contenthtml,
        string $prevpageid = '0',
        string $nextpageid = '0'
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'contents' => $contenthtml,
            'layout' => 1,
            'qtype' => 20,
            'display' => 1,
            'prevpageid' => $prevpageid,
            'nextpageid' => $nextpageid,
            'answers' => [],
        ];
    }
}
