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

use local_coursegen\local\template\template_actions;

/**
 * Maps what a template saves for an activity to what the AI service is told about it.
 *
 * This is the only place that knows both vocabularies: the template saves "keep" or "ai", and
 * the init payload of contract version 2 says "keep" or "modify".
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_export_behavior {
    /** @var string Action of the contract for an activity the AI never touches. */
    public const KEEP = 'keep';

    /** @var string Action of the contract for an activity the AI modifies. */
    public const MODIFY = 'modify';

    /**
     * The template_behavior entry of one activity.
     *
     * @param \stdClass|null $item The saved item of the activity, or null when nothing was saved for it.
     * @return array For example ['action' => 'keep'] or ['action' => 'modify', 'instruction' => 'Update the dates'].
     */
    public static function for_item(?\stdClass $item): array {
        if ($item === null || $item->action !== template_actions::AI) {
            return ['action' => self::KEEP];
        }
        $instruction = trim((string) $item->instruction);
        if ($instruction === '') {
            return ['action' => self::MODIFY, 'instruction' => null];
        }
        return ['action' => self::MODIFY, 'instruction' => $instruction];
    }
}
