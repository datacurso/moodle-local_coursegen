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

namespace local_coursegen\external;

use local_coursegen\local\models\template_instance;
use local_coursegen\local\service\template_export_uids;
use local_coursegen\output\template_row_options;

/**
 * Per-activity-row building for get_template_structure, kept apart from the
 * webservice contract (execute/execute_parameters/execute_returns) only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait get_template_structure_rows {
    /**
     * Build one virtual-instance activity row ("generate an activity here,
     * molded on one of the template's model activities").
     *
     * Id scheme: the row id IS the instance's stable uid
     * (template_export_uids::instance_uid()) — the same name it already
     * answers to as generationuid, so this method asks for it once and uses
     * it for both. A real activity row's id is that cmid, stringified for
     * type consistency across the whole activities array; nothing about a
     * real activity's id changes otherwise.
     *
     * Everything renders from the row's own snapshots (name, typelabel,
     * modname) — sourcecmid is never dereferenced. The icon resolves from
     * the snapshot modname through the exact same monologo rule as the
     * admin review (template_row_options::instance_icon_url()), and an
     * empty snapshot yields an empty iconhtml, not a broken image.
     *
     * @param template_instance $instance
     * @return array
     */
    private static function instance_row(template_instance $instance): array {
        $modname = (string) $instance->get('modname');
        $iconurl = template_row_options::instance_icon_url($instance->get('modname'));
        $iconhtml = '';
        if ($iconurl !== '') {
            $iconhtml = \html_writer::empty_tag('img', ['src' => $iconurl, 'class' => 'icon activityicon', 'alt' => '']);
        }
        $uid = template_export_uids::instance_uid($instance);
        $purpose = MOD_PURPOSE_OTHER;
        if ($modname !== '') {
            $purpose = self::get_purpose($modname);
        }
        return [
            'id'      => $uid,
            'name'    => format_string($instance->get('name')),
            'modname' => $modname,
            'purpose' => $purpose,
            'typelabel' => format_string($instance->get('typelabel')),
            'iconhtml' => $iconhtml,
            'locked'  => true,
            'action'  => '',
            'isinstance' => true,
            'aigenerated' => true,
            // The id this row will answer to in the generation's progress
            // events, so the live view can mark THIS activity when its own
            // content lands. Same value template_export_service sends.
            // Deliberately still a negative int, not the uid above: this one
            // is compared against real cmids (always positive) in a stream
            // event, so it must stay in that same numeric space.
            'generationcmid' => template_export_uids::instance_cmid((int) $instance->get('id')),
            // The name this row answers to in the generation's answer, which is
            // what a preview of it is asked for by — the same uid as the row's
            // own id, kept as a separate field because it names a different
            // thing (what the AI's answer calls this row, not what the tree
            // calls it), even though today they happen to be the same string.
            'generationuid' => $uid,
        ];
    }

    /**
     * Resolve a module's Moodle "purpose" (content, assessment, collaboration...).
     *
     * @param string $modname Module name.
     * @return string
     */
    private static function get_purpose(string $modname): string {
        return plugin_supports('mod', $modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER);
    }
}
