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

use local_coursegen\local\streaming\stream_type;
use moodle_url;

/**
 * Builds the streaming URLs of the Datacurso course AI service.
 *
 * The streaming endpoint paths are owned by this plugin so they can evolve
 * with the coursegen features without requiring a provider plugin release.
 * The provider only supplies the region-resolved base URL.
 *
 * Browsers never receive the service URLs: they read the streams through the relay
 * of this plugin, whose URL is built here too.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class streaming_url_builder {
    /**
     * Build the course planning streaming URL for a session.
     *
     * @param string $baseurl Region-resolved API base URL, with or without trailing slash.
     * @param string $sessionid External planning session identifier.
     * @return string Streaming URL.
     */
    public static function course_stream(string $baseurl, string $sessionid): string {
        return rtrim($baseurl, '/') . '/course/stream/' . urlencode($sessionid);
    }

    /**
     * Build the activity generation streaming URL for a job.
     *
     * @param string $baseurl Region-resolved API base URL, with or without trailing slash.
     * @param string $jobid External activity generation identifier (thread_id).
     * @return string Streaming URL.
     */
    public static function mod_stream(string $baseurl, string $jobid): string {
        return rtrim($baseurl, '/') . '/activity/stream/' . urlencode($jobid);
    }

    /**
     * Build the URL of the plugin relay that streams a generation to the browser.
     *
     * The relay is called with the Moodle session key, which the caller adds.
     *
     * @param string $streamtype Stream type, one of the stream_type constants.
     * @param string $threadid External thread identifier.
     * @return string Relay URL.
     * @throws \coding_exception When the stream type is unknown.
     */
    public static function relay(string $streamtype, string $threadid): string {
        if (!stream_type::is_valid($streamtype)) {
            throw new \coding_exception('Unknown stream type: ' . $streamtype);
        }

        $url = new moodle_url('/local/coursegen/stream.php', ['streamtype' => $streamtype, 'threadid' => $threadid]);
        return $url->out(false);
    }
}
