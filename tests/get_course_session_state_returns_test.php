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

use local_coursegen\external\get_course_session_state;

/**
 * Tests for what the session state web service answers to a page that is being reloaded.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_session_state::execute_returns
 */
final class get_course_session_state_returns_test extends \advanced_testcase {
    /**
     * The answer names the syllabus the session was started with, so the page can show its chip again.
     */
    public function test_the_answer_has_the_syllabus_name(): void {
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');

        $returns = get_course_session_state::execute_returns();

        $this->assertArrayHasKey('syllabusname', $returns->keys);
        $this->assertSame(PARAM_TEXT, $returns->keys['syllabusname']->type);
    }

    /**
     * The fields the page already reads are still there.
     */
    public function test_the_answer_keeps_the_fields_the_page_reads(): void {
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');

        $keys = array_keys(get_course_session_state::execute_returns()->keys);

        foreach (['success', 'recordid', 'sessionid', 'streamingurl', 'sessionstatus', 'snapshotjson', 'coursedatajson'] as $key) {
            $this->assertContains($key, $keys);
        }
    }
}
