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

namespace local_coursegen\local\preview;

use local_coursegen\local\link\link_token;

/**
 * Lays the text the AI wrote into a page where the preview of the page reads it.
 *
 * The run echoes the tree the template gave the page and writes its new text beside it, flat, in "content" and
 * "intro". The preview of a page reads the tree, so without this it would show the text of the template as if it
 * were the result.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_page_text {
    /** @var string[] The columns of the page row the AI writes, as the result names them. */
    private const COLUMNS = ['content', 'intro'];

    /**
     * The parameters of an activity with the text of the AI where the preview reads it.
     *
     * @param string $modname Type of the activity, for example "page".
     * @param array $parameters The activity's parameters, as the result holds them.
     * @param array $urlbyuid Uid => URL of its preview, for the links the AI left as tokens.
     * @param array $embedurlbyuid Uid => address of the file of a resource, for a file the page shows inside itself.
     * @return array The same parameters, with the page row of the tree updated.
     */
    public static function laid_in(string $modname, array $parameters, array $urlbyuid, array $embedurlbyuid = []): array {
        if ($modname !== 'page') {
            return $parameters;
        }
        $row = $parameters['structure']['page'][0] ?? null;
        if (!is_array($row)) {
            return $parameters;
        }
        foreach (self::COLUMNS as $column) {
            if (isset($parameters[$column]) && is_string($parameters[$column])) {
                $row[$column] = link_token::replace($parameters[$column], $urlbyuid, $embedurlbyuid);
            }
        }
        $parameters['structure']['page'][0] = $row;
        return $parameters;
    }
}
