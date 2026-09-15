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
 * Persistent model for table local_coursegen_tpl_instance.
 *
 * A virtual activity: it has no real course module of its own and never
 * appears in the base course. It only exists inside this template's saved
 * configuration, and represents "generate an activity here from the
 * template marked at sourcecmid when a course is created from this
 * template". sourcename and typelabel are snapshotted from the source
 * template at creation time so this row still renders correctly even if its
 * source is later deleted or unmarked as a template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_instance extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_coursegen_tpl_instance';

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
            // The cmid of the activity marked action=template this instance
            // was created from. Never dereferenced to render this row (see
            // sourcename/typelabel below); kept so a future course-generation
            // step can resolve which template to generate content from.
            'sourcecmid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            // Snapshot of the source template's display name at the moment
            // this instance was created, used for this row's "Instance of"
            // badge without depending on the source still existing/being
            // marked as a template.
            'sourcename' => [
                'type' => PARAM_TEXT,
                'null' => NULL_NOT_ALLOWED,
            ],
            // This instance's own admin-editable display name.
            'name' => [
                'type' => PARAM_TEXT,
                'null' => NULL_NOT_ALLOWED,
            ],
            // Snapshot of the source template's module type label, for the
            // Type column.
            'typelabel' => [
                'type' => PARAM_TEXT,
                'null' => NULL_NOT_ALLOWED,
            ],
            // Snapshot of the source template's own module type (e.g.
            // "lesson"), so this row's icon can be resolved the exact same
            // way a real activity's is (image_url('icon', $modname)) —
            // never dereferencing sourcecmid to get it, same reasoning as
            // sourcename/typelabel above. Null on rows saved before this
            // field existed, which fall back to a generic icon.
            'modname' => [
                'type' => PARAM_PLUGIN,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'prompt' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            // The real cmid this instance renders immediately after within
            // its section; 0 means "the start of the section".
            'aftercmid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            // Tiebreaker order among instances sharing the same aftercmid.
            'sortorder' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
        ];
    }
}
