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

use local_coursegen\local\reference\generated_reference_files;
use local_coursegen\local\reference\reference_parameters_resolver;

/**
 * Putting the files of the teacher into the parameters a run wrote.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_parameters_resolver
 * @covers     \local_coursegen\local\reference\generated_reference_files
 */
final class reference_parameters_resolver_test extends \basic_testcase {
    /**
     * Texts at any depth are resolved and everything else is left as it was.
     */
    public function test_texts_at_any_depth_are_resolved(): void {
        $parameters = [
            'name' => 'Unit one',
            'section' => 2,
            'content' => ['text' => '<iframe src="$@COURSEGENFILE*u.1@$"></iframe>', 'format' => 1],
            'pages' => [['contents' => '<img src="$@COURSEGENFILE*u.2@$">'], ['contents' => 'Plain']],
        ];

        $urls = ['u.1' => 'https://e.com/a.pdf', 'u.2' => 'https://e.com/b.png'];

        $result = reference_parameters_resolver::resolve($parameters, $urls, 'Unit one');

        $this->assertSame('<iframe src="https://e.com/a.pdf"></iframe>', $result['content']['text']);
        $this->assertSame('<img src="https://e.com/b.png">', $result['pages'][0]['contents']);
        $this->assertSame('Plain', $result['pages'][1]['contents']);
        $this->assertSame(2, $result['section']);
        $this->assertSame(1, $result['content']['format']);
    }

    /**
     * Parameters with no token come back unchanged.
     */
    public function test_parameters_without_tokens_are_unchanged(): void {
        $parameters = ['name' => 'Unit', 'content' => ['text' => '<p>Hello</p>']];

        $result = reference_parameters_resolver::resolve($parameters, [], 'Unit');

        $this->assertSame($parameters, $result);
    }

    /**
     * A token with no file stops the run and names the activity and the place.
     */
    public function test_a_token_without_a_file_stops_the_run(): void {
        $parameters = ['content' => ['text' => '<iframe src="$@COURSEGENFILE*u.7@$"></iframe>']];

        try {
            reference_parameters_resolver::resolve($parameters, ['u.1' => 'https://e.com/a.pdf'], 'Unit one');
            $this->fail('A token with no file was left in the text.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referenceunresolved', $exception->errorcode);
            $this->assertStringContainsString('Unit one', $exception->getMessage());
            $this->assertStringContainsString('u.7', $exception->getMessage());
        }
    }

    /**
     * A token the place of which cannot even be read stops the run too.
     */
    public function test_a_broken_token_stops_the_run(): void {
        $parameters = ['content' => '<p>$@COURSEGENFILE*@$</p>'];

        $this->expectException(\moodle_exception::class);

        reference_parameters_resolver::resolve($parameters, [], 'Unit');
    }

    /**
     * Every generated activity is resolved on its own.
     */
    public function test_every_generated_activity_is_resolved(): void {
        $activities = [
            ['uid' => 'a', 'parameters' => ['name' => 'One', 'content' => '<img src="$@COURSEGENFILE*u.1@$">']],
            ['uid' => 'b', 'parameters' => ['name' => 'Two', 'content' => 'Plain']],
        ];

        $result = generated_reference_files::apply($activities, ['u.1' => 'https://e.com/a.png']);

        $this->assertSame('<img src="https://e.com/a.png">', $result[0]['parameters']['content']);
        $this->assertSame('Plain', $result[1]['parameters']['content']);
        $this->assertSame('a', $result[0]['uid']);
    }

    /**
     * An activity without a name is reported by its uid.
     */
    public function test_an_unnamed_activity_is_reported_by_its_uid(): void {
        $activities = [['uid' => 'uid-77', 'parameters' => ['content' => '<img src="$@COURSEGENFILE*u.1@$">']]];

        try {
            generated_reference_files::apply($activities, []);
            $this->fail('A token with no file was left in the text.');
        } catch (\moodle_exception $exception) {
            $this->assertStringContainsString('uid-77', $exception->getMessage());
        }
    }

    /**
     * An entry without parameters is left as it is.
     */
    public function test_an_entry_without_parameters_is_left_alone(): void {
        $result = generated_reference_files::apply([['uid' => 'a']], []);

        $this->assertSame([['uid' => 'a', 'parameters' => []]], $result);
    }

    /**
     * One activity entry, as the preview reads it, comes back with the teacher's file and nothing else changed.
     */
    public function test_one_activity_entry_is_resolved_for_the_preview(): void {
        $activity = ['uid' => 'a', 'parameters' => ['name' => 'One', 'content' => '<a href="$@COURSEGENFILE*u.1@$">x</a>']];

        $result = generated_reference_files::apply_to_activity($activity, ['u.1' => 'https://e.com/a.pdf']);

        $this->assertSame('<a href="https://e.com/a.pdf">x</a>', $result['parameters']['content']);
        $this->assertSame('One', $result['parameters']['name']);
        $this->assertSame('a', $result['uid']);
    }
}
