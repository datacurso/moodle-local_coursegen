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
 * Manage the system instructions of a tenant (Moodle Workplace).
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\tenancy;
use local_coursegen\output\system_instruction_list;
use local_coursegen\output\tenant_scope_notice;

require_once('../../config.php');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

require_login(null, false);
\tool_wp\admin_externalpage::setup_page('local_coursegen_manage_system_instructions');
require_capability('local/coursegen:managesysteminstructions', context_system::instance());

// The instructions always belong to the tenant the user is currently in.
$tenantid = tenancy::get_tenant_id();

$pageurl = new moodle_url('/local/coursegen/manage_system_instructions.php');
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('managesysteminstructions', 'local_coursegen'));
$PAGE->set_heading(get_string('managesysteminstructions', 'local_coursegen'));

// Handle delete action: only instructions owned by the current tenant can be deleted.
if ($action === 'delete' && $id > 0) {
    $instruction = system_instruction_service::require_owned($id, $tenantid);

    if ($confirm) {
        require_sesskey();
        system_instruction_service::delete($id, $tenantid);
        redirect(
            $pageurl,
            get_string('systeminstructiondeleted', 'local_coursegen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $confirmurl = new moodle_url($pageurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deletesysteminstruction', 'local_coursegen'));
    echo $OUTPUT->confirm(
        get_string('confirmdeletesysteminstructionnamed', 'local_coursegen', format_string($instruction->get('name'))),
        $confirmurl,
        $pageurl
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->render(new tenant_scope_notice(get_string('managesysteminstructions_desc', 'local_coursegen')));
echo $OUTPUT->render(new system_instruction_list(system_instruction_service::get_available($tenantid)));
echo $OUTPUT->footer();
