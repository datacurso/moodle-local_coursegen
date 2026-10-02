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

use local_coursegen\local\preview\result_activity_check;

/**
 * Tests for the check a finished activity passes before it is previewed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\result_activity_check
 */
final class result_activity_check_test extends \basic_testcase {
    /**
     * A finished lesson whose pages carry the id of the template page each came from.
     *
     * @param array $pages The pages of mod_settings.
     * @return array
     */
    private function lesson(array $pages): array {
        return [
            'uid' => 'lesson-uid',
            'resource_type' => 'lesson',
            'parameters' => [
                'name' => 'Week 7',
                'structure' => ['lesson' => [['id' => '5', 'pages' => [['page' => [
                    ['id' => '11', 'title' => 'Intro'],
                    ['id' => '12', 'title' => 'Intro'],
                ]]]]]],
                'mod_settings' => ['pages' => $pages],
            ],
        ];
    }

    /**
     * Records that name a record of the tree, and records the AI added, are current.
     */
    public function test_a_result_with_its_tree_and_record_ids_passes(): void {
        $activity = $this->lesson([
            ['source_id' => '11', 'title' => 'Intro'],
            ['source_id' => '12', 'title' => 'Intro'],
            ['source_id' => null, 'title' => 'Added by the AI'],
        ]);

        result_activity_check::assert_current($activity);

        $this->assertTrue(true);
    }

    /**
     * A list of plain texts holds no records, so it needs no ids.
     */
    public function test_a_list_of_plain_texts_is_not_checked(): void {
        $activity = $this->lesson([]);
        $activity['parameters']['mod_settings']['tags'] = ['one', 'two'];
        $activity['parameters']['mod_settings']['rubric'] = null;

        result_activity_check::assert_current($activity);

        $this->assertTrue(true);
    }

    /**
     * A result without the template's tree was generated before the tree travelled with it.
     */
    public function test_a_result_without_its_tree_is_outdated(): void {
        $activity = $this->lesson([]);
        unset($activity['parameters']['structure']);

        $exception = $this->refusal_of($activity);

        $this->assertSame('courseai_preview_result_outdated', $exception->errorcode);
        $this->assertSame('Week 7', $exception->a->activity);
    }

    /**
     * A record that does not say where it came from is a result of before record ids existed.
     */
    public function test_a_record_without_source_id_is_outdated(): void {
        $activity = $this->lesson([['title' => 'Intro']]);

        $exception = $this->refusal_of($activity);

        $this->assertSame('courseai_preview_result_outdated', $exception->errorcode);
    }

    /**
     * A record that names an id the tree does not have is reported with its activity and its id.
     */
    public function test_an_unknown_source_id_is_an_error_naming_activity_and_record(): void {
        $activity = $this->lesson([['source_id' => '99', 'title' => 'Intro']]);

        $exception = $this->refusal_of($activity);

        $this->assertSame('courseai_preview_record_unknown', $exception->errorcode);
        $this->assertSame('Week 7', $exception->a->activity);
        $this->assertSame('pages: 99', $exception->a->record);
    }

    /**
     * The questions of a quiz travel beside its tree, and their ids count as known.
     */
    public function test_the_ids_of_the_questions_beside_the_tree_are_known(): void {
        $activity = [
            'uid' => 'quiz-uid',
            'resource_type' => 'quiz',
            'parameters' => [
                'name' => 'Quiz',
                'structure' => ['quiz' => [['id' => '3']]],
                'questions' => [['slot' => 1, 'question' => ['id' => '77']]],
                'mod_settings' => ['questions' => [['source_id' => '77', 'questiontext' => 'Q']]],
            ],
        ];

        result_activity_check::assert_current($activity);

        $this->assertTrue(true);
    }

    /**
     * An unnamed activity is reported by its uid.
     */
    public function test_an_unnamed_activity_is_reported_by_its_uid(): void {
        $activity = $this->lesson([['title' => 'Intro']]);
        unset($activity['parameters']['name']);

        $exception = $this->refusal_of($activity);

        $this->assertSame('lesson-uid', $exception->a->activity);
    }

    /**
     * What the check refuses an activity with.
     *
     * @param array $activity
     * @return \moodle_exception
     */
    private function refusal_of(array $activity): \moodle_exception {
        try {
            result_activity_check::assert_current($activity);
        } catch (\moodle_exception $exception) {
            return $exception;
        }
        $this->fail('The activity must be refused');
    }
}
