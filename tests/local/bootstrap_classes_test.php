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

namespace local_coursegen\local;

/**
 * Tests for the Bootstrap class names given to the templates and the JavaScript.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\bootstrap_classes
 */
final class bootstrap_classes_test extends \basic_testcase {
    /**
     * Moodle 4.5 gets the Bootstrap 4 names.
     */
    public function test_moodle_45_gets_bootstrap4_names(): void {
        $classes = bootstrap_classes::get(405);

        $this->assertFalse(bootstrap_classes::is_bootstrap5(405));
        $this->assertSame('sr-only', $classes['visuallyhidden']);
        $this->assertSame('mr-2', $classes['iconspacing2']);
        $this->assertSame('text-right', $classes['textend']);
        $this->assertSame('font-weight-bold', $classes['fontbold']);
        $this->assertSame('custom-control custom-switch', $classes['switchwrapper']);
        $this->assertSame('custom-control-input', $classes['checkinput']);
        $this->assertSame('custom-select custom-select-sm', $classes['selectsmall']);
    }

    /**
     * Moodle 5.0 and later get the Bootstrap 5 names.
     *
     * @dataProvider bootstrap5_branches_provider
     * @param int $branch
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bootstrap5_branches_provider')]
    public function test_moodle_5_gets_bootstrap5_names(int $branch): void {
        $classes = bootstrap_classes::get($branch);

        $this->assertTrue(bootstrap_classes::is_bootstrap5($branch));
        $this->assertSame('visually-hidden', $classes['visuallyhidden']);
        $this->assertSame('me-2', $classes['iconspacing2']);
        $this->assertSame('text-end', $classes['textend']);
        $this->assertSame('fw-bold', $classes['fontbold']);
        $this->assertSame('form-check form-switch', $classes['switchwrapper']);
        $this->assertSame('form-check-input', $classes['checkinput']);
        $this->assertSame('form-select form-select-sm', $classes['selectsmall']);
    }

    /**
     * Branches that ship Bootstrap 5.
     *
     * @return array
     */
    public static function bootstrap5_branches_provider(): array {
        return [
            'Moodle 5.0' => [500],
            'Moodle 5.3' => [503],
        ];
    }

    /**
     * Both releases give the same keys, so a template never meets a missing one.
     */
    public function test_both_releases_give_the_same_keys(): void {
        $this->assertSame(array_keys(bootstrap_classes::get(405)), array_keys(bootstrap_classes::get(503)));
    }

    /**
     * No Bootstrap 4 name is left in the Bootstrap 5 set.
     */
    public function test_bootstrap5_set_has_no_bootstrap4_name(): void {
        $bootstrap5 = implode(' ', bootstrap_classes::get(503));

        $this->assertDoesNotMatchRegularExpression(
            '/\b(sr-only|custom-[a-z-]+|(mr|ml|pr|pl)-\d|text-right|font-weight-[a-z]+|badge-light)\b/',
            $bootstrap5
        );
    }
}
