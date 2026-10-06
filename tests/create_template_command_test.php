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

use local_coursegen\local\placeholder\create_template_command;

/**
 * What the command that makes a template from a course accepts and prints before it reads any course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\placeholder\create_template_command
 */
final class create_template_command_test extends \basic_testcase {
    /**
     * The options of a run as cli_get_params returns them, with some of them changed.
     *
     * @param array $changes The options to change, e.g. ['courseid' => '646'].
     * @return array
     */
    private static function options(array $changes = []): array {
        $definition = create_template_command::definition();
        $defaults = $definition[0];
        return array_merge($defaults, $changes);
    }

    /**
     * Wrong uses of the command and the message each one gets.
     *
     * @return array
     */
    public static function wrong_use_provider(): array {
        return [
            'no course' => [[], '--courseid is required'],
            'a word instead of a number' => [['courseid' => 'abc'], '--courseid is required'],
            'zero' => [['courseid' => '0'], '--courseid is required'],
            'a negative number' => [['courseid' => '-4'], '--courseid is required'],
            'a decimal' => [['courseid' => '6.5'], '--courseid is required'],
            'an unknown scope' => [['courseid' => '646', 'scope' => 'galaxy'], '--scope must be course or section'],
            'replace and no-replace together' => [
                ['courseid' => '646', 'replace' => true, 'no-replace' => true],
                'cannot be used together',
            ],
        ];
    }

    /**
     * A wrong use stops before anything is read and exits with the usage code.
     *
     * @dataProvider wrong_use_provider
     * @param array $changes The options to change.
     * @param string $fragment What the message has to say.
     */
    public function test_a_wrong_use_exits_with_the_usage_code(array $changes, string $fragment): void {
        $options = self::options($changes);

        $result = create_template_command::run($options);

        $this->assertSame(create_template_command::EXIT_USAGE, $result['code']);
        $this->assertStringContainsString($fragment, $result['output']);
    }

    /**
     * With --json a wrong use is printed as JSON with its code.
     */
    public function test_a_wrong_use_is_printed_as_json_with_the_json_option(): void {
        $options = self::options(['json' => true]);

        $result = create_template_command::run($options);

        $document = json_decode($result['output'], true);
        $this->assertSame(create_template_command::EXIT_USAGE, $document['error']);
        $this->assertStringContainsString('--courseid', $document['message']);
    }

    /**
     * Help prints the usage and exits cleanly, even next to wrong options.
     */
    public function test_help_prints_the_usage(): void {
        $options = self::options(['help' => true, 'scope' => 'galaxy']);

        $result = create_template_command::run($options);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('--courseid=N', $result['output']);
        $this->assertStringContainsString('--dry-run', $result['output']);
        $this->assertStringContainsString('Exit codes', $result['output']);
    }

    /**
     * The definition offers every option of the command and the short form of help.
     */
    public function test_the_definition_lists_every_option(): void {
        $definition = create_template_command::definition();

        $names = array_keys($definition[0]);
        sort($names);
        $this->assertSame(
            ['allow-empty', 'courseid', 'dry-run', 'help', 'json', 'name', 'no-replace', 'replace', 'scope'],
            $names
        );
        $this->assertSame(['h' => 'help'], $definition[1]);
        $this->assertSame('course', $definition[0]['scope']);
    }
}
