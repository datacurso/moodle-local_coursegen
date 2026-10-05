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
 * The list of templates: create, edit and delete them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_coursegen\output\manage_templates_page;

admin_externalpage_setup('local_coursegen_manage_templates');

if (optional_param('saved', 0, PARAM_BOOL)) {
    \core\notification::success(get_string('template_saved', 'local_coursegen'));
}

$page = new manage_templates_page();
$data = $page->export_for_template($OUTPUT);

$PAGE->requires->js_call_amd('local_coursegen/template/manage', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/manage_templates', $data);
echo $OUTPUT->footer();
