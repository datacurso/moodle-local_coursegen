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

namespace local_coursegen\local\models;

use core\persistent;

/**
 * Persistent model for table local_coursegen_tpl_section.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_section extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_coursegen_tpl_section';

    /** Behavior: the AI service may modify the section's activities. */
    const BEHAVIOR_AI_MODIFY = 'aimodify';

    /** Behavior: the section is copied as it is. */
    const BEHAVIOR_KEEP = 'keep';

    /** Behavior: the section is left out of the new course. */
    const BEHAVIOR_EXCLUDE = 'exclude';

    /** Behavior: the section is repeated once per unit of the syllabus, with an instruction of its own. */
    const BEHAVIOR_TEMPLATE = 'template';

    /** Every behavior a section can have, in the order the editor offers them. */
    const BEHAVIORS = [self::BEHAVIOR_AI_MODIFY, self::BEHAVIOR_KEEP, self::BEHAVIOR_EXCLUDE, self::BEHAVIOR_TEMPLATE];

    /** File area of the pictures and files of a template section's instruction (item: the record's id). */
    const INSTRUCTION_FILEAREA = 'sectioninstruction';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'templateid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'sectionid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'sectionnum' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'behavior' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_NOT_ALLOWED,
                'default' => self::BEHAVIOR_AI_MODIFY,
            ],
            'instruction' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'instructionformat' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => FORMAT_HTML,
            ],
        ];
    }

    /**
     * Take the files of the instruction along when the record goes.
     *
     * @param bool $result Whether the record was deleted.
     */
    protected function after_delete($result) {
        if (!$result) {
            return;
        }
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_coursegen',
            self::INSTRUCTION_FILEAREA,
            (int) $this->raw_get('id')
        );
    }
}
