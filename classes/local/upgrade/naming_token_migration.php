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

namespace local_coursegen\local\upgrade;

/**
 * Renames the token of the section naming patterns already stored.
 *
 * The token that stands for the original section's name used to be written
 * with a word of another language. Stored patterns keep it until this runs.
 * Both tokens are frozen here on purpose: an upgrade step describes one
 * change from one state to another, so it must not follow later renames.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class naming_token_migration {
    /** The token stored patterns used for the original section's name. */
    const OLD_NAME_TOKEN = '{nombre}';

    /** The token that replaces it. */
    const NEW_NAME_TOKEN = '{name}';

    /**
     * Replace the old token with the new one, wherever it appears in a stored pattern.
     *
     * Only the exact, case-sensitive token is touched, so the text around it
     * in a custom pattern is kept as it is, and a pattern without the token
     * (or without a pattern at all) is left alone.
     */
    public static function run(): void {
        global $DB;

        $escaped = $DB->sql_like_escape(self::OLD_NAME_TOKEN);
        $like = $DB->sql_like('namingpattern', ':pattern', true);
        $sql = "UPDATE {local_coursegen_template}
                   SET namingpattern = REPLACE(namingpattern, :oldtoken, :newtoken)
                 WHERE $like";
        $params = [
            'oldtoken' => self::OLD_NAME_TOKEN,
            'newtoken' => self::NEW_NAME_TOKEN,
            'pattern' => '%' . $escaped . '%',
        ];
        $DB->execute($sql, $params);
    }
}
