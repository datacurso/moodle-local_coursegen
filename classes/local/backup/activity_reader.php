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

namespace local_coursegen\local\backup;

use backup;
use backup_nested_element;
use ReflectionMethod;

/**
 * Reads any activity completely, by asking the activity's own module how.
 *
 * Describing an activity to something outside Moodle used to mean writing, per
 * module, which of its tables hold what: the book's chapters, the lesson's
 * pages and their answers, the quiz's questions. That description has to be
 * written once per type, kept correct as each module changes, and it is wrong
 * the moment a module gains a field.
 *
 * Every module already carries that description. It is what backup uses, it
 * lives in backup/moodle2/backup_<mod>_stepslib.php, and it is exact enough
 * that restore rebuilds the activity from it. So instead of describing modules,
 * this asks each module for its own description and reads the answer.
 *
 * What comes back is the same tree the activity's XML would hold in a backup
 * file, as nested arrays: the module's own settings at the top, and every list
 * that belongs to it underneath, each element carrying the id the module gave
 * it. No file is written and no backup is taken.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_reader {
    /**
     * Everything one activity is made of.
     *
     * @param \cm_info|\stdClass $cm The course module to read.
     * @return array The activity's tree, or an empty array when its module
     *               does not support being backed up and therefore has no
     *               description of itself to give.
     */
    public static function read($cm): array {
        return self::read_with_sources($cm)['tree'];
    }

    /**
     * Everything one activity is made of, and where each part came from.
     *
     * @param \cm_info|\stdClass $cm
     * @return array {tree, tables, aliases}: the tree as read() gives it,
     *               element name => table, and element name => alias => column.
     */
    public static function read_with_sources($cm): array {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        // The moodle2 layer a module's own step is built on: the activity task
        // it belongs to and the steps it extends. The plan builder is what
        // brings all of it in, and is the only part of a backup plan used here.
        require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');

        $modname = (string) $cm->modname;
        $cmid = (int) $cm->id;

        // Supporting backup is what having a declared structure means. A
        // module without one cannot be read this way and must not be guessed
        // at, so it is reported as nothing rather than as something partial.
        $nothing = ['tree' => [], 'tables' => [], 'aliases' => []];
        if (!plugin_supports('mod', $modname, FEATURE_BACKUP_MOODLE2)) {
            return $nothing;
        }

        $taskfile = $CFG->dirroot . '/mod/' . $modname . '/backup/moodle2/backup_' . $modname . '_activity_task.class.php';
        if (!file_exists($taskfile)) {
            return $nothing;
        }
        // Loading the module's task is what loads its stepslib: the task file
        // requires it, the same way a real backup reaches it.
        require_once($taskfile);

        $stepclass = 'backup_' . $modname . '_activity_structure_step';
        if (!class_exists($stepclass)) {
            return $nothing;
        }

        $task = new reader_task('local_coursegen_read_' . $modname, $cmid, (int) $cm->course);
        $step = new $stepclass('local_coursegen_read_structure', $modname . '.xml', $task);

        // Each module declares its structure in a method meant to be called by
        // the step that runs it. Nothing else about the step is used.
        $define = new ReflectionMethod($stepclass, 'define_structure');
        $define->setAccessible(true);
        $structure = $define->invoke($step);
        if (!$structure instanceof backup_nested_element) {
            return $nothing;
        }

        // A structure names the activity it describes through these rather
        // than hardcoding it, which is what lets one declaration serve every
        // instance of its module.
        $sectionid = (int) $task->get_sectionid();
        $activityid = (int) $task->get_activityid();
        $contextid = (int) $task->get_contextid();

        $processor = new structure_array_processor();
        $processor->set_var(backup::VAR_MODID, $cmid);
        $processor->set_var(backup::VAR_COURSEID, (int) $cm->course);
        $processor->set_var(backup::VAR_SECTIONID, $sectionid);
        $processor->set_var(backup::VAR_MODNAME, $modname);
        $processor->set_var(backup::VAR_ACTIVITYID, $activityid);
        $processor->set_var(backup::VAR_CONTEXTID, $contextid);
        $processor->set_var(backup::VAR_BACKUPID, 'local_coursegen');

        $structure->process($processor);

        $tree = $processor->get_result();
        $tables = $processor->get_tables();
        $aliases = $processor->get_aliases();
        return ['tree' => $tree, 'tables' => $tables, 'aliases' => $aliases];
    }
}
