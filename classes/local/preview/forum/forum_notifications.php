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

use core\output\notification;
use mod_forum\local\entities\forum as forum_entity;
use stdClass;

/**
 * discussion_list::get_notifications(). Kept apart from view.php only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait forum_notifications {
    /**
     * discussion_list::get_notifications().
     *
     * @param stdClass $user
     * @param int|null $groupid
     * @param \mod_forum\local\managers\capability $capabilitymanager
     * @return array
     */
    protected function get_notifications(stdClass $user, ?int $groupid, $capabilitymanager): array {
        global $OUTPUT;
        $notifications = [];
        $forum = $this->forum();

        if ($forum->is_cutoff_date_reached()) {
            $notifications[] = (new notification(
                    get_string('cutoffdatereached', 'forum'),
                    notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        if ($forum->has_blocking_enabled()) {
            $notifications[] = (new notification(
                get_string('thisforumisthrottled', 'forum', [
                    'blockafter' => $forum->get_block_after(),
                    'blockperiod' => get_string('secondstotime' . $forum->get_block_period())
                ]),
                notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        if ($forum->is_in_group_mode() && !$capabilitymanager->can_access_all_groups($user)) {
            $groupnotification = $this->group_mode_notification($forum, $user, $groupid, $capabilitymanager);
            if ($groupnotification !== null) {
                $notifications[] = $groupnotification;
            }
        }

        if ('qanda' === $forum->get_type() && !$capabilitymanager->can_manage_forum($user)) {
            $notifications[] = (new notification(
                get_string('qandanotify', 'forum'),
                notification::NOTIFY_INFO
            ))->set_show_closebutton()->set_extra_classes(['mt-3']);
        }

        if ('eachuser' === $forum->get_type()) {
            $notifications[] = (new notification(
                get_string('allowsdiscussions', 'forum'),
                notification::NOTIFY_INFO)
            )->set_show_closebutton();
        }

        return array_map(function($notification) use ($OUTPUT) {
            return $notification->export_for_template($OUTPUT);
        }, $notifications);
    }

    /**
     * The one notification a group-restricted forum shows, if any: the
     * reader is asked to pick a group, or told why they cannot post to the
     * one they are in.
     *
     * @param forum_entity $forum
     * @param stdClass $user
     * @param int|null $groupid
     * @param \mod_forum\local\managers\capability $capabilitymanager
     * @return notification|null
     */
    private function group_mode_notification(
        forum_entity $forum,
        stdClass $user,
        ?int $groupid,
        $capabilitymanager
    ): ?notification {
        if ($groupid !== null) {
            if ($capabilitymanager->can_access_group($user, $groupid)) {
                return null;
            }
            return (new notification(
                get_string('cannotadddiscussion', 'mod_forum'),
                notification::NOTIFY_WARNING
            ))->set_show_closebutton();
        }

        $isvisiblegroupsmode = $forum->get_effective_group_mode() == VISIBLEGROUPS;
        $isgroupmember = !empty(groups_get_user_groups($forum->get_course_id(), $user->id)[0]);
        if (!$capabilitymanager->can_post_to_my_groups($user) && $isvisiblegroupsmode && $isgroupmember) {
            return (new notification(
                get_string('cannotadddiscussionall', 'mod_forum'),
                notification::NOTIFY_WARNING
            ))->set_show_closebutton();
        }
        return (new notification(
            get_string('cannotadddiscussiongroup', 'mod_forum'),
            notification::NOTIFY_WARNING
        ))->set_show_closebutton();
    }
}
