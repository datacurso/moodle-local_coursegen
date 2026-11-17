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

namespace local_coursegen\local\files;

use local_coursegen\local\space\space_scope;
use local_coursegen\utils\mold_file_copier;

/**
 * Removes from a text the elements that point at the file of a space the teacher brought nothing for.
 *
 * Without the teacher's file there is nothing for those elements to show, and the template's own file must not reach
 * the new course in its place. Only the element goes: the headings and words around it are the template's.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_element_remover {
    /** @var string An element with content (frame, embedded object, link): its tag is removed with what it holds. */
    private const PAIRED = '~<(iframe|object|a)\b([^>]*)>.*?</\1\s*>~isu';

    /** @var string An element with no content. */
    private const SINGLE = '~<(?:embed|img)\b[^>]*>~isu';

    /** @var string An attribute that can hold a file address, with the address in group 2. */
    private const ADDRESS = '~\b(?:src|href|data|poster)\s*=\s*(["\'])(.*?)\1~isu';

    /**
     * Remove the elements that point at the file of an unfilled space.
     *
     * @param string $text
     * @return string
     */
    public function strip(string $text): string {
        if (space_scope::current() === null) {
            return $text;
        }
        if (!str_contains($text, 'pluginfile.php/')) {
            return $text;
        }
        $text = preg_replace_callback(self::PAIRED, [$this, 'paired_element'], $text) ?? $text;
        return preg_replace_callback(self::SINGLE, [$this, 'single_element'], $text) ?? $text;
    }

    /**
     * An element with content, or nothing when it points at the file of an unfilled space.
     *
     * @param array $matches The element, its name and the attributes of its opening tag.
     * @return string
     */
    public function paired_element(array $matches): string {
        if ($this->points_at_unfilled_space($matches[2])) {
            return '';
        }
        return $matches[0];
    }

    /**
     * An element with no content, or nothing when it points at the file of an unfilled space.
     *
     * @param array $matches The element.
     * @return string
     */
    public function single_element(array $matches): string {
        if ($this->points_at_unfilled_space($matches[0])) {
            return '';
        }
        return $matches[0];
    }

    /**
     * Whether the attributes of a tag hold the address of the file of an unfilled space.
     *
     * @param string $attributes
     * @return bool
     */
    private function points_at_unfilled_space(string $attributes): bool {
        preg_match_all(self::ADDRESS, $attributes, $found);
        $selection = space_scope::current();
        foreach ($found[2] as $written) {
            $address = html_entity_decode($written, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $file = mold_file_copier::resolve_url($address);
            if ($file !== null && $selection->is_unfilled($file)) {
                return true;
            }
        }
        return false;
    }
}
