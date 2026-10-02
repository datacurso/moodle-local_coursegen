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

namespace local_coursegen\local\service;

use local_coursegen\local\models\template_activity;

/**
 * Which of a finished run's answered activities are new, versus travelling
 * back only as context.
 *
 * A "keep"/"reference" activity comes back exactly as it was submitted - a
 * description of an activity that already exists elsewhere, not something to
 * build. Only the entries the AI was asked to write should be created from
 * the payload, and the payload says which those are: it is the action each
 * activity was submitted with, not a guess from the shape of its id.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_activities_filter {
    /**
     * The activities the run was actually asked to write.
     *
     * @param array $activities
     * @return array
     */
    public static function only_ai_written(array $activities): array {
        $written = [];
        foreach ($activities as $activity) {
            $behavior = $activity['template_behavior'] ?? [];
            $action = $behavior['action'] ?? '';
            if ($action === template_activity::ACTION_INSTANCE) {
                $written[] = $activity;
            }
        }
        return $written;
    }
}
