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

namespace local_coursegen\local\preview\workshop;

use moodle_url;

/**
 * The workshop class's own link builders, made to lead back to the preview,
 * kept apart from view.php only because together they crossed the 250-line
 * cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_urls {
    /**
     * workshop::updatemod_url(): the activity's settings, from the preview.
     *
     * @return moodle_url
     */
    protected function updatemod_url(): moodle_url {
        return $this->url_to(['update' => 1, 'return' => 1]);
    }

    /**
     * workshop::editform_url().
     *
     * @return moodle_url
     */
    protected function editform_url(): moodle_url {
        return $this->url_to(['editform' => 1]);
    }

    /**
     * workshop::submission_url().
     *
     * @param int|null $id
     * @return moodle_url
     */
    protected function submission_url($id = null): moodle_url {
        return $this->url_to(['submission' => 1, 'id' => $id]);
    }

    /**
     * workshop::exsubmission_url().
     *
     * @param int $id
     * @return moodle_url
     */
    protected function exsubmission_url($id): moodle_url {
        return $this->url_to(['exsubmission' => 1, 'id' => $id]);
    }

    /**
     * workshop::allocation_url().
     *
     * @param string|null $method
     * @return moodle_url
     */
    protected function allocation_url($method = null): moodle_url {
        $params = ['allocation' => 1];
        if (!empty($method)) {
            $params['method'] = $method;
        }
        return $this->url_to($params);
    }

    /**
     * workshop::switchphase_url().
     *
     * @param int $phasecode
     * @return moodle_url
     */
    protected function switchphase_url($phasecode): moodle_url {
        $phasecode = clean_param($phasecode, PARAM_INT);
        return $this->url_to(['phase' => $phasecode]);
    }

    /**
     * A link to the preview with extra parameters, where the workshop linked to one of its pages.
     *
     * @param array $params
     * @return moodle_url
     */
    protected function url_to(array $params): moodle_url {
        $url = new moodle_url($this->here);
        foreach ($params as $name => $value) {
            if ($value !== null) {
                $url->param($name, $value);
            }
        }
        return $url;
    }
}
