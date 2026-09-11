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
 * Privacy provider for local_coursegen.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
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
                'courseid', 'userid', 'session_id', 'status', 'coursedata', 'timecreated', 'timemodified',
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
                $fielddata[$field] = 'privacy:metadata:' . $table . ':' . $field;
            }
            $collection->add_database_table(
                $table,
                $fielddata,
                'privacy:metadata:' . $table
            );
        }

        // Data sent to the external Datacurso course generation service
        // (planning prompts, activity instructions, syllabus files and the
        // request context composed by the provider layer).
        $collection->add_external_location_link('datacurso_course_service', [
            'prompt' => 'privacy:metadata:datacurso_course_service:prompt',
            'instructions' => 'privacy:metadata:datacurso_course_service:instructions',
            'syllabus_file' => 'privacy:metadata:datacurso_course_service:syllabus_file',
            'lang' => 'privacy:metadata:datacurso_course_service:lang',
            'with_images' => 'privacy:metadata:datacurso_course_service:with_images',
            'userid' => 'privacy:metadata:datacurso_course_service:userid',
            'site_id' => 'privacy:metadata:datacurso_course_service:site_id',
            'site_url' => 'privacy:metadata:datacurso_course_service:site_url',
            'timezone' => 'privacy:metadata:datacurso_course_service:timezone',
        ], 'privacy:metadata:datacurso_course_service');

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

        // Course contexts where the user has course-scoped personal data.
        $params = ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid];
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_coursegen_course_sessions} s
                    ON s.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
              WHERE s.userid = :userid",
            $params
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_coursegen_module_jobs} j
                    ON j.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
              WHERE j.userid = :userid",
            $params
        );

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            if (self::user_has_coursegen_data($context->instanceid)) {
                $userlist->add_user($context->instanceid);
            }
            return;
        }

        if ($context instanceof \context_course) {
            $params = ['courseid' => $context->instanceid];
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {local_coursegen_course_sessions} WHERE courseid = :courseid',
                $params
            );
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {local_coursegen_module_jobs} WHERE courseid = :courseid',
                $params
            );
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int)$context->instanceid === (int)$user->id) {
                self::export_user_context_data($user);
            } else if ($context instanceof \context_course) {
                self::export_course_context_data($context, $user);
            }
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
        } else if ($context->contextlevel == CONTEXT_COURSE) {
            self::delete_course_data((int)$context->instanceid);
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
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER) {
                self::delete_user_data($context->instanceid);
            } else if ($context->contextlevel == CONTEXT_COURSE) {
                self::delete_course_data((int)$context->instanceid, $userid);
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
        } else if ($context instanceof \context_course) {
            foreach ($userlist->get_userids() as $userid) {
                self::delete_course_data((int)$context->instanceid, (int)$userid);
            }
        }
    }

    /**
     * Export all plugin data of a user under their user context.
     *
     * @param stdClass $user The user being exported.
     */
    protected static function export_user_context_data(stdClass $user) {
        global $DB;

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

        // Export the syllabus files stored for the user's planning sessions.
        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $sessionids = $DB->get_fieldset_select('local_coursegen_course_sessions', 'id', 'userid = ?', [$user->id]);
        foreach ($sessionids as $sessionid) {
            $files = $fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', (int)$sessionid, 'id', false);
            foreach ($files as $file) {
                writer::with_context($context)->export_file([
                    get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
                    get_string('privacy:metadata:local_coursegen_course_sessions', 'local_coursegen'),
                ], $file);
            }
        }
    }

    /**
     * Export the course-scoped rows of a user under the course context.
     *
     * @param \context_course $context Course context.
     * @param stdClass $user The user being exported.
     */
    protected static function export_course_context_data(\context_course $context, stdClass $user) {
        global $DB;

        $tables = [
            'local_coursegen_course_sessions',
            'local_coursegen_module_jobs',
        ];

        foreach ($tables as $table) {
            $records = $DB->get_recordset($table, ['courseid' => $context->instanceid, 'userid' => $user->id]);
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
     * Personal rows (sessions and their syllabus files, jobs) are deleted;
     * shared configuration rows (system instructions and course context) are
     * kept and anonymized instead, so other users keep working setups.
     *
     * @param int $userid The user ID
     */
    private static function delete_user_data(int $userid) {
        global $DB;

        $sessionids = $DB->get_fieldset_select('local_coursegen_course_sessions', 'id', 'userid = ?', [$userid]);
        self::delete_syllabus_files($sessionids);
        $DB->delete_records('local_coursegen_course_sessions', ['userid' => $userid]);
        $DB->delete_records('local_coursegen_module_jobs', ['userid' => $userid]);

        // Anonymize the shared configuration instead of destroying it.
        $DB->set_field('local_coursegen_system_instruction', 'usermodified', 0, ['usermodified' => $userid]);
        $DB->set_field('local_coursegen_course_context', 'usermodified', 0, ['usermodified' => $userid]);
    }

    /**
     * Delete the course-scoped plugin data of a course, optionally for one user only.
     *
     * @param int $courseid Course id of the course context.
     * @param int|null $userid Restrict the deletion to this user, or null for every user.
     */
    private static function delete_course_data(int $courseid, ?int $userid = null) {
        global $DB;

        $sessionfilter = ['courseid' => $courseid];
        $jobfilter = ['courseid' => $courseid];
        $contextfilter = ['courseid' => $courseid];
        if ($userid !== null) {
            $sessionfilter['userid'] = $userid;
            $jobfilter['userid'] = $userid;
            $contextfilter['usermodified'] = $userid;
        }

        $sessionids = array_keys($DB->get_records('local_coursegen_course_sessions', $sessionfilter, '', 'id'));
        self::delete_syllabus_files($sessionids);
        $DB->delete_records('local_coursegen_course_sessions', $sessionfilter);
        $DB->delete_records('local_coursegen_module_jobs', $jobfilter);

        // The course context configuration is shared: anonymize it.
        $DB->set_field('local_coursegen_course_context', 'usermodified', 0, $contextfilter);
    }

    /**
     * Delete the stored syllabus files of the given planning sessions.
     *
     * @param array $sessionids Session record ids (file item ids).
     */
    private static function delete_syllabus_files(array $sessionids) {
        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        foreach ($sessionids as $sessionid) {
            $fs->delete_area_files($syscontextid, 'local_coursegen', 'syllabus', (int)$sessionid);
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
