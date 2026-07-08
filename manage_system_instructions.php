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
 * Manage system instructions page for DataCurso plugin.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursegen\local\models\system_instruction;
use local_coursegen\local\service\system_instruction_service;

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_coursegen_manage_system_instructions');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$context = context_system::instance();
require_capability('local/coursegen:managesysteminstructions', $context);

$PAGE->set_url('/local/coursegen/manage_system_instructions.php');
$PAGE->set_title(get_string('managesysteminstructions', 'local_coursegen'));
$PAGE->set_heading(get_string('managesysteminstructions', 'local_coursegen'));

// Handle delete action.
if ($action === 'delete' && $id > 0) {
    if ($confirm && confirm_sesskey()) {
        // Soft delete the system instruction.
        $model = new system_instruction($id);
        $model->set('deleted', 1);
        $model->update();
        redirect(
            $PAGE->url,
            get_string('systeminstructiondeleted', 'local_coursegen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        // Show confirmation dialog.
        $model = system_instruction::get_record(['id' => $id, 'deleted' => 0]);

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('deletesysteminstruction', 'local_coursegen'));

        $confirmurl = new moodle_url(
            $PAGE->url,
            ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]
        );
        $cancelurl = $PAGE->url;

        echo $OUTPUT->confirm(
            get_string('confirmdeletesysteminstruction', 'local_coursegen') .
                '<br><strong>' . format_string($model->get('name')) . '</strong>',
            $confirmurl,
            $cancelurl
        );

        echo $OUTPUT->footer();
        exit;
    }
}

echo $OUTPUT->header();

// Add system instruction button.
$addurl = new moodle_url('/local/coursegen/edit_system_instruction.php');
echo html_writer::div(
    $OUTPUT->single_button($addurl, get_string('addsysteminstruction', 'local_coursegen'), 'get'),
    'mb-3'
);

// Create table to display system instructions.
$table = new html_table();
$table->head = [
    get_string('systeminstructionname', 'local_coursegen'),
    get_string('systeminstructioncreated', 'local_coursegen'),
    get_string('systeminstructionmodified', 'local_coursegen'),
    get_string('actions', 'local_coursegen'),
];
$table->attributes['class'] = 'table table-striped';

// Get system instructions from service (handles filtering and ordering).
$instructions = system_instruction_service::get_all();

if (empty($instructions)) {
    echo html_writer::div(
        html_writer::tag('p', get_string('nosysteminstructions', 'local_coursegen'), ['class' => 'alert alert-info']),
        'mt-3'
    );
} else {
    foreach ($instructions as $instruction) {
        $editurl = new moodle_url('/local/coursegen/edit_system_instruction.php', ['id' => $instruction->get('id')]);
        $deleteurl = new moodle_url($PAGE->url, ['action' => 'delete', 'id' => $instruction->get('id')]);

        $editicon = $OUTPUT->pix_icon('t/edit', get_string('edit', 'local_coursegen'));
        $deleteicon = $OUTPUT->pix_icon('t/delete', get_string('delete', 'local_coursegen'));

        $actions = html_writer::link($editurl, $editicon, ['title' => get_string('edit', 'local_coursegen')]);
        $actions .= html_writer::link($deleteurl, $deleteicon, ['title' => get_string('delete', 'local_coursegen')]);

        $table->data[] = [
            format_string($instruction->get('name')),
            userdate($instruction->get('timecreated')),
            userdate($instruction->get('timemodified')),
            $actions,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
