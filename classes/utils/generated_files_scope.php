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

namespace local_coursegen\utils;

/**
 * The generated files of the activity being created.
 *
 * The texts of an activity reach the file areas of the new module through several classes (the text editor cleaner,
 * the module settings of a lesson, a book, a glossary...). All of them need to know which names in
 * "@@PLUGINFILE@@/name" are files the AI service made for this activity, so the creation of an activity runs inside
 * this scope instead of every class being handed the list.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_files_scope {
    /** @var array[] File name => entry of the activity being created. */
    private static array $entries = [];

    /** @var generated_file_cache|null The cache the files are read through. */
    private static ?generated_file_cache $cache = null;

    /**
     * Run the creation of one activity with its generated files in scope.
     *
     * The stored copies are removed after a creation that succeeded: the draft saves moved the files into the
     * new activity, and nothing else needs them. A failed creation leaves them for the next attempt.
     *
     * @param array[] $entries The activity's generated_files.
     * @param callable $creation Creates the activity and returns what it returns.
     * @param generated_file_cache|null $cache Replaces the default cache; tests pass their own.
     * @return mixed What the creation returned.
     */
    public static function run(array $entries, callable $creation, ?generated_file_cache $cache = null) {
        $previousentries = self::$entries;
        $previouscache = self::$cache;
        self::$entries = self::by_name($entries);
        self::$cache = $cache ?? new generated_file_cache();
        try {
            $created = $creation();
            foreach ($entries as $entry) {
                self::$cache->forget($entry);
            }
            return $created;
        } finally {
            self::$entries = $previousentries;
            self::$cache = $previouscache;
        }
    }

    /**
     * The entry of a generated file of the activity being created.
     *
     * @param string $filename
     * @return array|null Null when no generated file has this name, or nothing is being created.
     */
    public static function entry_named(string $filename): ?array {
        return self::$entries[$filename] ?? null;
    }

    /**
     * The stored file of an entry of the activity being created.
     *
     * @param array $entry
     * @return \stored_file
     */
    public static function stored_file(array $entry): \stored_file {
        if (self::$cache === null) {
            throw new \coding_exception('No generated files are in scope.');
        }
        return self::$cache->get($entry);
    }

    /**
     * Entries keyed by file name.
     *
     * @param array[] $entries
     * @return array[]
     */
    private static function by_name(array $entries): array {
        $named = [];
        foreach ($entries as $entry) {
            $named[(string) ($entry['filename'] ?? '')] = $entry;
        }
        return $named;
    }
}
