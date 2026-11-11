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

use local_coursegen\local\preview\preview_base;

/**
 * Tests for the description helper the previews share.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\preview_base_module_intro
 */
final class preview_base_module_intro_test extends \basic_testcase {
    /**
     * The module row is typed as the global stdClass, so a forum or an assignment row is accepted.
     */
    public function test_module_intro_takes_the_global_stdclass(): void {
        $method = new \ReflectionMethod(preview_base::class, 'module_intro');
        $type = $method->getParameters()[0]->getType();

        $this->assertSame(\stdClass::class, $type->getName());
    }
}
