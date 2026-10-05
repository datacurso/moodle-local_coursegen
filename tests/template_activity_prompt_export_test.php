<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursegen;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\service\template_export_service;

/**
 * Verify that an activity instruction reaches the course-generation payload.
 *
 * @package local_coursegen
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\template_export_service
 */
final class template_activity_prompt_export_test extends \advanced_testcase {
    /**
     * Persisted instructions are included in the per-activity AI behavior.
     */
    public function test_activity_prompt_is_included_in_exported_behavior(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $template = new template(0, (object) [
            'name' => 'Prompt template',
            'courseid' => $course->id,
        ]);
        $template->create();

        $instruction = 'Adapt the activity for a beginner audience.';
        $activity = new template_activity(0, (object) [
            'templateid' => $template->get('id'),
            'sectionid' => 0,
            'cmid' => $page->cmid,
            'action' => 'template',
            'prompt' => $instruction,
        ]);
        $activity->create();

        $payload = template_export_service::build_init_payload((int) $template->get('id'));
        $entry = null;
        foreach ($payload['activities'] as $candidate) {
            if ((int) $candidate['cmid'] === (int) $page->cmid) {
                $entry = $candidate;
                break;
            }
        }

        $this->assertNotNull($entry);
        $this->assertSame($instruction, $entry['template_behavior']['prompt']);
    }
}
