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

    /** @var string Opaque uid of the activity being created. */
    private static string $uid = '';

    /** @var preview_draft_store|null The draft store the files are read from. */
    private static ?preview_draft_store $store = null;

    /**
     * Run the creation of one activity with its generated files in scope.
     *
     * The files stay in the draft area of the user: the creation of the whole course discards them once it is done.
     *
     * @param string $uid Opaque uid of the activity, for example "7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10".
     * @param array[] $entries The activity's generated_files.
     * @param callable $creation Creates the activity and returns what it returns.
     * @param preview_draft_store $store Where the files of the run are stored.
     * @return mixed What the creation returned.
     */
    public static function run(string $uid, array $entries, callable $creation, preview_draft_store $store) {
        $previousentries = self::$entries;
        $previousuid = self::$uid;
        $previousstore = self::$store;
        self::$entries = self::by_name($entries);
        self::$uid = $uid;
        self::$store = $store;
        try {
            return $creation();
        } finally {
            self::$entries = $previousentries;
            self::$uid = $previousuid;
            self::$store = $previousstore;
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
        if (self::$store === null) {
            throw new \coding_exception('No generated files are in scope.');
        }
        return self::$store->get(self::$uid, $entry);
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
