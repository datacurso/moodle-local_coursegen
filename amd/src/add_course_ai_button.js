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
 * Add AI button to course creation/edit form
 *
 * @module     local_coursegen/add_course_ai_button
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from "core/templates";

/**
 * Initialize the AI button functionality
 */
export const init = () => {
  addAIButton();
};

/**
 * Add the AI button before the submit button
 */
const addAIButton = async () => {
  // Find the submit button group — Moodle renders it as #fgroup_id_buttonar
  // on course edit forms, or .mb-3.fitem.form-submit on some themes.
  const submitElement = document.getElementById("fgroup_id_buttonar")
    || document.querySelector(".mb-3.fitem.form-submit")
    || document.querySelector('div[data-fieldtype="submit"]')
    || document.querySelector('input[name="saveanddisplay"]')?.closest(".fitem")
    || document.querySelector('input[name="saveandreturn"]')?.closest(".fitem");

  if (!submitElement) {
    return;
  }

  await insertAIButton(submitElement);
  const button = document.querySelector(
    '[data-action="local_coursegen/add_ai_course"]'
  );
  if (button) {
    button.addEventListener("click", handleAIButtonClick);
  }
};

/**
 * Handle the AI button click event
 * @param {Event} e - The click event
 */
const handleAIButtonClick = async (e) => {
  e.preventDefault();
  const btn = e.currentTarget;
  if (btn) {
    btn.setAttribute("disabled", "disabled");
    btn.classList.add("disabled");
  }

  document.querySelector('input[name="local_coursegen_create_ai_course"]').value = 1;
  document.querySelector('input[name="saveanddisplay"]').click();
};

/**
 * Insert the AI button before the target element
 * @param {Element} targetElement - The element before which to insert the button
 */
const insertAIButton = async (targetElement) => {
  // Check if button already exists to avoid duplicates
  if (document.querySelector('[data-action="local_coursegen/add_ai_course"]')) {
    return;
  }

  // Render the template (no context needed as template is self-contained)
  const { html } = await Templates.renderForPromise(
    "local_coursegen/add_ai_course_button",
    {}
  );
  const buttonContainer = document.createElement("div");
  buttonContainer.innerHTML = html;

  // Insert before the submit element
  targetElement.parentNode.insertBefore(buttonContainer, targetElement);
};
