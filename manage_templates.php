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
 * Manage course templates page.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;
use local_coursegen\reportbuilder\local\systemreports\templates;
use core_reportbuilder\system_report_factory;

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_coursegen_manage_templates');

$context = context_system::instance();
require_capability('local/coursegen:managetemplates', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$PAGE->set_url('/local/coursegen/manage_templates.php');
$PAGE->set_title(get_string('managetemplates', 'local_coursegen'));
$PAGE->set_heading(get_string('managetemplates', 'local_coursegen'));

// Handle delete action.
if ($action === 'delete' && $id > 0) {
    if ($confirm && confirm_sesskey()) {
        $activities = template_activity::get_records(['templateid' => $id]);
        foreach ($activities as $a) {
            $a->delete();
        }
        $sections = template_section::get_records(['templateid' => $id]);
        foreach ($sections as $s) {
            $s->delete();
        }
        $tpl = new template($id);
        $tpl->delete();
        redirect(
            $PAGE->url,
            get_string('template_deleted', 'local_coursegen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $tpl = new template($id);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('template_confirm_delete', 'local_coursegen') .
                '<br><strong>' . format_string($tpl->get('name')) . '</strong>',
            new moodle_url($PAGE->url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
            $PAGE->url
        );
        echo $OUTPUT->footer();
        exit;
    }
}

echo $OUTPUT->header();

// Create template button.
$addurl = new moodle_url('/local/coursegen/edit_template.php');
echo html_writer::div(
    $OUTPUT->single_button($addurl, get_string('template_create', 'local_coursegen'), 'get'),
    'mb-3'
);

// System report.
$report = system_report_factory::create(templates::class, $context);
echo $report->output();

echo $OUTPUT->footer();
