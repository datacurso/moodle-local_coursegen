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


defined('MOODLE_INTERNAL') || die();

/**
 * Create every template table that is missing, in the order their keys need.
 *
 * A site that was already past the 2026072002 step when the template tables arrived never ran that step, so
 * the steps after it must be able to count on the tables. Existing tables are left as they are.
 *
 * @param database_manager $dbman Database manager.
 */
function local_coursegen_ensure_template_tables(database_manager $dbman): void {
    local_coursegen_ensure_template_table($dbman);
    local_coursegen_ensure_tpl_section_table($dbman);
    local_coursegen_ensure_tpl_activity_table($dbman);
    local_coursegen_ensure_tpl_instance_table($dbman);
}

/**
 * Create the table local_coursegen_template when it does not exist, with the shape the 2026072002 step gave it.
 *
 * @param database_manager $dbman Database manager.
 */
function local_coursegen_ensure_template_table(database_manager $dbman): void {
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
}

/**
 * Create the table local_coursegen_tpl_section when it does not exist, with the shape the 2026072002 step gave it.
 *
 * @param database_manager $dbman Database manager.
 */
function local_coursegen_ensure_tpl_section_table(database_manager $dbman): void {
    $table = new xmldb_table('local_coursegen_tpl_section');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('sectionnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('behavior', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'aimodify');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_key('templateid', XMLDB_KEY_FOREIGN, ['templateid'], 'local_coursegen_template', ['id']);
    if (!$dbman->table_exists($table)) {
        $dbman->create_table($table);
    }
}

/**
 * Create the table local_coursegen_tpl_activity when it does not exist, with the shape the 2026072002 step gave it.
 *
 * @param database_manager $dbman Database manager.
 */
function local_coursegen_ensure_tpl_activity_table(database_manager $dbman): void {
    $table = new xmldb_table('local_coursegen_tpl_activity');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'modify');
    $table->add_field('useasreference', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
    $table->add_field('templatescope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'course');
    $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_key('templateid', XMLDB_KEY_FOREIGN, ['templateid'], 'local_coursegen_template', ['id']);
    $table->add_key('cmid', XMLDB_KEY_FOREIGN, ['cmid'], 'course_modules', ['id']);
    if (!$dbman->table_exists($table)) {
        $dbman->create_table($table);
    }
}

/**
 * Create the table local_coursegen_tpl_instance when it does not exist, with the shape the 2026072002 step gave it.
 *
 * @param database_manager $dbman Database manager.
 */
function local_coursegen_ensure_tpl_instance_table(database_manager $dbman): void {
    $table = new xmldb_table('local_coursegen_tpl_instance');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('sourcecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('sourcename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('typelabel', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('modname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
    $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('aftercmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_key('templateid', XMLDB_KEY_FOREIGN, ['templateid'], 'local_coursegen_template', ['id']);
    $table->add_key('sourcecmid', XMLDB_KEY_FOREIGN, ['sourcecmid'], 'course_modules', ['id']);
    if (!$dbman->table_exists($table)) {
        $dbman->create_table($table);
    }
}
