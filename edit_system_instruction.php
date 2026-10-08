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
 * Add or edit a system instruction of a tenant (Moodle Workplace).
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursegen\form\system_instruction_form;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\tenancy;
use local_coursegen\output\tenant_scope_notice;

require_once('../../config.php');

$id = optional_param('id', 0, PARAM_INT);

require_login(null, false);
\local_coursegen\admin\external_page::setup('local_coursegen_edit_system_instruction');
require_capability('local/coursegen:managesysteminstructions', context_system::instance());

// The instruction always belongs to the tenant the user is currently in.
$tenantid = tenancy::get_tenant_id();

$manageurl = new moodle_url('/local/coursegen/manage_system_instructions.php');
$pageurl = new moodle_url('/local/coursegen/edit_system_instruction.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->navigation->override_active_url($manageurl);

/** @var \local_coursegen\local\models\system_instruction|null $instruction */
$instruction = null;
if ($id > 0) {
    // Only instructions owned by the current tenant can be edited.
    $instruction = system_instruction_service::require_owned($id, $tenantid);
    $PAGE->set_title(get_string('editsysteminstruction', 'local_coursegen'));
    $PAGE->set_heading(get_string('editsysteminstruction', 'local_coursegen'));
    $PAGE->navbar->add(get_string('editsysteminstruction', 'local_coursegen'));
} else {
    $PAGE->set_title(get_string('addsysteminstruction', 'local_coursegen'));
    $PAGE->set_heading(get_string('addsysteminstruction', 'local_coursegen'));
    $PAGE->navbar->add(get_string('addsysteminstruction', 'local_coursegen'));
}

$form = new system_instruction_form($pageurl->out(false), ['tenantid' => $tenantid]);

if ($instruction) {
    $form->set_data((object) [
        'id' => $instruction->get('id'),
        'name' => $instruction->get('name'),
        'content_editor' => [
            'text' => $instruction->get('content'),
            'format' => FORMAT_HTML,
        ],
    ]);
}

if ($form->is_cancelled()) {
    redirect($manageurl);
}

if ($data = $form->get_data()) {
    $payload = [
        'name' => trim($data->name),
        'content' => $data->content_editor['text'],
    ];

    if (!empty($data->id)) {
        system_instruction_service::update((int) $data->id, $payload, $tenantid);
    } else {
        system_instruction_service::create($payload, $tenantid);
    }

    redirect(
        $manageurl,
        get_string('systeminstructionsaved', 'local_coursegen'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->render(new tenant_scope_notice());
$form->display();
echo $OUTPUT->footer();
