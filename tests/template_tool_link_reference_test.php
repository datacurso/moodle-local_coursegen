<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for the verified template-tool file reference.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_coursegen\local\service\template_tool_link_reference;

/**
 * Verifies model data cannot choose the resource URL or target activity.
 *
 * @covers \local_coursegen\local\service\template_tool_link_reference
 */
final class template_tool_link_reference_test extends advanced_testcase {
    /**
     * The token is inserted only for the fixed source pair and verified uid.
     */
    public function test_inserts_server_generated_token_for_fixed_pair(): void {
        $result = [
            'template_tool_resource_reference' => [
                'kind' => 'source_activity', 'cmid' => 11340, 'page_cmid' => 11342, 'uid' => '11340',
            ],
            'generated_activities' => [
                ['cmid' => 11342, 'uid' => '11342', 'resource_type' => 'page', 'parameters' => ['page' => ['text' => 'Syllabus content']]],
                ['cmid' => 11340, 'uid' => '11340', 'resource_type' => 'resource', 'parameters' => ['name' => 'Verified PDF']],
            ],
        ];

        $prepared = template_tool_link_reference::prepare($result);

        $text = $prepared['generated_activities'][0]['parameters']['page']['text'];
        $this->assertStringContainsString('<iframe src="$@COURSEGENLINK*11340@$"', $text);
    }

    /**
     * Arbitrary target IDs and URLs are rejected before course creation.
     *
     * @dataProvider invalid_reference_provider
     * @param array $reference
     * @param string $text
     */
    public function test_rejects_untrusted_reference(array $reference, string $text): void {
        $result = [
            'template_tool_resource_reference' => $reference,
            'generated_activities' => [
                ['cmid' => 11342, 'uid' => '11342', 'resource_type' => 'page', 'parameters' => ['page' => ['text' => $text]]],
                ['cmid' => 11340, 'uid' => '11340', 'resource_type' => 'resource', 'parameters' => []],
            ],
        ];
        $this->expectException(moodle_exception::class);
        template_tool_link_reference::prepare($result);
    }

    /**
     * Invalid exact-scope cases.
     *
     * @return array
     */
    public static function invalid_reference_provider(): array {
        return [
            'wrong resource cmid' => [['kind' => 'source_activity', 'cmid' => 999, 'page_cmid' => 11342, 'uid' => '11340'], 'Safe text'],
            'model authored URL' => [['kind' => 'source_activity', 'cmid' => 11340, 'page_cmid' => 11342, 'uid' => '11340'], '<iframe src="https://evil.test"></iframe>'],
        ];
    }
}
