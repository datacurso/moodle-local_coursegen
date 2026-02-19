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

/**
 * Privacy subsystem implementation for local_coursegen.
 *
 * @package local_coursegen
 * @author Wilber Narvaez <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

namespace local_coursegen\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use stdClass;

/**
 * Local userlist provider for local_coursegen.
 *
 */

/**
 * Class provider
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata about this plugin's stored data.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $tables = [
            'local_coursegen_system_instruction' => [
                'name', 'content', 'deleted', 'timecreated', 'timemodified', 'usermodified',
            ],
            'local_coursegen_course_context' => [
                'courseid', 'context_type', 'system_instruction_id', 'lang',
                'prompt_text', 'timecreated', 'timemodified', 'usermodified',
            ],
            'local_coursegen_course_sessions' => [
                'courseid', 'userid', 'session_id', 'status', 'timecreated', 'timemodified',
            ],
            'local_coursegen_module_jobs' => [
                'courseid', 'userid', 'job_id', 'status', 'generate_images',
                'context_type', 'system_instruction_name', 'sectionnum', 'beforemod',
                'timecreated', 'timemodified',
            ],
        ];

        foreach ($tables as $table => $fields) {
            $fielddata = [];
            foreach ($fields as $field) {
                $fielddata[$field] = get_string('privacy:metadata:' . $table . ':' . $field, 'local_coursegen');
            }
            $collection->add_database_table(
                $table,
                $fielddata,
                get_string('privacy:metadata:' . $table, 'local_coursegen')
            );
        }

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_coursegen_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }

        if (self::user_has_coursegen_data($context->instanceid)) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        $context = \context_user::instance($user->id);
        $tables = static::get_table_user_map($user);

        foreach ($tables as $table => $filterparams) {
            $records = $DB->get_recordset($table, $filterparams);
            foreach ($records as $record) {
                writer::with_context($context)->export_data([
                    get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
                    get_string('privacy:metadata:' . $table, 'local_coursegen'),
                ], $record);
            }
            $records->close();
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        if ($context->contextlevel == CONTEXT_USER) {
            self::delete_user_data($context->instanceid);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER) {
                self::delete_user_data($context->instanceid);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            self::delete_user_data($context->instanceid);
        }
    }

    /**
     * Return true if the specified userid has data in any local_coursegen tables.
     *
     * @param int $userid The user to check for.
     * @return bool
     */
    private static function user_has_coursegen_data(int $userid): bool {
        global $DB;

        $userdata = new stdClass();
        $userdata->id = $userid;

        $tables = self::get_table_user_map($userdata);
        foreach ($tables as $table => $filterparams) {
            if ($DB->record_exists($table, $filterparams)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform deletion of user data given a userid.
     *
     * @param int $userid The user ID
     */
    private static function delete_user_data(int $userid) {
        global $DB;

        $userdata = new stdClass();
        $userdata->id = $userid;

        $tables = self::get_table_user_map($userdata);
        foreach ($tables as $table => $filterparams) {
            $DB->delete_records($table, $filterparams);
        }
    }

    /**
     * Get a map of database tables that contain user data, and the filters to get records for a user.
     *
     * @param stdClass $user The user to get the map for.
     * @return array<string,array<string,int>> The table user map.
     */
    protected static function get_table_user_map(stdClass $user): array {
        // Only include tables with direct user references.
        $tables = [
            'local_coursegen_system_instruction' => ['usermodified' => $user->id],
            'local_coursegen_course_context' => ['usermodified' => $user->id],
            'local_coursegen_course_sessions' => ['userid' => $user->id],
            'local_coursegen_module_jobs' => ['userid' => $user->id],
        ];
        return $tables;
    }
}
