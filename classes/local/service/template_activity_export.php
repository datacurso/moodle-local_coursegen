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
use local_coursegen\local\service\mold_export\base_mold_export;

/**
 * One real activity's "parameters" for the course-template payload.
 *
 * A mold is the case that actually matters here: the AI reproduces its
 * structure field by field (rich text with markers, repeatable rows, real
 * settings), so each supported module type has its own exporter under
 * mold_export\{modname}_mold_export producing exactly the dict the
 * service's generate_<type>_for_template documents. Every other module
 * type only needs enough to identify and place it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity_export {
    /**
     * Build one activity's parameters.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function parameters_for(cm_info $cm): array {
        $class = __NAMESPACE__ . '\\mold_export\\' . $cm->modname . '_mold_export';
        if (class_exists($class) && is_subclass_of($class, base_mold_export::class)) {
            return $class::export($cm);
        }
        return base_mold_export::minimal($cm);
    }
}
