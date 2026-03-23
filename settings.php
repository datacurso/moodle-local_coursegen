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
    $pluginname = 'local_coursegen';
    $admincategory = new admin_category($pluginname, get_string('pluginname', $pluginname));
    $ADMIN->add('localplugins', $admincategory);
    $settings = new admin_settingpage('local_coursegen_settings', get_string('generalsettings', 'local_coursegen'));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/datacurso_service_url',
        get_string('datacurso_service_url', 'local_coursegen'),
        get_string('datacurso_service_url_desc', 'local_coursegen'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/datacurso_service_url_eu',
        get_string('datacurso_service_url_eu', 'local_coursegen'),
        get_string('datacurso_service_url_eu_desc', 'local_coursegen'),
        '',
        PARAM_URL
    ));

    $ADMIN->add($pluginname, $settings);
    // Add Manage system instructions page.
    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_manage_system_instructions',
        get_string('managesysteminstructions', 'local_coursegen'),
        new moodle_url('/local/coursegen/manage_system_instructions.php')
    ));

    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_edit_system_instruction',
        get_string('editsysteminstruction', 'local_coursegen'),
        new moodle_url('/local/coursegen/edit_system_instruction.php'),
        'moodle/site:config',
        true
    ));

    $ADMIN->add($pluginname, new admin_externalpage(
        'local_coursegen_manage_image_generation',
        get_string('manage_image_generation', 'local_coursegen'),
        new moodle_url('/local/coursegen/manage_image_generation.php'),
        'moodle/site:config'
    ));
}
