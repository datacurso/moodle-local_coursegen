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

namespace local_coursegen\local\reference;

/**
 * The token the service writes where the file of a reference belongs.
 *
 * It only ever stands as the whole value of the attribute that holds the file
 * (src, href, data or poster) and names its place the way the payload does.
 * It is replaced there and nowhere else: any other occurrence is left in place
 * for the caller to report.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_file_token {
    /** @var string A token that is the whole value of a file attribute. */
    private const ATTRIBUTE_PATTERN =
        '/(?<![\w-])(href|src|data|poster)=(["\'])\$@COURSEGENFILE\*([A-Za-z0-9_.-]{1,128}\.[0-9]{1,3})@\$\2/i';

    /** @var string What follows the start of any token still present, well formed or not. */
    private const REMAINING_PATTERN = '/\$@COURSEGENFILE\*([A-Za-z0-9_.-]*)/';

    /** @var array<string,string> Place => address of its file. */
    private array $urlbyslot;

    /**
     * Constructor.
     *
     * @param array<string,string> $urlbyslot Place => address of its file.
     */
    public function __construct(array $urlbyslot) {
        $this->urlbyslot = $urlbyslot;
    }

    /**
     * The text with the tokens whose place has a file replaced by the address of that file.
     *
     * The address is escaped for the attribute it lands in. A token for a
     * place that has no file stays where it is.
     *
     * @param string $text
     * @return string
     */
    public function replace(string $text): string {
        $callback = [$this, 'attribute_for'];
        return preg_replace_callback(self::ATTRIBUTE_PATTERN, $callback, $text);
    }

    /**
     * The attribute one token stands in, with the address of its file.
     *
     * @param array $match The attribute, its name, its quote and the place.
     * @return string
     */
    public function attribute_for(array $match): string {
        $slot = $match[3];
        if (!isset($this->urlbyslot[$slot])) {
            return $match[0];
        }
        $escaped = s($this->urlbyslot[$slot]);
        return $match[1] . '=' . $match[2] . $escaped . $match[2];
    }

    /**
     * The place of the first token still present in a text.
     *
     * @param string $text
     * @return string|null The place, an empty string for a token with no readable place, or null when none remains.
     */
    public static function first_remaining(string $text): ?string {
        if (!preg_match(self::REMAINING_PATTERN, $text, $match)) {
            return null;
        }
        return $match[1];
    }
}
