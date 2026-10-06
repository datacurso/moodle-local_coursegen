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

$pluginname = 'local_coursegen';

// The "Create a new course with AI" page is also used by Workplace tenant
// administrators, who have no site configuration rights: it is registered outside the
// $hassiteconfig block with its own access check, which accepts system-level
// and category-level course creators alike (see \local_coursegen\local\permission).
// The "Restore course" sibling only exists for users holding moodle/site:config.
$ADMIN->add('courses', new \tool_wp\admin_externalpage(
    'local_coursegen_addnewcourseai',
    get_string('courseai_admin_addnewcourse', 'local_coursegen'),
    (new moodle_url('/local/coursegen/aicoursecreation.php'))->out(false),
    static fn(): bool => \local_coursegen\local\permission::can_create_course_with_ai()
), $ADMIN->locate('restorecourse') ? 'restorecourse' : null);

// The plugin category also hosts pages meant for tenant administrators, so it is
// created for every admin tree user (an admin category hides itself when none of
// its pages is accessible). The "Local plugins" category only exists for users
// holding moodle/site:config; for anyone else the category hangs from "Courses".
$admincategory = new admin_category($pluginname, get_string('pluginname', $pluginname));
$ADMIN->add($ADMIN->locate('localplugins') ? 'localplugins' : 'courses', $admincategory);

// Every configuration page below applies to the tenant the user is currently in
// (tenant administrators: their own tenant; site administrators: the tenant they
// switched to). They are registered outside the $hassiteconfig block so tenant
// administrators reach them, each gated by its own plugin capability.

// General settings, stored for the current tenant.
$settings = new admin_settingpage(
    'local_coursegen_settings',
    get_string('generalsettings', 'local_coursegen'),
    'local/coursegen:managetenantsettings'
);

$settings->add(new \local_coursegen\admin\setting_tenant_scope_notice('local_coursegen/generaltenantscopenotice'));

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
// environments, kept apart from the functional settings. Also per tenant.
$devsettings = new admin_settingpage(
    'local_coursegen_devsettings',
    get_string('devsettings', 'local_coursegen'),
    'local/coursegen:managetenantsettings'
);

$devsettings->add(new \local_coursegen\admin\setting_tenant_scope_notice('local_coursegen/devtenantscopenotice'));

$devsettings->add(new admin_setting_heading(
    'local_coursegen/devsettingsheading',
    '',
    get_string('devsettings_desc', 'local_coursegen')
));

// HTTPS is enforced: the overrides carry prompts and syllabus files, so
// plain HTTP is only accepted for localhost under developer debugging.
$devsettings->add(new \local_coursegen\admin\setting_https_url(
    'local_coursegen/datacurso_service_url',
    get_string('datacurso_service_url', 'local_coursegen'),
    get_string('datacurso_service_url_desc', 'local_coursegen'),
    '',
    PARAM_URL
));

$devsettings->add(new \local_coursegen\admin\setting_https_url(
    'local_coursegen/datacurso_service_url_eu',
    get_string('datacurso_service_url_eu', 'local_coursegen'),
    get_string('datacurso_service_url_eu_desc', 'local_coursegen'),
    '',
    PARAM_URL
));

$ADMIN->add($pluginname, $devsettings);

// Image generation policy of the current tenant.
$ADMIN->add($pluginname, new \tool_wp\admin_externalpage(
    'local_coursegen_manage_image_generation',
    get_string('manage_image_generation', 'local_coursegen'),
    (new moodle_url('/local/coursegen/manage_image_generation.php'))->out(false),
    static fn(): bool => has_capability('local/coursegen:manageimagegeneration', context_system::instance())
));

// System instructions of the current tenant.
$ADMIN->add($pluginname, new \tool_wp\admin_externalpage(
    'local_coursegen_manage_system_instructions',
    get_string('managesysteminstructions', 'local_coursegen'),
    (new moodle_url('/local/coursegen/manage_system_instructions.php'))->out(false),
    static fn(): bool => has_capability('local/coursegen:managesysteminstructions', context_system::instance())
));

$ADMIN->add($pluginname, new \tool_wp\admin_externalpage(
    'local_coursegen_edit_system_instruction',
    get_string('editsysteminstruction', 'local_coursegen'),
    (new moodle_url('/local/coursegen/edit_system_instruction.php'))->out(false),
    static fn(): bool => has_capability('local/coursegen:managesysteminstructions', context_system::instance()),
    true
));
