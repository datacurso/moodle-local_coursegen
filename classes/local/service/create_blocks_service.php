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

defined('MOODLE_INTERNAL') || die();

/**
 * Reproduce a course's block instances (the sidebar: "Recent activity",
 * "Calendar", "Search forums", ...) from the blocks_info shape produced by
 * course_export_service::export_course().
 *
 * create_course() (course/lib.php) already calls
 * blocks_add_default_course_blocks(), which seeds the new course with the
 * theme/format default blocks - not necessarily the ones the source course
 * actually has. Those defaults are removed before the real blocks are added
 * so the new course ends up with exactly the source course's blocks.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_blocks_service {
    /**
     * Replace whatever blocks a freshly created course has with the ones
     * described by $blocksinfo.
     *
     * @param int $courseid Newly created course id.
     * @param array $blocksinfo blocks_info entries from the export.
     * @return void
     */
    public static function create_blocks(int $courseid, array $blocksinfo): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $course = get_course($courseid);
        self::remove_existing_course_blocks($course);

        if (empty($blocksinfo)) {
            return;
        }

        $page = new \moodle_page();
        $page->set_course($course);

        foreach ($blocksinfo as $blockinfo) {
            self::add_block($page, $blockinfo);
        }
    }

    /**
     * Delete every block instance living directly in the course's own
     * context - this is what blocks_add_default_course_blocks() populated -
     * via the same core API blocks/edit.php uses to delete a block, so
     * associated data (block context, positions, user prefs) is cleaned up
     * too. Blocks inherited from a parent context (showinsubcontexts) are
     * not rows here and are left untouched.
     *
     * @param \stdClass $course Newly created course record.
     * @return void
     */
    private static function remove_existing_course_blocks(\stdClass $course): void {
        global $DB;

        $coursecontext = \context_course::instance($course->id);
        $existinginstances = $DB->get_records('block_instances', ['parentcontextid' => $coursecontext->id]);
        foreach ($existinginstances as $instance) {
            blocks_delete_instance($instance);
        }
    }

    /**
     * Add one block instance to the course, using block_manager::add_block()
     * (the same public API blocks_add_default_course_blocks() uses), then
     * restore its configdata, if any, through the block's own
     * instance_config_save() so blocks that override config handling still
     * behave correctly.
     *
     * @param \moodle_page $page Page set to the destination course.
     * @param array $blockinfo One blocks_info entry.
     * @return void
     */
    private static function add_block(\moodle_page $page, array $blockinfo): void {
        $blockname = (string)($blockinfo['blockname'] ?? '');
        if ($blockname === '') {
            return;
        }

        $region = (string)($blockinfo['defaultregion'] ?? BLOCK_POS_RIGHT);
        $weight = (int)($blockinfo['defaultweight'] ?? 0);
        $showinsubcontexts = !empty($blockinfo['showinsubcontexts']);
        $pagetypepattern = (string)($blockinfo['pagetypepattern'] ?? '') ?: null;
        $subpagepattern = (string)($blockinfo['subpagepattern'] ?? '') ?: null;

        $page->blocks->add_regions([$region], false);

        try {
            $block = $page->blocks->add_block(
                $blockname,
                $region,
                $weight,
                $showinsubcontexts,
                $pagetypepattern,
                $subpagepattern
            );
        } catch (\Throwable $e) {
            debugging(
                'local_coursegen: could not add block "' . $blockname . '": ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return;
        }

        $configdata = $blockinfo['configdata'] ?? null;
        if ($block !== null && is_array($configdata) && !empty($configdata)) {
            $block->instance_config_save((object)$configdata);
        }
    }
}
