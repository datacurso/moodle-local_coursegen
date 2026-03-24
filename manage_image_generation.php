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

$currentmode = get_config('local_coursegen', 'generationmode') ?: activities::MODE_AUTO;

$activitydefinitions = activities::get_definitions();
$activitiescontext = [];

foreach ($activitydefinitions as $definition) {
    $id = $definition['id'];
    $configenable = $definition['configenable'];
    $configprompt = $definition['configprompt'];

    $enabled = (int) get_config('local_coursegen', $configenable) === 1;
    $prompt = (string) get_config('local_coursegen', $configprompt);
    if ($prompt === '') {
        $prompt = $definition['defaultprompt'];
    }

    $activitiescontext[] = [
        'id' => $id,
        'iconclass' => $definition['iconclass'],
        'name' => get_string($definition['stringactivity'], 'local_coursegen'),
        'tooltip' => get_string($definition['stringtooltip'], 'local_coursegen'),
        'promptlabel' => get_string($definition['stringpromptlabel'], 'local_coursegen'),
        'enabled' => $enabled,
        'show' => $enabled,
        'prompt' => $prompt,
    ];
}

$context = [
    'overridecourse'   => (bool) get_config('local_coursegen', 'overridecourse'),
    'overrideactivity' => (bool) get_config('local_coursegen', 'overrideactivity'),

    'ismoddisabled' => ($currentmode === activities::MODE_DISABLED),
    'ismodeauto'    => ($currentmode === activities::MODE_AUTO),
    'ismodemanual'  => ($currentmode === activities::MODE_MANUAL),
    'currentmode'   => $currentmode,

    'activities' => $activitiescontext,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/manage_image_generation', $context);
echo $OUTPUT->footer();
