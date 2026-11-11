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

namespace local_coursegen\local\preview\forum;

use cm_info;
use mod_forum\grades\forum_gradeitem;
use mod_forum\local\container;
use mod_forum\local\entities\forum as forum_entity;
use stdClass;

/**
 * discussion_list::render(), for a forum in which nobody has posted yet, and
 * which template draws it for a forum's type. Kept apart from view.php only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait forum_discussion_list {
    /**
     * discussion_list::render_new_discussion().
     *
     * @param stdClass $user
     * @param int|null $groupid
     * @return string
     */
    protected function render_new_discussion(stdClass $user, ?int $groupid): string {
        global $OUTPUT;
        $forumexporter = container::get_exporter_factory()->get_forum_exporter($user, $this->forum(), $groupid);

        $forumview = [
            'forum' => (array) $forumexporter->export($OUTPUT),
        ];

        return $OUTPUT->render_from_template('mod_forum/forum_new_discussion_actionbar', $forumview);
    }

    /**
     * discussion_list::render(), for a forum in which nobody has posted yet.
     *
     * @param stdClass $user
     * @param cm_info $cm
     * @param int|null $groupid
     * @return string
     */
    protected function discussion_list(stdClass $user, cm_info $cm, ?int $groupid): string {
        global $OUTPUT;

        $forum = $this->forum();
        $course = $forum->get_course_record();
        $forumgradeitem = forum_gradeitem::load_from_forum_entity($forum);
        $capabilitymanager = container::get_manager_factory()->get_capability_manager($forum);

        $forumexporter = container::get_exporter_factory()->get_forum_exporter($user, $forum, $groupid);

        $hasanyactions = false;
        $hasanyactions = $hasanyactions || $capabilitymanager->can_favourite_discussion($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_pin_discussions($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_manage_forum($user);

        $forumview = [
            'forum' => (array) $forumexporter->export($OUTPUT),
            'contextid' => $forum->get_context()->id,
            'cmid' => $cm->id,
            'groupid' => $groupid,
            'name' => format_string($forum->get_name()),
            'courseid' => $course->id,
            'coursename' => format_string($course->shortname),
            'experimentaldisplaymode' => false,
            'gradingcomponent' => $forumgradeitem->get_grading_component_name(),
            'gradingcomponentsubtype' => $forumgradeitem->get_grading_component_subtype(),
            'sendstudentnotifications' => $forum->should_notify_students_default_when_grade_for_forum(),
            'gradeonlyactiveusers' => $forumgradeitem->should_grade_only_active_users(),
            'hasanyactions' => $hasanyactions,
            'groupchangemenu' => groups_print_activity_menu($cm, $this->here, true),
            'hasmore' => false,
            'notifications' => $this->get_notifications($user, $groupid, $capabilitymanager),
            'settings' => [
                'excludetext' => true,
                'togglemoreicon' => true,
                'excludesubscription' => true
            ],
            'totaldiscussioncount' => 0,
            'userid' => $user->id,
            'visiblediscussioncount' => 0,
            // view.php passes false: the button to start a discussion is in
            // the action bar above, not in the list.
            'enablediscussioncreation' => false,
        ];

        // The form the button above would open. Without the button it has
        // no way to be reached, and a preview has nowhere to post it to.
        $forumview['newdiscussionhtml'] = '';

        return $OUTPUT->render_from_template($this->template($forum), $forumview);
    }

    /**
     * renderer factory: the discussion list template for a forum's type.
     *
     * @param forum_entity $forum
     * @return string
     */
    protected function template(forum_entity $forum): string {
        $type = $forum->get_type();
        if ($type === 'news') {
            if ($forum->get_course_id() == SITEID) {
                return 'mod_forum/frontpage_news_discussion_list';
            }
            return 'mod_forum/news_discussion_list';
        }
        $templates = [
            'qanda' => 'mod_forum/qanda_discussion_list',
            'blog' => 'mod_forum/blog_discussion_list',
        ];
        return $templates[$type] ?? 'mod_forum/discussion_list';
    }
}
