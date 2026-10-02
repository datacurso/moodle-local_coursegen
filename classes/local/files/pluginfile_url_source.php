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

use local_coursegen\utils\mold_file_copier;

/**
 * The files of the template's course and the ones a teacher brought, named by their pluginfile.php address.
 *
 * An address says exactly which file it is (context, component, area, item and
 * name), so no guessing is involved; what is checked is that the current user
 * may copy that file (see mold_file_copier::can_copy).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pluginfile_url_source implements file_source {
    /** @var int|null The only course whose files may be copied; null allows any course the user can manage. */
    private ?int $sourcecourseid;

    /**
     * Constructor.
     *
     * @param int|null $sourcecourseid The template's base course, or null.
     */
    public function __construct(?int $sourcecourseid = null) {
        $this->sourcecourseid = $sourcecourseid;
    }

    #[\Override]
    public function find(file_reference $reference): ?\stored_file {
        if ($reference->kind !== file_reference::KIND_URL) {
            return null;
        }
        $file = mold_file_copier::resolve_url($reference->value);
        if ($file === null) {
            return null;
        }
        $allowed = mold_file_copier::can_copy($file, $this->sourcecourseid);
        if (!$allowed) {
            return null;
        }
        return $file;
    }
}
