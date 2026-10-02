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

/**
 * A reference to a file as a text writes it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_reference {
    /** @var string The absolute pluginfile.php address of a file of this site. */
    public const KIND_URL = 'url';

    /** @var string A "@@PLUGINFILE@@/name" placeholder, which names a file without saying where it is. */
    public const KIND_PLACEHOLDER = 'placeholder';

    /** @var string The path of an image the AI service made, to be fetched from it. */
    public const KIND_IMAGE_PATH = 'imagepath';

    /**
     * Constructor.
     *
     * @param string $kind One of the KIND_* constants.
     * @param string $value The address, the path after the placeholder (URL-encoded) or the image path.
     */
    public function __construct(
        /** @var string One of the KIND_* constants. */
        public readonly string $kind,
        /** @var string The reference as the text writes it. */
        public readonly string $value
    ) {
    }
}
