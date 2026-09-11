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
 * Manual test page: create a course from the unification of a real existing
 * course's structure and a real .mbz backup's content, without going through
 * the real Datacurso AI backend.
 *
 * Renders raw, without the Moodle theme: creating the activities pollutes the
 * global $PAGE state (each mod_form construction calls $PAGE->set_cm()), so
 * $OUTPUT->header()/footer() would render the last created activity's own
 * page furniture instead of this page's.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_coursegen\local\service\course_export_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;

require_login();
require_capability('moodle/site:config', context_system::instance());

// Test service started via coursegen_template/compose.yml, on the same
// external 'moodle' docker network as this Moodle site.
$nodeserviceurl = 'http://coursegen-template:3000';

$sourcecourseid = optional_param('sourcecourseid', 422, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/local/coursegen/testcoursegen.php');

$resultline = '';

if ($action === 'create' && confirm_sesskey()) {
    try {
        $courseexport = course_export_service::export_course($sourcecourseid);

        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');
        $response = $curl->post($nodeserviceurl . '/api/course-result', json_encode($courseexport));

        if ($curl->get_errno()) {
            throw new \Exception('Could not reach the coursegen_template test service: ' . $curl->error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['result'])) {
            throw new \Exception('Unexpected response from the coursegen_template test service: ' . $response);
        }
        $resultdata = $decoded['result'];

        $session = course_session_service::create_from_form_data(
            new stdClass(),
            $USER->id,
            'testcoursegen-' . bin2hex(random_bytes(8))
        );

        $creationresult = create_course_service::create_course($session, $resultdata, []);

        if (!empty($creationresult['success'])) {
            $courseurl = new moodle_url('/course/view.php', ['id' => $creationresult['courseid']]);
            $resultline = $courseurl->out(false);
        } else {
            $resultline = 'Error: ' . $creationresult['message'];
        }
    } catch (\Throwable $e) {
        $resultline = 'Error: ' . $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<body>
<form method="post" action="<?php echo s($pageurl->out(false)); ?>">
    <input type="hidden" name="sesskey" value="<?php echo s(sesskey()); ?>">
    <input type="hidden" name="action" value="create">
    <input type="number" name="sourcecourseid" value="<?php echo s($sourcecourseid); ?>">
    <input type="submit" value="Create test course">
</form>
<?php if ($resultline !== ''): ?>
<p><?php echo s($resultline); ?></p>
<?php endif; ?>
</body>
</html>
