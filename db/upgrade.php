<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package     local_coursegen
 * @category    upgrade
 * @copyright   2025 Josue Condori <https://datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_coursegen upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_coursegen_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();
    // Add local_coursegen_model table.
    if ($oldversion < 2025091701) {
        // Define table local_coursegen_model to be created.
        $table = new xmldb_table('local_coursegen_model');

        // Adding fields to table local_coursegen_model.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('deleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_coursegen_model.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for local_coursegen_model.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Datacurso savepoint reached.
        upgrade_plugin_savepoint(true, 2025091701, 'local', 'coursegen');
    }

    // Add local_coursegen_course_context table.
    if ($oldversion < 2025091704) {
        // Define table local_coursegen_course_context to be created.
        $table = new xmldb_table('local_coursegen_course_context');

        // Adding fields to table local_coursegen_course_context.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('context_type', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('model_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_coursegen_course_context.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('model_id', XMLDB_KEY_FOREIGN, ['model_id'], 'local_coursegen_model', ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for local_coursegen_course_context.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025091704, 'local', 'coursegen');
    }

    if ($oldversion < 2025092001) {
        // Define table local_coursegen_course_sessions to be created.
        $table = new xmldb_table('local_coursegen_course_sessions');

        // Adding fields to table local_coursegen_course_sessions.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('session_id', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_coursegen_course_sessions.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Conditionally launch create table for local_coursegen_course_sessions.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025092001, 'local', 'coursegen');
    }

    // Add local_coursegen_module_jobs table for AI module generation tracking.
    if ($oldversion < 2025092401) {
        // Define table local_coursegen_module_jobs to be created.
        $table = new xmldb_table('local_coursegen_module_jobs');

        // Adding fields to table local_coursegen_module_jobs.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('job_id', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('generate_images', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('context_type', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('model_name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('sectionnum', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('beforemod', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_coursegen_module_jobs.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Conditionally launch create table for local_coursegen_module_jobs.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025092401, 'local', 'coursegen');
    }

    if ($oldversion < 2025112801) {
        $table = new xmldb_table('local_coursegen_course_context');

        $prompttext = new xmldb_field('prompt_text', XMLDB_TYPE_TEXT, null, null, null, null, null, 'model_id');
        if (!$dbman->field_exists($table, $prompttext)) {
            $dbman->add_field($table, $prompttext);
        }

        upgrade_plugin_savepoint(true, 2025112801, 'local', 'coursegen');
    }

    if ($oldversion < 2025120300) {
        // Define table local_coursegen_model to be renamed to local_coursegen_system_instruction.
        $table = new xmldb_table('local_coursegen_model');

        // Launch rename table for local_coursegen_system_instruction.
        $dbman->rename_table($table, 'local_coursegen_system_instruction');

        // Rename field model_id on table local_coursegen_course_context to system_instruction_id.
        $table = new xmldb_table('local_coursegen_course_context');
        $field = new xmldb_field('model_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'context_type');

        // Launch rename field system_instruction_id.
        $dbman->rename_field($table, $field, 'system_instruction_id');

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025120300, 'local', 'coursegen');
    }

    if ($oldversion < 2025120302) {
        // Define key model_id (foreign) to be dropped form local_coursegen_course_context.
        $table = new xmldb_table('local_coursegen_course_context');
        $key = new xmldb_key('model_id', XMLDB_KEY_FOREIGN, ['model_id'], 'local_coursegen_system_instruction', ['id']);

        // Launch drop key model_id.
        $dbman->drop_key($table, $key);

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025120302, 'local', 'coursegen');
    }

    if ($oldversion < 2025120303) {
        // Define key system_instruction_id (foreign) to be added to local_coursegen_course_context.
        $table = new xmldb_table('local_coursegen_course_context');
        $key = new xmldb_key(
            'system_instruction_id',
            XMLDB_KEY_FOREIGN,
            ['system_instruction_id'],
            'local_coursegen_system_instruction',
            ['id']
        );

        // Launch add key system_instruction_id.
        $dbman->add_key($table, $key);

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025120303, 'local', 'coursegen');
    }

    if ($oldversion < 2025121601) {
        // Define field lang to be added to local_coursegen_course_context.
        $table = new xmldb_table('local_coursegen_course_context');
        $field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'system_instruction_id');

        // Conditionally launch add field lang.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2025121601, 'local', 'coursegen');
    }

    if ($oldversion < 2026021801) {
        // Changing nullability of field courseid on table local_coursegen_course_sessions to null.
        $table = new xmldb_table('local_coursegen_course_sessions');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');

        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Launch change of nullability for field courseid.
        $dbman->change_field_notnull($table, $field);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2026021801, 'local', 'coursegen');
    }

    if ($oldversion < 2026021803) {
        // Define field coursedata to be added to local_coursegen_course_sessions.
        $table = new xmldb_table('local_coursegen_course_sessions');
        $field = new xmldb_field('coursedata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'courseid');

        // Conditionally launch add field coursedata.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Coursegen savepoint reached.
        upgrade_plugin_savepoint(true, 2026021803, 'local', 'coursegen');
    }

    if ($oldversion < 2026072002) {
        // Define table local_coursegen_template.
        $table = new xmldb_table('local_coursegen_template');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('maxsections', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('nolimit', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('allowedtypes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('namingpattern', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('namingstart', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_coursegen_tpl_section.
        $table = new xmldb_table('local_coursegen_tpl_section');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sectionnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('behavior', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'custom');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('templateid', XMLDB_KEY_FOREIGN, ['templateid'], 'local_coursegen_template', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_coursegen_tpl_activity.
        $table = new xmldb_table('local_coursegen_tpl_activity');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'modify');
        $table->add_field('useasreference', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('templateid', XMLDB_KEY_FOREIGN, ['templateid'], 'local_coursegen_template', ['id']);
        $table->add_key('cmid', XMLDB_KEY_FOREIGN, ['cmid'], 'course_modules', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072002, 'local', 'coursegen');
    }

    return true;
}
