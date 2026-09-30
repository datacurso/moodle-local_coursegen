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

use local_coursegen\local\upgrade\naming_token_migration;

/**
 * The upgrade step that renames the token of the stored naming patterns.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\upgrade\naming_token_migration
 */
final class naming_token_migration_test extends \advanced_testcase {
    /**
     * Store a template row with a naming pattern.
     *
     * @param string|null $pattern The pattern to store.
     * @return int The new template id.
     */
    private function store_pattern(?string $pattern): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $row = (object) [
            'name' => 'Template',
            'courseid' => $course->id,
            'namingpattern' => $pattern,
            'namingstart' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 2,
        ];
        return $DB->insert_record('local_coursegen_template', $row);
    }

    /**
     * Read back the pattern a template stores.
     *
     * @param int $id The template id.
     * @return string|null
     */
    private function stored_pattern(int $id): ?string {
        global $DB;

        return $DB->get_field('local_coursegen_template', 'namingpattern', ['id' => $id]);
    }

    /**
     * The old token becomes the new one and the text around it is kept.
     */
    public function test_the_old_token_is_renamed_inside_a_custom_pattern(): void {
        $this->resetAfterTest();
        $id = $this->store_pattern('Chapter {N} - {nombre}');

        naming_token_migration::run();

        $this->assertSame('Chapter {N} - {name}', $this->stored_pattern($id));
    }

    /**
     * The name-only pattern, which is the token alone, is renamed too.
     */
    public function test_the_name_only_pattern_is_renamed(): void {
        $this->resetAfterTest();
        $id = $this->store_pattern('{nombre}');

        naming_token_migration::run();

        $this->assertSame('{name}', $this->stored_pattern($id));
    }

    /**
     * Every occurrence in one pattern is renamed, not only the first.
     */
    public function test_every_occurrence_in_a_pattern_is_renamed(): void {
        $this->resetAfterTest();
        $id = $this->store_pattern('{nombre} / {nombre}');

        naming_token_migration::run();

        $this->assertSame('{name} / {name}', $this->stored_pattern($id));
    }

    /**
     * Patterns without the old token, and templates without a pattern, stay as they are.
     */
    public function test_patterns_without_the_old_token_are_left_alone(): void {
        $this->resetAfterTest();
        $plain = $this->store_pattern('Unit {N}');
        $current = $this->store_pattern('Unit {N}: {name}');
        $empty = $this->store_pattern('');
        $none = $this->store_pattern(null);

        naming_token_migration::run();

        $this->assertSame('Unit {N}', $this->stored_pattern($plain));
        $this->assertSame('Unit {N}: {name}', $this->stored_pattern($current));
        $this->assertSame('', $this->stored_pattern($empty));
        $this->assertNull($this->stored_pattern($none));
    }

    /**
     * Only the exact token is renamed: a different case or a look-alike is not a token.
     */
    public function test_only_the_exact_token_is_renamed(): void {
        $this->resetAfterTest();
        $id = $this->store_pattern('{Nombre} {nombre2} nombre');

        naming_token_migration::run();

        $this->assertSame('{Nombre} {nombre2} nombre', $this->stored_pattern($id));
    }

    /**
     * Running it twice gives the same result as running it once.
     */
    public function test_running_it_twice_changes_nothing_more(): void {
        $this->resetAfterTest();
        $id = $this->store_pattern('Week {N}: {nombre}');

        naming_token_migration::run();
        naming_token_migration::run();

        $this->assertSame('Week {N}: {name}', $this->stored_pattern($id));
    }
}
