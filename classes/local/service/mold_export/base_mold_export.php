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

namespace local_coursegen\local\service\mold_export;

use cm_info;
use context;
use context_module;
use stdClass;

/**
 * Base of the per-module "mold" exporters.
 *
 * A mold is a template activity the AI service reproduces field by field:
 * its rich-text fields (with the marker convention), its repeatable rows and
 * every real setting travel as the raw ``parameters`` dict the service's
 * ``generate_<type>_for_template`` documents. Subclasses only describe what
 * is specific to their module; the course-module columns and the editor
 * dicts are built here.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_mold_export {
    /**
     * Build one activity's parameters.
     *
     * @param cm_info $cm
     * @return array The payload, or the minimal {name, section} when the instance row is gone.
     */
    public static function export(cm_info $cm): array {
        global $DB;

        $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        if (!$record) {
            return static::minimal($cm);
        }
        return static::payload($cm, $record);
    }

    /**
     * The module-specific payload.
     *
     * @param cm_info $cm
     * @param stdClass $record The module instance row.
     * @return array
     */
    abstract protected static function payload(cm_info $cm, stdClass $record): array;

    /**
     * What every module type sends when it has nothing better: enough to identify and place it.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function minimal(cm_info $cm): array {
        return [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
        ];
    }

    /**
     * Name, section, intro editor and the course-module columns shared by every mold.
     *
     * @param cm_info $cm
     * @param stdClass $record
     * @return array
     */
    protected static function common(cm_info $cm, stdClass $record): array {
        $context = context_module::instance($cm->id);
        return array_merge(
            static::minimal($cm),
            [
                'introeditor' => static::editor(
                    (string) ($record->intro ?? ''),
                    (int) ($record->introformat ?? FORMAT_HTML),
                    $context,
                    'mod_' . $cm->modname,
                    'intro',
                    null
                ),
            ],
            static::cm_columns($cm)
        );
    }

    /**
     * The course-module settings worth reproducing, under their mod_form names.
     *
     * visible/course/section are left out on purpose: a mold is typically a
     * hidden scaffold and the generated activity is placed by the service.
     *
     * @param cm_info $cm
     * @return array
     */
    protected static function cm_columns(cm_info $cm): array {
        $columns = [
            'showdescription' => $cm->showdescription,
            'completion' => $cm->completion,
            'completionview' => $cm->completionview,
            'completionexpected' => $cm->completionexpected,
            'completionpassgrade' => $cm->completionpassgrade,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            'groupmode' => $cm->groupmode,
            'groupingid' => $cm->groupingid,
            'cmidnumber' => $cm->idnumber,
            'availabilityconditionsjson' => $cm->availability,
        ];
        $exported = [];
        foreach ($columns as $key => $value) {
            if ($value !== null) {
                $exported[$key] = is_numeric($value) && $key !== 'cmidnumber' ? (int) $value : $value;
            }
        }
        $exported['completionunlocked'] = 1;
        return $exported;
    }

    /**
     * One rich-text field as the editor dict mod_form expects, with absolute file URLs.
     *
     * The service never rewrites text nodes, so @@PLUGINFILE@@ placeholders
     * would reach the generated activity verbatim and break; an absolute
     * pluginfile.php URL at least renders (a later slice copies the files).
     *
     * @param string $text
     * @param int $format
     * @param context $context
     * @param string $component
     * @param string $filearea
     * @param int|null $itemid Null for areas addressed without an item id (intro).
     * @return array {text, format}
     */
    protected static function editor(
        string $text,
        int $format,
        context $context,
        string $component,
        string $filearea,
        ?int $itemid
    ): array {
        return [
            'text' => file_rewrite_pluginfile_urls($text, 'pluginfile.php', $context->id, $component, $filearea, $itemid),
            'format' => $format,
        ];
    }

    /**
     * The given columns of an instance row, raw, skipping nulls.
     *
     * @param stdClass $record
     * @param string[] $fields
     * @return array
     */
    protected static function instance_columns(stdClass $record, array $fields): array {
        $columns = [];
        foreach ($fields as $field) {
            if (isset($record->$field)) {
                $columns[$field] = $record->$field;
            }
        }
        return $columns;
    }

    /**
     * The "grade to pass" of one of the module's grade items, if it has one.
     *
     * @param string $modname
     * @param int $instanceid
     * @param int $itemnumber
     * @return float|null
     */
    protected static function grade_pass(string $modname, int $instanceid, int $itemnumber): ?float {
        global $DB;

        $gradepass = $DB->get_field('grade_items', 'gradepass', [
            'itemtype' => 'mod', 'itemmodule' => $modname, 'iteminstance' => $instanceid, 'itemnumber' => $itemnumber,
        ]);
        return $gradepass === false ? null : (float) $gradepass;
    }
}
