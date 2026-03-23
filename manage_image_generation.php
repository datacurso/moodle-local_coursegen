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

admin_externalpage_setup('local_coursegen_manage_image_generation');

$currentmode = get_config('local_coursegen', 'generationmode') ?: 'auto';

$context = [
    'override_course'   => get_config('local_coursegen', 'overridecourse') ? 'checked' : '',
    'override_activity' => get_config('local_coursegen', 'overrideactivity') ? 'checked' : '',

    'mode_disabled' => ($currentmode === 'disabled') ? 'active-disabled' : '',
    'mode_auto'     => ($currentmode === 'auto') ? 'active-auto' : '',
    'mode_manual'   => ($currentmode === 'manual') ? 'active-manual' : '',
    'current_mode'  => $currentmode,

    'enable_book' => get_config('local_coursegen', 'enableimgbook') ? 'checked' : '',
    'book_show'   => get_config('local_coursegen', 'enableimgbook') ? 'show' : '',
    'prompt_book' => get_config('local_coursegen', 'promptimgbook') ?: 'Generate 1 header image per book chapter...',

    'enable_quiz' => get_config('local_coursegen', 'enableimgquiz') ? 'checked' : '',
    'quiz_show'   => get_config('local_coursegen', 'enableimgquiz') ? 'show' : '',
    'prompt_quiz' => get_config('local_coursegen', 'promptimgquiz') ?: 'Generate 1 illustrative image per question...',
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/manage_image_generation', $context);
echo $OUTPUT->footer();
