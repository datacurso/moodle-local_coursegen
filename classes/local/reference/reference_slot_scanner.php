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

use cm_info;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\service\template_activity_export;
use local_coursegen\local\service\template_reference_scanner;

/**
 * Finds the places of a template where the teacher may bring a file.
 *
 * Only the activities the service reads as a template source are searched:
 * the ones saved with the action "template". A kept activity is copied as it is and never
 * rewritten, so a marker in one would reach the course as plain text. The
 * markers are counted over the same parameters, in the same order, that the
 * export sends to the service, which is what makes the ordinal of a slot mean
 * the same thing on both sides.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_slot_scanner {
    /** @var string[] The actions whose activity is read by the service as a source. */
    private const SOURCE_ACTIONS = [template_activity::ACTION_TEMPLATE];

    /**
     * The slots of a template.
     *
     * @param int $templateid
     * @return reference_slot[] In the order of the course's activities and then of their markers.
     * @throws \moodle_exception When the template does not exist.
     */
    public static function for_template(int $templateid): array {
        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }
        $courseid = $template->get('courseid');
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();
        $sourcecmids = self::source_cmids($templateid);

        $slots = [];
        foreach ($cms as $cm) {
            if (!isset($sourcecmids[$cm->id])) {
                continue;
            }
            $found = self::slots_of_activity($cm);
            $slots = array_merge($slots, $found);
        }
        return $slots;
    }

    /**
     * The template activities that are read as a source, keyed by their cmid.
     *
     * @param int $templateid
     * @return array<int,true>
     */
    private static function source_cmids(int $templateid): array {
        $records = template_activity::get_records(['templateid' => $templateid]);
        $cmids = [];
        foreach ($records as $record) {
            $action = $record->get('action');
            if (!in_array($action, self::SOURCE_ACTIONS, true)) {
                continue;
            }
            $cmid = $record->get('cmid');
            $cmids[(int) $cmid] = true;
        }
        return $cmids;
    }

    /**
     * The slots of one activity, numbered over its parameters in export order by the service's own scanner.
     *
     * The activity's cmid stands where the payload uid stands in the name of a place, so the name does not
     * change between one export and the next.
     *
     * @param cm_info $cm
     * @return reference_slot[]
     * @throws \moodle_exception When a marker has no usable target.
     */
    private static function slots_of_activity(cm_info $cm): array {
        $parameters = template_activity_export::parameters_for($cm);
        $cmid = (int) $cm->id;
        $found = template_reference_scanner::slots($parameters, (string) $cmid, $cm->name);
        $slots = [];
        foreach ($found as $slot) {
            $kind = reference_file_policy::kind_of($slot['mimetype'], $slot['filename']);
            $slots[] = new reference_slot($cmid, $slot['ordinal'], $cm->name, $slot['instruction'], $kind);
        }
        return $slots;
    }
}
