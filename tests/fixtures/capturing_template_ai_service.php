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

namespace local_coursegen;

use local_coursegen\local\service\mock_template_ai_service;
use local_coursegen\local\service\template_content_generator;

/**
 * Capturing AI-service double for template_course_builder_service tests.
 *
 * Records every generate() payload it receives, then delegates to the real
 * mock so the produced activity is still a valid create_from_ai_result()
 * input and the full course-build flow completes.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capturing_template_ai_service implements template_content_generator {
    /** @var array[] Every payload generate() was called with, in call order. */
    public array $payloads = [];

    /**
     * Record the payload, then delegate to the mock generator.
     *
     * @param array $payload Per-activity generation payload.
     * @return array{resource_type:string,parameters:array}
     */
    public function generate(array $payload): array {
        $this->payloads[] = $payload;
        return (new mock_template_ai_service())->generate($payload);
    }

    /**
     * Delegate to the mock generator.
     *
     * @param array $payload Section-picture payload.
     * @return array{filename:string,mimetype:string,content:string}
     */
    public function generate_section_picture(array $payload): array {
        return (new mock_template_ai_service())->generate_section_picture($payload);
    }
}
