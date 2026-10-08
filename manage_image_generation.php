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
 * Manage image generation settings page.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use local_coursegen\local\image_generation\activities;
use local_coursegen\local\image_generation\image_settings;
use local_coursegen\local\tenancy;
use local_coursegen\output\tenant_scope_notice;

require_login(null, false);
\local_coursegen\admin\external_page::setup('local_coursegen_manage_image_generation');
require_capability('local/coursegen:manageimagegeneration', context_system::instance());

// The settings always belong to the tenant the user is currently in.
$tenantid = tenancy::get_tenant_id();

$PAGE->set_url(new moodle_url('/local/coursegen/manage_image_generation.php'));

// Show a standard success notification if coming back from a save redirect.
if (optional_param('saved', 0, PARAM_BOOL)) {
    \core\notification::success(get_string('changessaved'));
}

$settings = image_settings::get_settings($tenantid);
$currentmode = $settings[image_settings::KEY_MODE];

$activitiescontext = [];
foreach (activities::get_definitions() as $definition) {
    $id = $definition['id'];
    $enabled = $settings[$definition['configenable']] === 1;

    // Use the standard module monologo icon, similar to admin activity table.
    $iconurl = $OUTPUT->image_url('monologo', 'mod_' . $id)->out(false);

    $partcontexts = [];
    foreach ($definition['parts'] ?? [] as $partdefinition) {
        $partid = $partdefinition['id'];
        $partconfigmaximages = $partdefinition['configmaximages'] ?? null;

        $partcontexts[] = [
            'id' => $partid,
            'partuniqueid' => $id . '_' . $partid,
            'label' => $partdefinition['stringlabel'],
            'enabled' => $settings[$partdefinition['configenable']] === 1,
            'maximages' => $partconfigmaximages !== null ? $settings[$partconfigmaximages] : 1,
            'partmaximageshelp' => $OUTPUT->render(
                new \core\output\help_icon('help_maximages_' . $id . '_' . $partid, 'local_coursegen')
            ),
        ];
    }

    $activitiescontext[] = [
        'id' => $id,
        'iconurl' => $iconurl,
        'name' => $definition['stringactivity'],
        'tooltip' => $definition['stringtooltip'],
        'enabled' => $enabled,
        'show' => $enabled,
        'parts' => $partcontexts,
    ];
}

$context = [
    'overridecourse'   => $settings[image_settings::KEY_OVERRIDE_COURSE] === 1,
    'overrideactivity' => $settings[image_settings::KEY_OVERRIDE_ACTIVITY] === 1,

    'ismoddisabled' => ($currentmode === activities::MODE_DISABLED),
    'ismodeauto'    => ($currentmode === activities::MODE_AUTO),
    'ismodemanual'  => ($currentmode === activities::MODE_MANUAL),
    'currentmode'   => $currentmode,

    'activities' => $activitiescontext,
];

echo $OUTPUT->header();
echo $OUTPUT->render(new tenant_scope_notice());
echo $OUTPUT->render_from_template('local_coursegen/manage_image_generation', $context);
echo $OUTPUT->footer();
