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
 * The file the teacher brought, for an address that names the template's file of a space.
 *
 * A template points at a file resource from anywhere in the course by the address of its file. In the new course that
 * address means the teacher's file, so asking for the template's file gets the teacher's. An address that is not
 * the file of a space, or a space the teacher brought nothing for, is none of this source's business.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_file_source implements file_source {
    #[\Override]
    public function find(file_reference $reference): ?\stored_file {
        if ($reference->kind !== file_reference::KIND_URL) {
            return null;
        }
        $selection = space_scope::current();
        if ($selection === null) {
            return null;
        }
        $file = mold_file_copier::resolve_url($reference->value);
        if ($file === null) {
            return null;
        }
        return $selection->teacher_file_for($file);
    }
}
