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

/**
 * The blocks of a template's course, as the course made from it shows them.
 *
 * The site gives every new course its own blocks; a course made from a
 * template shows the template's instead, each one with its configuration,
 * its files and where it sits on the page, the way restoring a course puts
 * its blocks back.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_course_blocks {
    /**
     * Replace the blocks of the new course by the ones of the template's course.
     *
     * @param \stdClass $course The new course.
     * @param int $sourcecourseid The template's course.
     */
    public static function copy(\stdClass $course, int $sourcecourseid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/blocklib.php');

        $contextid = \context_course::instance($course->id)->id;
        $sourcecontextid = \context_course::instance($sourcecourseid)->id;

        self::remove_blocks($contextid);

        $sourceinstances = $DB->get_records(
            'block_instances',
            ['parentcontextid' => $sourcecontextid],
            'defaultregion, defaultweight, id'
        );
        self::copy_instances($sourceinstances, $contextid, $sourcecontextid);
    }

    /**
     * Remove the blocks the site gave a new course.
     *
     * @param int $contextid Context of the new course.
     */
    private static function remove_blocks(int $contextid): void {
        global $DB;

        $instances = $DB->get_records('block_instances', ['parentcontextid' => $contextid]);
        foreach ($instances as $instance) {
            blocks_delete_instance($instance);
        }
    }

    /**
     * Copy blocks, in the order they are given.
     *
     * @param \stdClass[] $sourceinstances Blocks of the template's course.
     * @param int $contextid Context of the new course.
     * @param int $sourcecontextid Context of the template's course.
     */
    private static function copy_instances(array $sourceinstances, int $contextid, int $sourcecontextid): void {
        foreach ($sourceinstances as $sourceinstance) {
            $instance = self::copy_instance($sourceinstance, $contextid);
            self::copy_files($sourceinstance, $instance);
            self::copy_positions($sourceinstance, $instance, $contextid, $sourcecontextid);
        }
    }

    /**
     * Create the copy of a block in the new course.
     *
     * @param \stdClass $sourceinstance The block of the template's course.
     * @param int $contextid Context of the new course.
     * @return \stdClass The new block.
     */
    private static function copy_instance(\stdClass $sourceinstance, int $contextid): \stdClass {
        global $DB;

        $instance = clone $sourceinstance;
        unset($instance->id);
        $instance->parentcontextid = $contextid;
        $instance->timecreated = time();
        $instance->timemodified = $instance->timecreated;
        $instance->id = $DB->insert_record('block_instances', $instance);
        \context_block::instance($instance->id);
        return $instance;
    }

    /**
     * Copy the files a block shows under its copy.
     *
     * @param \stdClass $sourceinstance
     * @param \stdClass $instance
     */
    private static function copy_files(\stdClass $sourceinstance, \stdClass $instance): void {
        $fs = get_file_storage();
        $sourcecontextid = \context_block::instance($sourceinstance->id)->id;
        $contextid = \context_block::instance($instance->id)->id;
        $component = 'block_' . $sourceinstance->blockname;
        $files = $fs->get_area_files($sourcecontextid, $component, false, false, 'id', false);
        foreach ($files as $file) {
            $fs->create_file_from_storedfile(['contextid' => $contextid], $file);
        }
    }

    /**
     * Copy where the template's course page places a block.
     *
     * @param \stdClass $sourceinstance
     * @param \stdClass $instance
     * @param int $contextid Context of the new course.
     * @param int $sourcecontextid Context of the template's course.
     */
    private static function copy_positions(
        \stdClass $sourceinstance,
        \stdClass $instance,
        int $contextid,
        int $sourcecontextid
    ): void {
        global $DB;

        $positions = $DB->get_records('block_positions', [
            'blockinstanceid' => $sourceinstance->id,
            'contextid' => $sourcecontextid,
        ]);
        foreach ($positions as $position) {
            unset($position->id);
            $position->blockinstanceid = $instance->id;
            $position->contextid = $contextid;
            $DB->insert_record('block_positions', $position);
        }
    }
}
