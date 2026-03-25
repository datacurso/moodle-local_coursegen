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

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

use local_coursegen\local\image_generation\activities;

admin_externalpage_setup('local_coursegen_manage_image_generation');

// Show a standard success notification if coming back from a save redirect.
if (optional_param('saved', 0, PARAM_BOOL)) {
    \core\notification::success(get_string('changessaved'));
}

$currentmode = get_config('local_coursegen', 'generationmode') ?: activities::MODE_DISABLED;

$activitydefinitions = activities::get_definitions();
$activitiescontext = [];

foreach ($activitydefinitions as $definition) {
    $id = $definition['id'];
    $configenable = $definition['configenable'];

    $enabled = (int) get_config('local_coursegen', $configenable) === 1;

    // Use the standard module monologo icon, similar to admin activity table.
    $component = 'mod_' . $id;
    $iconurl = $OUTPUT->image_url('monologo', $component)->out(false);

    $partcontexts = [];
    if (!empty($definition['parts']) && is_array($definition['parts'])) {
        foreach ($definition['parts'] as $partdefinition) {
            $partid = $partdefinition['id'];
            $partconfigenable = $partdefinition['configenable'];
            $partconfigmaximages = $partdefinition['configmaximages'] ?? null;
            $partenabled = (int) get_config('local_coursegen', $partconfigenable) === 1;

            $maximages = 1;
            if ($partconfigmaximages !== null) {
                $savedmax = (int) get_config('local_coursegen', $partconfigmaximages);
                if ($savedmax >= 0) {
                    $maximages = $savedmax;
                }
            }

            $partcontexts[] = [
                'id' => $partid,
                'partuniqueid' => $id . '_' . $partid,
                'label' => $partdefinition['stringlabel'],
                'enabled' => $partenabled,
                'maximages' => $maximages,
            ];
        }
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

$partmaximageshelpicon = new \core\output\help_icon('help_part_maximages', 'local_coursegen');

$context = [
    'overridecourse'   => (bool) get_config('local_coursegen', 'overridecourse'),
    'overrideactivity' => (bool) get_config('local_coursegen', 'overrideactivity'),

    'ismoddisabled' => ($currentmode === activities::MODE_DISABLED),
    'ismodeauto'    => ($currentmode === activities::MODE_AUTO),
    'ismodemanual'  => ($currentmode === activities::MODE_MANUAL),
    'currentmode'   => $currentmode,

    'activities' => $activitiescontext,
    'partmaximageshelp' => $OUTPUT->render($partmaximageshelpicon),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/manage_image_generation', $context);
echo $OUTPUT->footer();
