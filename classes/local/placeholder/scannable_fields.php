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

namespace local_coursegen\local\placeholder;

/**
 * Which fields of each activity type carry html a template author can put placeholders in.
 *
 * Every activity has an intro. Some types keep more html in fields of their own or in the rows of a child table; the
 * list follows the fields of the template v6 manifest and the types the AI service has a content contract for.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scannable_fields {
    /** @var string[] The column every activity table has. */
    private const INTRO = 'intro';

    /** @var array<string, string[]> The columns of the module table itself, besides the intro. */
    private const OWN_COLUMNS = [
        'assign' => ['activity'],
        'page' => ['content'],
        'workshop' => ['instructauthors', 'instructreviewers', 'conclusion'],
    ];

    /** @var array<string, array[]> The child tables of a module: {table, key, columns}. */
    private const CHILD_TABLES = [
        'book' => [['table' => 'book_chapters', 'key' => 'bookid', 'columns' => ['title', 'content']]],
        'choice' => [['table' => 'choice_options', 'key' => 'choiceid', 'columns' => ['text']]],
        'feedback' => [['table' => 'feedback_item', 'key' => 'feedback', 'columns' => ['name', 'presentation']]],
        'lesson' => [['table' => 'lesson_pages', 'key' => 'lessonid', 'columns' => ['title', 'contents']]],
    ];

    /**
     * The columns of the module table that can hold placeholders.
     *
     * @param string $modname Module type, e.g. 'page'.
     * @return string[] E.g. ['intro', 'content'].
     */
    public static function own_columns(string $modname): array {
        $extra = self::OWN_COLUMNS[$modname] ?? [];
        return array_merge([self::INTRO], $extra);
    }

    /**
     * The child tables of a module that can hold placeholders.
     *
     * @param string $modname Module type, e.g. 'lesson'.
     * @return array[] Each {table, key, columns}.
     */
    public static function child_tables(string $modname): array {
        $tables = self::CHILD_TABLES[$modname] ?? [];
        return $tables;
    }
}
