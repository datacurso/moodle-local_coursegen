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

namespace local_coursegen\local\service\mold_export;

/**
 * Unit tests for lesson_mold_export — the pre-refactor lesson payload must be reproduced byte for byte.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\lesson_mold_export
 */
final class lesson_mold_export_test extends \advanced_testcase {
    /** @var string[] The exact settings columns the lesson payload has always carried (PR-180 contract). */
    private const SETTINGS = [
        'practice', 'modattempts', 'usepassword', 'password', 'dependency', 'conditions',
        'grade', 'custom', 'ongoing', 'usemaxgrade', 'maxanswers', 'maxattempts',
        'review', 'nextpagedefault', 'feedback', 'minquestions', 'maxpages', 'timelimit',
        'retake', 'activitylink', 'mediafile', 'mediaheight', 'mediawidth', 'mediaclose',
        'slideshow', 'width', 'height', 'bgcolor', 'displayleft', 'displayleftif',
        'progressbar', 'available', 'deadline', 'completionendreached', 'completiontimespent',
    ];

    /**
     * Settings columns, name, section, intro and pages in chain order with their answer buttons.
     */
    public function test_payload_matches_the_legacy_lesson_shape(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', [
            'course' => $course->id, 'name' => 'Mold lesson', 'intro' => '<p>Intro</p>', 'section' => 2,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        // The generator inserts each new page at the BEGINNING of the chain, so the
        // second created page walks first even though it has the higher id.
        $second = $generator->create_content($lesson, [
            'title' => 'Second created', 'contents_editor' => ['text' => '<p>B</p>', 'format' => 1, 'itemid' => 0],
        ]);
        $first = $generator->create_content($lesson, [
            'title' => 'First created', 'contents_editor' => ['text' => '<p>A</p>', 'format' => 1, 'itemid' => 0],
        ]);
        $DB->insert_record('lesson_answers', (object) [
            'lessonid' => $lesson->id, 'pageid' => $first->id, 'answer' => '<p>Next</p>', 'jumpto' => -1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('lesson_answers', (object) [
            'lessonid' => $lesson->id, 'pageid' => $first->id, 'answer' => '', 'jumpto' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($lesson->cmid);

        $result = lesson_mold_export::export($cm);

        $record = $DB->get_record('lesson', ['id' => $lesson->id]);
        $expected = [];
        foreach (self::SETTINGS as $field) {
            if (isset($record->$field)) {
                $expected[$field] = $record->$field;
            }
        }
        $expected['name'] = 'Mold lesson';
        $expected['section'] = 2;
        $expected['intro'] = '<p>Intro</p>';
        $expected['mod_settings'] = ['pages' => [
            ['title' => 'First created', 'page_type' => 'content', 'content_html' => '<p>A</p>',
                'buttons' => [['text' => 'Next', 'jumpto' => -1]]],
            ['title' => 'Second created', 'page_type' => 'content', 'content_html' => '<p>B</p>', 'buttons' => []],
        ]];
        $this->assertSame($expected, $result);
        $this->assertArrayNotHasKey('introeditor', $result);
        $this->assertArrayNotHasKey('completionunlocked', $result);
    }
}
