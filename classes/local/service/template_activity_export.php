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

use cm_info;
use local_coursegen\local\backup\activity_reader;

/**
 * One real activity's "parameters" for the course-template payload.
 *
 * A mould is the case that matters: the AI reproduces its structure piece by
 * piece, so the mould has to travel whole, with the markers its author wrote
 * still in the text that carries them.
 *
 * It used to travel as a hand-written description, and only for lessons. Every
 * other type sent its name and its place and nothing else, so a template built
 * on a book or a quiz had nothing to reproduce. The description was also ours
 * to keep correct: a list of thirty lesson columns, against the forty-two the
 * activity really has.
 *
 * Each module already describes itself completely, and that description is now
 * what travels. See activity_reader.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity_export {
    /**
     * Build one activity's parameters.
     *
     * Name and section stay at the top because they are not the activity's
     * content: they are where it sits in the course, which is what the answer
     * needs to place what it writes.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function parameters_for(cm_info $cm): array {
        return [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            // Everything the activity is made of, as its own module declares
            // it: its settings, and every list that belongs to it, each
            // element carrying the id the module gave it.
            'structure' => activity_reader::read($cm),
        ];
    }
}
