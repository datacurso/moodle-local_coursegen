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
 * Export a template's configuration, or import it over a restored copy of its course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursegen\form\template_transfer_import_form;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_config_transfer;

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_coursegen_transfer_template');

$context = context_system::instance();
require_capability('local/coursegen:edittemplates', $context);

$PAGE->set_url('/local/coursegen/transfer_template.php');
$PAGE->set_title(get_string('template_transfer', 'local_coursegen'));
$PAGE->set_heading(get_string('template_transfer', 'local_coursegen'));

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'export') {
    require_sesskey();
    $templateid = required_param('id', PARAM_INT);
    $tpl = new template($templateid);
    $data = template_config_transfer::export($templateid);
    $filename = clean_filename('template-' . $tpl->get('name') . '.json');
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    send_file(json_encode($data, $flags), $filename, 0, 0, true, true, 'application/json');
}

$form = new template_transfer_import_form($PAGE->url);
$notification = '';
if ($formdata = $form->get_data()) {
    try {
        $data = template_config_transfer::parse((string) $form->get_file_content('configfile'));
        $result = template_config_transfer::import((int) $formdata->courseid, $data, trim((string) $formdata->templatename));
        $notification = local_coursegen_transfer_result($result, (int) $formdata->courseid);
    } catch (moodle_exception $error) {
        $notification = $OUTPUT->notification($error->getMessage(), \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $notification;

echo $OUTPUT->heading(get_string('template_transfer_export', 'local_coursegen'), 3);
echo html_writer::tag('p', get_string('template_transfer_export_help', 'local_coursegen'));
$items = [];
foreach (template::get_records([], 'name') as $tpl) {
    $url = new moodle_url($PAGE->url, ['action' => 'export', 'id' => $tpl->get('id'), 'sesskey' => sesskey()]);
    $courselabel = get_string('template_transfer_course_id', 'local_coursegen', $tpl->get('courseid'));
    $items[] = html_writer::link($url, format_string($tpl->get('name'))) . ' ' .
        html_writer::span('(' . $courselabel . ')', 'text-muted');
}
echo html_writer::alist($items);

echo $OUTPUT->heading(get_string('template_transfer_import', 'local_coursegen'), 3);
echo html_writer::tag('p', get_string('template_transfer_import_help', 'local_coursegen'));
$form->display();

echo $OUTPUT->footer();

/**
 * The message that tells what an import did.
 *
 * @param array $result What template_config_transfer::import() returned.
 * @param int $courseid
 * @return string
 */
function local_coursegen_transfer_result(array $result, int $courseid): string {
    global $OUTPUT;

    if ($result['ok']) {
        $a = (object) [
            'name' => s($result['name']),
            'course' => format_string(get_course($courseid)->fullname),
        ];
        $message = get_string('template_transfer_done', 'local_coursegen', $a);
        $edit = new moodle_url('/local/coursegen/edit_template.php', ['id' => $result['templateid']]);
        $message .= ' ' . html_writer::link($edit, get_string('template_transfer_open', 'local_coursegen'));
        return $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
    }
    $lines = [];
    foreach ($result['problems'] as $problem) {
        if ($problem['type'] === 'section') {
            $lines[] = get_string('template_transfer_missing_section', 'local_coursegen', $problem['section']);
        } else {
            $lines[] = get_string('template_transfer_missing_activity', 'local_coursegen', (object) [
                'modname' => s($problem['modname']),
                'name' => s($problem['name']),
                'position' => $problem['position'],
                'section' => $problem['section'],
            ]);
        }
    }
    $message = get_string('template_transfer_mismatch', 'local_coursegen') . html_writer::alist($lines);
    return $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
}
