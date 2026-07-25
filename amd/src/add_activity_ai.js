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
 * TODO describe module add_activity_ai
 *
 * @module     local_coursegen/add_activity_ai
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
  "core/templates",
  "core/notification",
  "core/modal",
  "core/custom_interaction_events",
  "core/str",
  "local_coursegen/repository/chatbot",
  "local_coursegen/module_streaming",
], (
  Templates,
  Notification,
  Modal,
  CustomEvents,
  Str,
  chatbotRepository,
  moduleStreaming
) => {
  const LINK_SELECTOR = '[data-action="local_coursegen/add_activity_ai"]';

  let modal = null;
  let initialized = false;

  // Global state for scroll behavior
  let userHasScrolled = false;
  let scrollTimeout = null;

  /**
   * Check if user is at the bottom of the scrollable container
   * @param {Element} element - The scrollable element
   * @returns {boolean} - True if user is at bottom
   */
  const isAtBottom = (element) => {
    const threshold = 50; // 50px threshold
    return (
      element.scrollTop + element.clientHeight >=
      element.scrollHeight - threshold
    );
  };

  /**
   * Setup scroll detection to pause auto-scroll when user scrolls manually
   * @param {Element} scrollContainer - The container to monitor for scroll
   */
  const setupScrollDetection = (scrollContainer) => {
    if (!scrollContainer) {
      return;
    }

    const handleScroll = () => {
      // Clear existing timeout
      if (scrollTimeout) {
        clearTimeout(scrollTimeout);
      }

      // Mark that user has scrolled
      userHasScrolled = true;

      // Check if user scrolled back to bottom
      if (isAtBottom(scrollContainer)) {
        // Reset flag after a short delay to resume auto-scroll
        scrollTimeout = setTimeout(() => {
          userHasScrolled = false;
        }, 1000);
      }
    };

    scrollContainer.addEventListener("scroll", handleScroll, { passive: true });
  };

  const init = () => {
    if (initialized) {
      return;
    }
    initialized = true;

    const events = [
      "click",
      CustomEvents.events.activate,
      CustomEvents.events.keyboardActivate,
    ];

    CustomEvents.define(document, events);

    events.forEach((event) => {
      document.addEventListener(event, async (e) => {
        const link = e.target.closest(LINK_SELECTOR);
        if (!link) {
          return;
        }
        e.preventDefault();
        const payload = readDataset(link);
        await openChatModal(payload);
      });
    });
  };

  const readDataset = (el) => {
    const { sectionnum, beforemod } = el.dataset;
    return {
      sectionnum: Number(sectionnum),
      beforemod: beforemod ? Number(beforemod) : null,
    };
  };

  const openChatModal = async (payload) => {
    try {
      // If there is already an open modal, close it first
      if (modal) {
        await modal.destroy();
        modal = null;
      }

      const [bodyHTML, footerHTML] = await Promise.all([
        Templates.render("local_coursegen/add_activity_ai_modal", {}),
        Templates.render("local_coursegen/activity_chat_footer", {
          cangenerateimages: Boolean(payload.cangenerateimages),
        }),
      ]);

      const title = await Str.get_string(
        "addactivityai_modaltitle",
        "local_coursegen"
      );

      modal = await Modal.create({
        title,
        body: bodyHTML,
        footer: footerHTML,
        large: true,
        scrollable: true,
        removeOnClose: true,
      });

      // Align width/appearance with course AI modal.
      modal.getRoot().addClass("local_coursegen_course_ai_modal");

      // Explicitly show after creation (no show: true in options).
      modal.show();

      // Handle modal close event
      modal.getRoot().on("hidden.bs.modal", () => {
        if (modal) {
          modal.destroy();
          modal = null;
        }
      });
      // Reset scroll state and setup detection
      userHasScrolled = false;
      if (scrollTimeout) {
        clearTimeout(scrollTimeout);
        scrollTimeout = null;
      }

      // Setup scroll detection on modal body
      const modalBody = modal.getBody()[0];
      if (modalBody) {
        setupScrollDetection(modalBody);
      }

      // Chat handlers
      const bodyEl = modal.getBody()[0];
      const footerEl = modal.getFooter()[0];
      wireChatHandlers(bodyEl, footerEl, payload);
    } catch (err) {
      Notification.exception(err);
    }
  };

  const wireChatHandlers = (container, footerContainer, payload) => {
    const streamingSection = container.querySelector(
      "#activity-streaming-section"
    );
    const userMessagesSection = container.querySelector(
      "#activity-user-messages"
    );
    const form = footerContainer.querySelector("form.local_coursegen_ai_input");
    const textarea = form.querySelector("textarea");
    const sendBtn = form.querySelector(".local_coursegen_ai_send");

    // Send on submit.
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const prompt = textarea.value.trim();
      if (!prompt) {
        return;
      }

      pushUser(userMessagesSection, prompt);

      // Show user messages section
      if (userMessagesSection) {
        userMessagesSection.style.display = "block";
      }

      const generateImagesEl = document.querySelector(
        'input[name="generate_images"]:checked'
      );
      const generateImages = generateImagesEl ? generateImagesEl.value : "0";

      textarea.value = "";

      // Disable form elements
      sendBtn.disabled = true;
      textarea.disabled = true;
      const radioButtons = document.querySelectorAll(
        'input[name="generate_images"]'
      );
      radioButtons.forEach((rb) => {
        rb.disabled = true;
      });
      setLoading(sendBtn, true);

      try {
        // Start streaming job
        const response = await chatbotRepository.createModStream({
          ...payload,
          prompt,
          generateimages: generateImages,
        });

        if (!response.ok) {
          throw new Error(response.message);
        }

        // Show and use the integrated streaming section
        if (streamingSection) {
          streamingSection.style.display = "block";

          // Update progress indicator text for activity creation
          const progressIndicator = streamingSection.querySelector(
            "[data-region='local_coursegen/course_streaming/progress']"
          );
          if (progressIndicator) {
            const titleElement = progressIndicator.querySelector("h6, h5");
            const subtitleElement = progressIndicator.querySelector("small");
            if (titleElement) {
              const titleText = await Str.get_string(
                "module_creation_title",
                "local_coursegen"
              );
              titleElement.textContent = titleText;
            }
            if (subtitleElement) {
              const subtitleText = await Str.get_string(
                "module_creation_subtitle",
                "local_coursegen"
              );
              subtitleElement.textContent = subtitleText;
            }
            progressIndicator.style.display = "block";
          }
        }

        // Start module streaming using the integrated container
        await moduleStreaming.startModuleStreaming(
          response.streamingurl,
          streamingSection,
          {
            courseid: payload.courseid,
            sectionnum: payload.sectionnum,
            beforemod: payload.beforemod,
            jobid: response.job_id,
          }
        );
      } catch (err) {
        // Show error message in streaming section
        if (streamingSection) {
          streamingSection.style.display = "block";
          const eventList = streamingSection.querySelector(
            "[data-region='local_coursegen/course_streaming']"
          );
          if (eventList) {
            const errorDiv = document.createElement("div");
            errorDiv.className = "alert alert-danger mb-2";
            const errorMsg =
              err.message ||
              (await Str.get_string("addactivityai_error", "local_coursegen"));
            const errorLabel = await Str.get_string(
              "error_label",
              "local_coursegen"
            );
            errorDiv.innerHTML = `<small>❌ ${errorLabel}: ${errorMsg}</small>`;
            eventList.appendChild(errorDiv);

            // Auto-scroll to show error message - only if user hasn't scrolled
            if (!userHasScrolled) {
              const modalBody = document.querySelector(".modal-body");
              if (modalBody) {
                modalBody.scrollTop = modalBody.scrollHeight;
              } else {
                eventList.scrollTop = eventList.scrollHeight;
              }
            }
          }
        }
      } finally {
        // Re-enable form elements
        radioButtons.forEach((rb) => {
          rb.disabled = false;
        });
        textarea.disabled = false;
        sendBtn.disabled = false;
        setLoading(sendBtn, false);
      }
    });

    // Press Enter to send (without Ctrl, as is more common in chat)
    textarea.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        form.requestSubmit();
      }
    });
  };

  const pushUser = (wrap, text) => addBubble(wrap, text, "user");

  const addBubble = (wrap, text, role) => {
    const row = document.createElement("div");
    row.className = `local_coursegen_ai_msg ${role}`;
    const b = document.createElement("div");
    b.className = "bubble";
    b.textContent = text;
    row.appendChild(b);
    wrap.appendChild(row);
    scrollToBottom(wrap);
  };

  const scrollToBottom = (wrap) => {
    if (!userHasScrolled) {
      const modalBody = document.querySelector(".modal-body");
      if (modalBody) {
        modalBody.scrollTop = modalBody.scrollHeight;
      } else {
        wrap.scrollTop = wrap.scrollHeight;
      }
    }
  };

  const setLoading = (btn, isLoading) => {
    btn.disabled = isLoading;
    btn.style.opacity = isLoading ? 0.7 : 1;
  };

  return { init, openChatModal };
});
