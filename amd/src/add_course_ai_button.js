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

import Ajax from "core/ajax";
import Templates from "core/templates";
import Notification from "core/notification";
import * as FormEvents from "core_form/events";
import * as FormChangeChecker from "core_form/changechecker";

const VALIDATE_WS = "local_coursegen_validate_course_form";
const PROCESS_WS = "local_coursegen_process_course_form";

/**
 * Initialize the AI button functionality
 */
export const init = () => {
  addAIButton();
};

/**
 * Add the AI button before the submit button
 */
const addAIButton = async() => {
  const form = document.querySelector("form.mform");
  if (!form) {
    return;
  }

  // Find the submit form element
  const submitElement = document.querySelector(".mb-3.fitem.form-submit");

  if (!submitElement) {
    // If not found, try alternative selectors
    const alternativeSubmit =
      document.querySelector('div[data-fieldtype="submit"]') ||
      document.querySelector('input[name="saveandreturn"]')?.closest(".fitem");

    if (alternativeSubmit) {
      await insertAIButton(alternativeSubmit);
    }
    return;
  }

  await insertAIButton(submitElement);
  const button = document.querySelector(
    '[data-action="local_coursegen/add_ai_course"]'
  );
  if (!button) {
    return;
  }

  button.addEventListener("click", async(e) => {
    e.preventDefault();

    // If we found invalid fields, focus on the first one and do not submit via ajax.
    if (!validateElements(form)) {
      return;
    }

    disableButtons(form);
    clearServerErrors(form);

    const hiddenFlag = form.querySelector(
      'input[name="local_coursegen_create_ai_course"]'
    );
    if (hiddenFlag) {
      hiddenFlag.value = 1;
    }

    const formData = new URLSearchParams(new FormData(form)).toString();

    try {
      // Primero, validación servidor.
      const validation = await Ajax.call([
        {
          methodname: VALIDATE_WS,
          args: {payload: formData},
        },
      ])[0];

      if (!validation.ok) {
        const errorsMap = {};
        (validation.errors || []).forEach((err) => {
          errorsMap[err.field] = err.msg;
        });
        showServerErrors(form, errorsMap);
        enableButtons(form);
        return;
      }

      // Si la validación OK, procesar vía webservice dinámico.
      const response = await Ajax.call([
        {
          methodname: PROCESS_WS,
          args: {formdata: formData},
        },
      ])[0];

      if (!response.submitted) {
        enableButtons(form);
        return;
      }

      const data = response.data || {};

      // Form was submitted properly: limpiar estado dirty y redirigir.
      FormEvents.notifyFormSubmittedByJavascript(form, true);
      FormChangeChecker.resetFormDirtyState(form);
      enableButtons(form);

      if (data.redirecturl) {
        window.location.href = data.redirecturl;
      }
    } catch (err) {
      enableButtons(form);
      Notification.exception(err);
    }
  });
};

/**
 * Insert the AI button before the target element
 * @param {Element} targetElement - The element before which to insert the button
 */
const insertAIButton = async(targetElement) => {
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

const validateElements = (form) => {
  // Notificar envío JS (resetea autosave Atto, etc.).
  FormEvents.notifyFormSubmittedByJavascript(form);

  // Ahora verificamos campos inválidos.
  const invalid = [...form.querySelectorAll('[aria-invalid="true"], .error')];
  if (invalid.length) {
    const focusField = invalid[0];
    focusField.scrollIntoView({
      behavior: "smooth",
      block: "center",
    });
    setTimeout(() => {
      focusField.focus({preventScroll: true});
    }, 0);
    return false;
  }

  return true;
};

const disableButtons = (form) => {
  form
    .querySelectorAll('input[type="submit"], button[type="submit"]')
    .forEach((el) => el.setAttribute("disabled", true));
};

const enableButtons = (form) => {
  form
    .querySelectorAll('input[type="submit"], button[type="submit"]')
    .forEach((el) => el.removeAttribute("disabled"));
};

const clearServerErrors = (form) => {
  form.querySelectorAll(".is-invalid").forEach((el) =>
    el.classList.remove("is-invalid")
  );
  form
    .querySelectorAll(
      '.form-control-feedback.invalid-feedback[data-from-aicourse="1"]'
    )
    .forEach((el) => el.remove());
};

const showServerErrors = (form, errors) => {
  let focusField = null;
  Object.entries(errors).forEach(([field, msg]) => {
    if (field === "_general") {
      return;
    }

    const input = form.querySelector(`#id_${field}`);
    if (!input) {
      return;
    }

    input.classList.add("is-invalid");

    let feedback = form.querySelector(`#id_error_${field}`);
    if (!feedback) {
      feedback = document.createElement("div");
      feedback.className = "form-control-feedback invalid-feedback";
      input.insertAdjacentElement("afterend", feedback);
    }

    feedback.setAttribute("data-from-aicourse", "1");
    feedback.textContent = msg;
    feedback.style.display = "block";

    if (!focusField) {
      focusField = input;
    }
  });

  if (focusField) {
    focusField.scrollIntoView({
      behavior: "smooth",
      block: "center",
    });

    setTimeout(() => {
      focusField.focus({preventScroll: true});
    }, 0);
  }
};
