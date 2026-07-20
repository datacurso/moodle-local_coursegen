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
 * Plugin administration pages are defined here.
 *
 * @package     local_coursegen
 * @category    admin
 * @copyright   2025 Josue Condori <https://datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('courses', new admin_externalpage(
        'local_coursegen_addnewcourseai',
        get_string('courseai_admin_addnewcourse', 'local_coursegen'),
        new moodle_url('/local/coursegen/aicoursecreation.php'),
        'local/coursegen:createcoursewithai'
    ), 'restorecourse');

    $pluginname = 'local_coursegen';
    $admincategory = new admin_category($pluginname, get_string('pluginname', $pluginname));
    $ADMIN->add('localplugins', $admincategory);
    $settings = new admin_settingpage('local_coursegen_settings', get_string('generalsettings', 'local_coursegen'));

    // Custom checkbox: rejects enabling the feature while mod_subsection is
    // disabled, so generated subsections can never silently flatten.
    $settings->add(new \local_coursegen\admin\setting_enablesubsections(
        'local_coursegen/enablesubsections',
        get_string('enablesubsections', 'local_coursegen'),
        get_string('enablesubsections_desc', 'local_coursegen'),
        0
    ));

    $ADMIN->add($pluginname, $settings);

    // Development settings page: service URL overrides for dev/staging
    // environments, kept apart from the functional settings.
    $devsettings = new admin_settingpage(
        'local_coursegen_devsettings',
        get_string('devsettings', 'local_coursegen')
    );

    $devsettings->add(new admin_setting_heading(
        'local_coursegen/devsettingsheading',
        '',
        get_string('devsettings_desc', 'local_coursegen')
    ));

    $devsettings->add(new admin_setting_configtext(
        'local_coursegen/datacurso_service_url',
        get_string('datacurso_service_url', 'local_coursegen'),
        get_string('datacurso_service_url_desc', 'local_coursegen'),
        '',
        PARAM_URL
    ));

    $devsettings->add(new admin_setting_configtext(
        'local_coursegen/datacurso_service_url_eu',
        get_string('datacurso_service_url_eu', 'local_coursegen'),
        get_string('datacurso_service_url_eu_desc', 'local_coursegen'),
        '',
        PARAM_URL
    ));

    $ADMIN->add($pluginname, $devsettings);
    // Add Manage system instructions page.
    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_manage_system_instructions',
        get_string('managesysteminstructions', 'local_coursegen'),
        new moodle_url('/local/coursegen/manage_system_instructions.php')
    ));

    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_manage_image_generation',
        get_string('manage_image_generation', 'local_coursegen'),
        new moodle_url('/local/coursegen/manage_image_generation.php'),
        'local/coursegen:manageimagegeneration'
    ));

    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_manage_templates',
        get_string('managetemplates', 'local_coursegen'),
        new moodle_url('/local/coursegen/manage_templates.php'),
        'local/coursegen:managetemplates'
    ));

    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_edit_template',
        get_string('managetemplates', 'local_coursegen'),
        new moodle_url('/local/coursegen/edit_template.php'),
        'local/coursegen:managetemplates',
        true
    ));

    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_edit_system_instruction',
        get_string('editsysteminstruction', 'local_coursegen'),
        new moodle_url('/local/coursegen/edit_system_instruction.php'),
        'moodle/site:config',
        true
    ));
}
