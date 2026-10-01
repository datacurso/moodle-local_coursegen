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
 * Persistent model for table local_coursegen_tpl_activity.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_coursegen_tpl_activity';

    /** Action: the activity is copied into the new course as it is. */
    const ACTION_KEEP = 'keep';

    /** Action: the AI service rewrites the activity. */
    const ACTION_MODIFY = 'modify';

    /** Action: the activity is only context for the AI service. */
    const ACTION_REFERENCE = 'reference';

    /** Action: the activity is left out of the new course. */
    const ACTION_EXCLUDE = 'exclude';

    /** Action: the activity is a mold other activities are generated from. */
    const ACTION_TEMPLATE = 'template';

    /** Action: the professor provides this activity instead. */
    const ACTION_SPACE = 'space';

    /** Template scope: the mold serves the whole course. */
    const SCOPE_COURSE = 'course';

    /** Template scope: the mold serves only its own section. */
    const SCOPE_SECTION = 'section';

    /** The template scopes a mold can have. */
    const SCOPES = [self::SCOPE_COURSE, self::SCOPE_SECTION];

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
            'cmid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'action' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_NOT_ALLOWED,
                'default' => self::ACTION_MODIFY,
            ],
            'useasreference' => [
                'type' => PARAM_INT,
                'default' => 1,
            ],
            // Only meaningful when action=template: whether this molde may be
            // used by "modify" activities anywhere in the course, or only by
            // ones in this same section. Ignored for every other action.
            'templatescope' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_NOT_ALLOWED,
                'default' => self::SCOPE_COURSE,
            ],
            'prompt' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            // Only meaningful when action=space: the professor must provide
            // this activity (1) or may skip it (0).
            'spacerequired' => [
                'type' => PARAM_INT,
                'default' => 1,
            ],
            // Only meaningful when action=space: what the professor has to
            // provide in place of this activity.
            'spaceinstruction' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }
}
