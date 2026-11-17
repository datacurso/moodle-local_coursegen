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

namespace local_coursegen\local\service;

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\placeholder\activity_scanner;

/**
 * An activity of the template "modified with AI": the new course has it changed by the AI, in its place.
 *
 * It needs no mold and no instance from the admin: when the template is saved, the activity gets one instance of its
 * own, placed right after it, which is what the AI generates. With markers, the activity is the mold of that instance
 * (its markers are filled, the instruction says the rest); without markers, the activity is the reference the AI reads
 * and the instruction says how to change it. Either one is enough, and both can be there.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_modified_activity {
    /**
     * Save the instance of an activity modified with AI.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param int $cmid The activity.
     * @param string $instruction What the admin wrote; may be empty when the activity has markers.
     * @throws \invalid_parameter_exception When the activity has neither an instruction nor markers.
     */
    public static function save(int $templateid, int $sectionid, int $cmid, string $instruction): void {
        $cm = self::cm_of($cmid);
        $hasmarkers = self::has_markers($cm);
        $instruction = trim($instruction);
        if ($instruction === '' && !$hasmarkers) {
            throw new \invalid_parameter_exception(
                get_string('template_aimodify_instruction_required', 'local_coursegen', format_string($cm->name))
            );
        }
        $instance = new template_instance(0);
        $instance->set('uid', \core\uuid::generate());
        $instance->set('templateid', $templateid);
        $instance->set('sectionid', $sectionid);
        // With markers the activity is the mold; without them the instance is written freely, the activity as reference.
        $instance->set('sourcecmid', $hasmarkers ? $cmid : 0);
        $instance->set('sourcename', (string) $cm->name);
        $instance->set('name', (string) $cm->name);
        $instance->set('typelabel', (string) $cm->get_module_type_name());
        $instance->set('modname', $cm->modname);
        $instance->set('prompt', $instruction);
        $instance->set('aftercmid', $cmid);
        $instance->set('sortorder', -1);
        $instance->set('ownercmid', $cmid);
        $instance->create();
    }

    /**
     * The action the generation is given for an activity modified with AI: its mold, or its reference.
     *
     * @param \cm_info $cm
     * @return string template_activity::ACTION_TEMPLATE or template_activity::ACTION_REFERENCE.
     */
    public static function export_action(\cm_info $cm): string {
        return self::has_markers($cm) ? template_activity::ACTION_TEMPLATE : template_activity::ACTION_REFERENCE;
    }

    /**
     * Whether the activity has markers the AI fills.
     *
     * @param \cm_info $cm
     * @return bool
     */
    public static function has_markers(\cm_info $cm): bool {
        return activity_scanner::scan($cm)->has_placeholders();
    }

    /**
     * The activity, as the course shows it.
     *
     * @param int $cmid
     * @return \cm_info
     */
    private static function cm_of(int $cmid): \cm_info {
        [$course, $cm] = get_course_and_cm_from_cmid($cmid);
        return get_fast_modinfo($course)->get_cm($cm->id);
    }
}
