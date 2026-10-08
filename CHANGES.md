# Changelog

All notable changes to this project will be documented in this file. Each change is logged under its own build number, newest first; the release stays as it is.

## [2.0.10] - 2026100624

### Fixed

- **A failed template generation always tells what happened in plain words**  
  The card that offers to try again showed "[object Object]" when the service reported the failure as an object, for example a message it had already localized, a code with its details, or a failure wrapped under `detail` or `error`. Every place that shows a failure of the template run, the card to try again, the closed view after a reload and the pass that follows the stream, now reads the failure whatever its shape: a known code (a document with too many pages, a missing request, a license problem, a model that took too long, a busy service, a file that could not be saved and more) gets its own sentence in English and Spanish, an unknown code shows the sentence that came with it, and when nothing safe can be shown a generic sentence is used. Raw JSON, object markers, stack traces and codes are never shown, and the text is always written as text. The failure reason also stays the one the service gave when the connection closes right after it. The answer card reads the errors of a rejected answer the same way. Along the way the template stream module was split into smaller ones for the stage labels, the review round and the events of a run, with no change in behavior.

## [2.0.10] - 2026100623

### Changed

- **The steps of a template generation name the activity and read as professional status lines**  
  The progress feed now says which activity each step is about ("Revisando la actividad «Guía Semanal»", "Adjuntando el archivo a «Guía Didáctica»") and repeated lines are only merged when they are the same step on the same activity. The lines are neutral statements in the gerund, like the status lines of free mode, and the chat voice is kept only for the messages the AI writes as a conversation. The question card says what is missing ("Falta un archivo" or "Se necesita más información") and names the activity it is about. The errors, the notices and the answer and syllabus messages state what happened and what to do, and the leftover formal wording in the template picker help is gone. The activity name travels in the stream events as plain data and is always shown as text.

## [2.0.10] - 2026100622

### Changed

- **Everything the AI says in a template generation reads as a conversation with the teacher**  
  The steps of the progress feed, the question card, the review card, the messages when something stops, the errors of the syllabus and of the answers, and the notices now speak the way free mode already does: the AI in the first person ("I am reading the template", "I finished generating the course") and the teacher spoken to directly. In Spanish the register is neutral, warm and professional: it avoids "tu" and never uses "usted", and prefers phrasing such as "la plantilla" or "el curso". The three failures of the stream that were hard-coded in English (the run could not finish, ended unexpectedly, lost its connection) are now language strings in both languages. A test checks that both languages carry the same strings with the same placeholders and that the Spanish ones keep the register.

## [2.0.10] - 2026100621

### Changed

- **The progress of a template generation and the way to preview the course are always in view**  
  The count of the activities the AI writes moved from the bottom of the left column to the top bar, right after the path: a small pill with a ring that fills as the activities are written and the count ("1/2"). A press opens a short list under it with one row per activity (a ring that turns while it is written, a green check once it is done); the list closes with a press anywhere else or with Escape, so the detail is one press away and never takes room of its own. While the AI waits for an answer the pill shows a small amber dot. The line that said how many sections and activities the template has, and the link to preview the course, moved from the top of the right column to the same bar, on the right. On a narrow screen the bar gives up its words before anything else: the link keeps only its icon, then the chip and the label of the pill go, and the list takes the width of the screen. The cards of the left column keep their place. Free mode is not affected.

## [2.0.10] - 2026100620

### Changed

- **The left column of a template generation is a clean timeline with cards**  
  What the AI does is now a compact timeline: one small line per step with a marker (a spinner for the step in progress, an amber ring while the AI waits for the professor, a check once the run is over), the time on the same baseline and a thin line joining the steps. A step the AI takes several times in a row is one line with a count instead of repeated lines. The seconds of a long call sit right under the steps in a small pill. The activities the AI writes are a card with a meter that fills as they are written, one tidy row per activity with its type, and a green check on each one that is done. The question of the AI, the progress and the review of the course share one look: the same border, radius, spacing and buttons, with the secondary action on the left and the primary one on the right in a single aligned row, and a primary button that is clearly disabled but still readable until there is an answer. The file control of a question is a drop-zone looking area that says which files it takes (PDF, DOCX or TXT) and turns into a roomy row with the file name once one is picked. The bar that said the AI was generating is hidden while the AI waits for an answer. Nothing turns or pulses for a professor who asked for less motion. Free mode is not affected.

## [2.0.10] - 2026100619

### Changed

- **The question the AI asks during a template generation looks like the rest of the page**  
  The card that appears when the AI needs something from the professor was drawn with stock blue buttons, a native file button and a bare link, so it stood out from everything around it. It is now the decision card of the page: the send button is the same coral one as Accept and stays disabled until there is something to send, the secondary actions are the outline button of Adjust, a picked file shows as the same chip as the attached syllabus with a way to remove it, and the options are the same selectable rows as the proposals. The notice that offers a new attempt uses the same buttons.

### Fixed

- **The progress list no longer opens with two almost identical lines**  
  A generation started with "Reading the template." and the first step of the AI said "Reading the template" right after it. The first line now says that the generation is starting.

## [2.0.10] - 2026100618

### Fixed

- **The PDF a page embeds shows inside the page of the preview instead of being downloaded**  
  A page of a template whose viewer pointed to the file of a resource showed an empty frame while the browser downloaded the file, because the page that served it from the draft area of the reviewer always downloads. The preview now serves its files from a page of its own, only to the person who is reviewing, and shows a PDF or an image inside the page. Any other type is still downloaded, and a link to the file keeps working as before. Creating the course is not affected.

## [2.0.10] - 2026100617

### Fixed

- **A written activity keeps its check once a template generation has finished**  
  After the previous fix the rows of the activities the AI had written went back to a bare look, with nothing to show they were done. Now a row the AI wrote ends with the same check as in free mode, and it stays through the review and after a reload. A row the AI did not reach, or could not write, ends with no mark and never with a spinner. A change request sends only the rows being redone back to the spinner, and they end with their check again.

## [2.0.10] - 2026100616

### Fixed

- **No row keeps a spinner or a badge once a template generation has finished**  
  The rows of the activities the AI writes kept the mark of their last state, a spinner or a badge, while the generated course was waiting for the professor to accept it or ask for changes. When the run completes, every row now goes back to its normal look, as it does in free mode, and the same happens when the page is reloaded on that review. A change request spins only the rows being redone and clears them again at the end.

## [2.0.10] - 2026100615

### Changed

- **The Generate button of a template waits for something to work from**  
  The button was on as soon as a template was loaded. It now needs a text that says what the course is to be, a file, or both: with neither it stays off, it comes on when the professor types or attaches a file, and it goes off again if both are taken away. A text the browser puts back in the box after a reload counts too. Starting with neither is also refused when the request reaches the server, with a clear message.
- **The first line of a generation no longer talks about material the professor did not attach**  
  It now says the AI is reading the template.

### Added

- **A professor with no file can say so when the AI asks for one**  
  The question that asks for a file now has a button to say there is none. The AI then asks what the content must be, so nothing is invented.

## [2.0.10] - 2026100614

### Fixed

- **The preview of a page from a template shows the file the AI attached where the template page showed its own document**  
  A page that embedded the file of a resource, such as a PDF viewer, kept showing the original document of the template even though the resource got the attached file. The page now points to the resource, and its preview shows the attached file from the draft area of the person who reviews it. Creating the course already placed the new file; the page now follows it there too.

## [2.0.10] - 2026100613

### Added

- **While a course is generated from a template, every activity the AI will write shows a spinner from the first moment, and a long wait counts its seconds**  
  The rows marked to be generated stayed still until the AI reached them, and the conversation showed nothing for as long as the first answer of the AI took. Now each of those rows spins as soon as the generation starts, the row being written stands out from the queued ones, and a line under the progress list says "Waiting for the AI… 35 s" and updates in place until the call ends. When the AI stops to ask a question the spinners and the line stop, and they turn again when it goes on

## [2.0.10] - 2026100612

### Fixed

- **A step the AI retries by itself is no longer shown as a failure while a course is generated from a template**  
  The conversation printed a red line such as "A step did not work: t:11340 is a resource; this phase can modify: ['page']" whenever the AI tried something that was refused and then did it another way. Those refusals are meant for the AI, not for the person watching, so the conversation now shows only what the AI is doing, and a real failure still stops the generation with its own message. The string of that line is removed from both languages.

## [2.0.10] - 2026100611

### Fixed

- **The preview of a file the AI attached no longer fails, and every preview file is read from the draft area of the reviewer**  
  Opening the preview of a modified file resource stopped with "A generated file entry has no thread_id", because the files of the template result carry only an id and a name. The files of a run are now downloaded once into the draft area of the person who reviews it, in a folder named by the uid of their activity, and the previews address them there. A modified file resource shows the file that was attached instead of the file of the template. Creating the course copies the files from that draft area into the new activities and the resource, and the draft files are deleted when the course is created or the generation is cancelled. The old storage of generated files under the system context and its file server are removed.

### Changed

- **Each activity of a template now has its own opaque uid instead of the number of its course module**  
  The uid is generated when the activity is first saved in the template, stays the same while the template is saved again, and is what the payload, the answer of the AI, the previews and the links between activities are named by. The upgrade gives every activity already saved in a template its uid. The progress list and the change requests find the row of an activity through its course module id, which the row keeps separately.

## [2.0.10] - 2026100610

### Fixed

- **A page that embeds the attached file now shows that file from the new course**  
  The iframe of the generated guide page pointed at the page of the resource, so the file was shown inside a page of Moodle. A link that is the source of an embedded frame now points at the file stored in the resource of the new course, and a plain link to the resource still opens the resource as before. A resource with no file keeps the link to its page.

## [2.0.10] - 2026100609

### Fixed

- **Accepting the result now builds the course with the page the AI wrote, or builds nothing**  
  Accepting a generated course failed while creating the page the AI had rewritten, because the page was never given the data Moodle needs to create it. The page is now created with the text the AI wrote, the way the template page was shown and the visibility and completion of the template activity, and its links to the attached file and to other activities resolve. A course is also complete or not made: when an activity cannot be built or a file cannot be put in its resource, the course that was started is deleted, the generation is marked as failed and the error is shown, instead of leaving a course with something missing that was reported as created.

## [2.0.10] - 2026100608

### Fixed

- **The course is created only after the teacher accepts what the AI generated**  
  When the AI finished, the page went straight to the form that creates the course, with no chance to read the result or ask for changes. The review step is back: the generated course is shown with a card to accept it or ask for changes, to the whole result or to one activity from its row. Accepting opens the review of the name and category and only then creates the course; Cancel leaves without creating anything. A change request makes the AI continue from its draft and finish again, the preview refreshes, and a question asked during a change is answered as before. Reloading the page at any point shows the same screen again: the review, the change in progress or the pending question. The progress bar reaches 100% and no row keeps spinning while the review is open. The files the service holds for the run are deleted once the course is created or the teacher cancels, never between rounds of changes. The preview of a written page now shows the text the AI wrote instead of the text of the template, and its links to other activities open their preview. Nothing else that was removed along with the review step comes back: spaces, markers, reference scanning, instances, scope and naming stay as they are.

## [2.0.10] - 2026100607

### Fixed

- **The template generation now sends each page's text and closes every row of the progress list**  
  The service saw the guide page as empty because the page's html travelled only inside its backup structure, so the page was never rewritten and was still reported as done. Each page now also carries its html and intro in the place the service reads them. The progress list takes its total from the count the service announces, closes any row that is still spinning when the run ends, and shows an activity the run left as it was with a quiet "No changes" label instead of a spinner.

## [2.0.10] - 2026100606

### Fixed

- **A question of the AI no longer ends the generation as if it had failed**  
  When the AI stopped to ask the teacher for a file, a text or a choice, the page reported that the generation ended unexpectedly instead of showing the question. The pass that follows the stream now ends on a question, on a failure the teacher can try again and on completion, which are the three ways a pass ends without failing, so the question card appears and the run goes on once it is answered. A failure the service marks as retryable now shows the retry card while the run is live, as it already did after a reload, instead of closing the page. A stream that ends with nothing said before its end is still reported as a failure.

## [2.0.10] - 2026100605

### Fixed

- **A syllabus can be attached when a course is generated from a template**  
  Starting a generation from a template with a syllabus attached stopped with a message saying the syllabus was not available yet. The syllabus is now sent to the run right after it is created and before it starts, in the same order as the free creation: the run reads its pages as images in every step. The plugin checks the capability to upload syllabi, refuses an empty file or one over 25 MB before sending it, and turns each refusal of the service into a message the teacher can act on: file too large, file that cannot be used, reading that took too long, or a run that cannot take the file. A refused syllabus creates no session and is dropped from the start form, because the draft area of the file is emptied as soon as it has been read, so no copy of the file stays in the site files. The transfer is recorded in the same event as in the free creation, without the content of the file. The reading of the draft area is shared with the answer to a question, whose file keeps its own endpoint and is never taken for the syllabus.

## [2.0.10] - 2026100604

### Fixed

- **Switching the course in the template editor draws its sections again**  
  The service that draws the sections of the chosen course set up the page after reading the course, which Moodle refuses once the theme is ready. The page is now set up first, so picking another course shows its sections.

## [2.0.10] - 2026100603

### Changed

- **The template editor is the page it was before, with only two choices per activity**  
  The page that configures a template went back to its previous layout: the category and course pickers, the name and description, one collapsible card per section with its number of activities, select all, the bulk bar, Save and the warning about unsaved changes. The only difference is the choices of each activity: Keep intact, or Modify with AI with an optional instruction under the row. The extra options, the scope dialog, the spaces for the teacher and the section settings that the simplified editor added are gone.

## [2.0.10] - 2026100602

### Changed

- **The list of templates is a Report Builder report again**  
  The page that lists the templates went back to the report it had before the simplified editor: a table with filters and the Edit and Delete actions of each row in its action menu, instead of a plain table. It shows the name, the course, how many activities the template has, how many of them the AI modifies and when it was last saved, newest first, and a template whose course was deleted says so. The name can be filtered, long lists are paged, and deleting still asks first. The plain list and the code only it used are gone.

## [2.0.10] - 2026100601

### Added

- **A course made from a template runs on the template agent of the AI service**  
  Starting a generation from a saved template creates the run at the template agent endpoints and follows it through the relay of the plugin, so the browser never talks to the service and the license travels on every call. The steps the AI takes show in the conversation as lines, and a step that fails shows its reason.
- **The AI can stop and ask the teacher**  
  When the run pauses on a question, the page shows a card with what the AI asks and the control that answers it: a file picker, a text box or a list of options. The answer is sent to the service and the run goes on from where it was. A file the teacher chooses is read from the draft area, sent to the service and deleted from the draft area whether it worked or not, and an empty file, a file over 25 MB or a blank answer is refused before anything is sent.
- **A reloaded page goes on with the generation**  
  A page reloaded in the middle of a template generation repaints the steps already shown, shows the pending question again exactly as it was and goes on with the stream. A run that stopped for a reason a new attempt may fix shows a button that continues it from its last checkpoint.
- **The file the teacher brings goes in the new course**  
  A resource whose file the run replaced is copied from the template and its file is swapped for the one the teacher brought, so the page that links it points to the new file. If the file cannot be put in the resource, the course is still created and a warning names the file.

### Changed

- **The activity uid of a template run is its course module id**  
  The rows of the structure light up as the steps of the run come, and the links between activities resolve to the activities of the new course.
- **A syllabus cannot be attached to a template generation yet**  
  It is refused with a message instead of being dropped in silence, because the template agent does not read documents yet.

### Removed

- **The review of the generated activities before the course is created**  
  The template agent has no review step. The accept and adjust actions, their web service and their screen are gone, together with the gate that held the generation back while the template flow was rebuilt and the scanner of reference markers.

## [2.0.10] - 2026100600

### Changed

- **A template only says what the AI keeps and what it modifies**  
  The template page has the course of the template, a name, a description and, for each activity of that course, two choices: keep it intact or modify it with the AI, with an instruction that is optional. Everything else the old page configured, such as using an activity as a template, scopes, instances, spaces for the teacher, naming patterns, section limits and section behavior, is gone: what an activity should become is written in its instruction.
- **The data of a template is rebuilt**  
  A template is saved in two tables, one for the template and one for its activities. The tables of the previous shape are dropped when the plugin is upgraded or reinstalled, so the templates saved before have to be created again. The permission to manage templates is a single one again, and the four permissions carved out of it are removed.
- **The init payload for the AI service follows contract version 2**  
  Each activity travels with `keep` or `modify` and its instruction, and the sections of the template are marked as modified when any of their activities is. One class maps what a template saves to what the service is told.
- **A course is built from the kept activities and the ones the AI wrote**  
  The activities of the template that are kept are copied, the ones the AI modifies are written by the AI, and the new course follows the order of the template. The files of a text come from the template course or the AI service.

### Removed

- **The spaces for the teacher and the section limits of the teacher screen**  
  The cards that asked the teacher for a file before generating, and the badge with the sections still available, are gone with the options they depended on.
- **Generating a course from a template says that it is being rebuilt**  
  Starting a generation from a template is refused with a clear message until the AI service can run it, instead of failing in the service.

## [2.0.10] - 2026100501

### Fixed

- **"Create with AI" button missing on My courses in Moodle 5.2**  
  Moodle 5.2 moved the "Manage courses" and "Create course" buttons from the My courses page header into the Course overview block, so the plugin no longer found the header container it injected its button into and the button silently disappeared for users with enrolled courses. The button is now spliced server side into the page HTML by a `before_http_headers` hook, which starts the output buffer once the page URL, context and login are known (the previous `after_config` buffering ran before any of them were set). It is placed next to core's course action buttons in the page header (Moodle 4.5/5.0), right after the "Create course" form inside the Course overview block (Moodle 5.2) or in the empty-state action bar (all versions), so it is part of the initial page response and does not depend on JavaScript.
- **Bootstrap 5 compatibility on Moodle 5.0+**  
  The institutional guideline preview now uses Moodle's `core/modal` API instead of a jQuery Bootstrap 4 modal that never opened on Moodle 5.0, tooltips declare both `data-toggle` and `data-bs-toggle`, and CSS colours fall back from Bootstrap 5 `--bs-*` variables to their Bootstrap 4 names.
- **A reload after the generation finished asks for the course details again**  
  A page reloaded once the generation had completed but the course was not created yet showed the plan review again, with its Accept and Adjust actions, as if the plan still waited for approval, and the form with the name, short name and category of the course was gone. The page now opens that form again, as the live stream does when the generation completes, and a course that already exists still shows its completion view. A generation that ended with a failure is drawn from its conversation with the failure as the last turn and the plan controls enabled, instead of leaving the page empty.
- **Question bank defaults on Moodle 5.0**  
  Quiz questions are created in the module default category through `question_get_default_category()` on Moodle 5.0, since the previous helper is deprecated there.

### Changed
- **The template work and the PHP relay are one branch**  
  The template pages and the generation flows of the template branch now sit on top of the relay, the license header on every call and the reload restoration. Streams the browser used to read straight from the AI service go through the plugin relay.

- **Generation streams are read through Moodle instead of straight from the AI service**  
  The browser used to open the planning and activity streams of the AI service directly, so anyone who knew a thread identifier could follow it and Moodle had no say. The pages now read them from `local/coursegen/stream.php`, which checks that the user owns the planning session or activity job and still holds the capabilities of the paid generation, opens the service stream on the server and passes its events on unchanged. The relay releases the Moodle session lock while it streams, stops the transfer from the service as soon as the browser leaves, and drops a transfer that stays silent for 5 minutes instead of using a total time limit. It holds no lock of its own per thread, because the service runs each thread once and lets every connection follow it. A stream that ends without the service's `done` event is closed with a retryable failure. The server-sent events parser handles `event:` and multi-line `data:` fields, LF, CRLF and CR line ends and comments, and the browser side reads the relay with `fetch` instead of `EventSource`, so the stream handlers are unchanged. The `streamingurl` values returned by `start_course_planning`, `get_course_session_state` and `create_mod_stream` now point to the relay, and the Activity AI mutations module was split into stream and activity creation modules.
- **A reloaded page comes back where the generation was**  
  A page reloaded while a course was being planned or generated used to show only the structure of the plan, because the activities already listed and the progress counters had been streamed and were not kept anywhere. The service now keeps what each phase emits in the state of the run and returns it as `progress_events` in the state snapshot, and the page replays it through the same handlers as the live stream, ahead of the events that follow, so a reload in the middle of planning, of an adjustment or of the generation draws what was on screen. Planning draws only from the replay instead of drawing the plan from the snapshot as well, and an adjustment that is still running no longer shows the review actions. A page that reloads at the review or after the end is drawn as before. The page bootstrap that rebuilds the conversation moved to its own module, and plain node tests for the bootstrap decisions live in `tests/js`.
- **A reloaded page looks the same as before the reload**  
  The reloaded page now shows the syllabus chip of the chat input, keeps the loading skeletons while no section has been drawn yet, draws a finished generation as the live stream leaves it (every activity checked, the editing controls hidden, the header done) and shows the line that names the course and the note the planner wrote about disabled subsections. It also puts back what the user had left open and where the user had scrolled: the open and closed sections, activities and "Show more" blocks, the scroll of both panels, the Adjust mode of the review and the text typed in the chat input and not sent. That state is kept in the session storage of the tab, per planning session.
- **My courses is disabled by default on fresh Moodle 5.2 installs**  
  The "Create with AI" button on My courses needs that page to be enabled. On a fresh Moodle 5.2 site, enable it in `Site administration > Appearance > Navigation > Enable My courses`; upgraded sites keep their existing setting.
- **Tests and CI for Moodle 5.0**  
  The plugin CI workflow installs the DataCurso AI provider from the branch matching each Moodle version, the Behat course page scenarios assert the plugin's own AI button rather than core's activity chooser (renamed on Moodle 5.0), and a plugin data generator seeds institutional guidelines so the preview dialogue is covered by Behat.
- **Guideline popover and preview markup moved from JavaScript to Mustache templates**  
  The institutional guideline list, its compact toolbar variant and the preview dialogue body were built as HTML strings inside `amd/src/local/courseai/context/guideline.js`. They are now rendered from `local_coursegen/local/courseai/guideline_list`, `guideline_list_compact` and `guideline_preview` through `core/templates`, so the markup is theme-overridable, escaped by the template engine and covered by PHPUnit; list clicks are delegated on the container and stale renders while typing in the search box are dropped.
- **Guideline list templates are self-contained listboxes**  
  `local_coursegen/local/courseai/guideline_list` and `guideline_list_compact` now render the whole `<ul role="listbox">` (with a `listlabel` context variable for its accessible name) instead of bare `<li>` fragments, so they validate on their own and the `.mustachelintignore` exception is gone. The page renders them once through the same partials inside `[data-region="guideline-list"]` / `[data-region="guideline-list-compact"]` wrappers, JavaScript replaces the whole list inside those wrappers and delegates clicks on them; the `#guidelineList` / `#guidelineListCompact` ids are unchanged.
- **Every call to the service carries the license, and a missing key stops the call**  
  The AI service now refuses any request without a valid `License-Key`, including the generation streams, which used to be open to anyone who knew a thread identifier. The relay no longer builds the header itself: it asks the provider client for it through `get_license_header()`, the single place that builds it for every call, so the streams, the state reads, the uploads and the file downloads cannot leave without the license. When no key is configured the call fails before any request, with the provider's localized message, instead of reaching the service and coming back refused. This build needs the provider plugin build 2026100400 or later.

## [2.0.10] - 2026100223

### Changed
- **The space card of the teacher screen is smaller and uses an informative blue**  
  The card asked for the teacher's file in red, a colour that reads as an error, and its icon, type and button were larger than the rows around it. It is now drawn in the blue of an informative notice, with a smaller icon, text and button and less padding, so it stands out without shouting. The required state keeps a stronger left border and a filled badge, the file state keeps its green, and the remove action is no longer red.

## [2.0.10] - 2026100222

### Changed
- **The space of the teacher screen is a card that asks for the teacher's file**  
  It is no longer one more row of the list with a small badge and a grey button. It is a full-width card with a dashed border and a soft tint, in the same place of the same section, with the label "Your contribution", the badge "Required" or "Optional", the administrator's instruction as its main line and a clear "Upload file" button. A required space also says that the file has to be uploaded to generate the course. Once the file is added the card turns green and shows the file name with "Change" and "Remove file", and the button shows "Uploading…" while the file selector loads

## [2.0.10] - 2026100221

### Added
- **A file resource of the template can be a space for the teacher's file**  
  In the template editor the action "Space for the teacher" is offered only for a file resource. The teacher sees a card with an "Add" button in the same place of the same section, and it opens the same file selection window as the syllabus. A space can be required, and then the course cannot be generated until the file is added
- **The new course gets the teacher's file where the template had its own**  
  The resource is created in the same section and position with the name and description of the template and the teacher's file. Any text of any activity that points at the template's file (a frame, a link, an image, an embedded object) points at a copy of the teacher's file that the activity keeps in its own files. When the teacher adds no file the resource is not created and only the elements that pointed at its file are removed from the texts

### Changed
- **A space saved on an activity that is not a file resource is read as excluded**  
  The editor says so on the row and the space cannot be saved for those activities any more
- **An address of a file resource that carries its revision is understood**  
  The number between the file area and the file name of a resource address is read as a revision, not as an item id

### Removed
- **The "Course files" block, the "Add activity" and "Add section" controls of the teacher screen**  
  The block that asked for a file at each reference marker, its web service, its upload page, the storage of those files and the file token the AI service handed back are gone. The reference marker stays only to make a new image from a file of the template. The files left by the old storage are deleted on upgrade

## [2.0.10] - 2026100220

### Changed
- **The preview of a generated activity is drawn from its own result and from nothing else**  
  The result of the run holds, for each activity written from a template, the template's own rows with what the AI wrote laid into the row it came from, and every record the AI wrote says which template record it came from (`source_id`) and which row of the result it is (`record_id`). The preview reads exactly that: it no longer looks up the template activity or matches the written pages, chapters, entries or options to the template's rows by title or by order, so two pages that share a title each show their own content and no `[[coursegen:` marker is shown
- **A quiz is previewed from the questions its result carries**  
  The result returns the questions of a quiz as the question bank rows with the AI's texts laid into the row of each question, beside the same questions in the form that creates them. The preview draws the first and no longer rebuilds a question from the form
- **A generation made before the records carried their ids cannot be previewed, and says so**  
  The preview of an activity whose result has no template rows, or whose record names a row the result does not hold or an element the result gives no table to read it from, stops with a message that names the activity and asks to generate the course again. Nothing is guessed from the title or the position. Sessions generated before this build need to be generated again to be previewed

### Removed
- **The title and order matching of the preview**  
  The row matchers for lesson pages and book chapters, the translation of a kept activity into the shape of a plan, the conversion of a quiz form into engine rows and the per-module methods that laid a draft over the template rows are gone, with their tests

### Fixed
- **The preview of a glossary entry, a wiki page and the results of a choice no longer stop with a missing class**  
  The classes that draw them named `stdClass`, `url_select` and `wiki_parser_proxy` without the namespace they live in

## [2.0.10] - 2026100219

### Fixed
- **Every field of a new activity now receives the files it references, whatever the module**  
  Once an activity exists, the rows its module declares are read, every text that points at a file is rewritten to the way Moodle stores it and the file is copied into the file area that field is served from, with the author and license it had. It covers the introduction of every module and the text of wiki pages, the questions of a quiz with their answers and feedback, the criteria of a workshop, the final page and the items of a feedback and the entries of a database, which no one had to remember to wire before. A file that cannot be found stops the creation, removes the half made activity and names the activity, the field and the file.

### Changed
- **The forum, glossary and lesson settings no longer carry their own file handling**  
  The forum message, the glossary definition and the lesson page used to be prepared one by one so their files reached the new activity, and a field nobody wired lost its files. That preparation is gone, and so is the list of file areas a file of the template had to be in: the files of every field are given afterwards in one step for all modules. A link to a file that belongs to a place other than the one the text is stored in stays a link.

## [2.0.10] - 2026100218

### Added
- **A text can be read for the files it references, whichever way it names them**  
  The absolute address of a file of the template or of the teacher, the placeholder of a file the service made and the path of an image the service made are all turned into the one reference Moodle stores, `@@PLUGINFILE@@/name`, and the file they name is found where it comes from. A reference that names no file, or one the user may not copy, stops the creation with the activity, the field and the file in the message. Two different files with the same name stay two files, and the markers the service left unresolved are removed.

## [2.0.10] - 2026100217

### Added
- **The rows of an activity can be listed with the file areas their module declares for each**  
  Reading the structure the module itself declares for a backup, every row an activity is made of is listed with the file areas its texts may keep files in and the item they are stored under, so a module added tomorrow is covered without anybody describing it. The questions of a quiz, which a backup keeps apart from the quiz, are found through the question bank: their text, feedback, answers, hints and the options of their type.

## [2.0.10] - 2026100216

### Added
- **A template can ask for a real video, and the review says when none was found**  
  An iframe whose `src` is entirely one `[[coursegen:aiprompt: ...]]` marker is now a video slot: when the course is generated a real video that can be embedded is searched for it and only its address is written into the iframe, which keeps the rest of its attributes and styles. The "Markers" section of the template configuration explains the rule and tells it apart from a link to another activity. When no suitable video is found the iframe is left out of the activity and the review shows a small notice on that activity with the video that was asked for.

### Changed
- **The backup structure of an activity can now be walked by any reader**  
  What the reader of an activity's backup structure does with each element is no longer fixed: the walk is a method of its own that takes any processor, and a walk can ask for what people filed in the module (posts, entries, records) as well. The tree sent to the AI service is read exactly as before.

## [2.0.10] - 2026100215

### Fixed
- **The files a forum message references now reach the discussion post**  
  The message of a discussion created from a template is prepared like any other rich text: the template's own files, the teacher's files and the files the service made are copied into a draft area that the post saves into its own file area, so an image or a document placed above a reference marker in a discussion is no longer left as a broken address.

## [2.0.10] - 2026100214

### Added
- **The template editor explains the reference marker**  
  A new collapsed "Markers" section of the template configuration holds a help button that explains how to type `[[coursegen:reference: what the teacher brings]]` above the element that holds a file, what the teacher sees for it, and what happens when they bring nothing.

## [2.0.10] - 2026100213

### Added
- **The preview of a generated activity shows the file the teacher brought, never a token**  
  Where a place of the template has a file of the teacher, the preview of the activity that holds it shows that file, served only to the teacher who brought it. A token with no file stops the preview with the name of the activity instead of showing it.

## [2.0.10] - 2026100212

### Added
- **A "Course files" section on the template screen lets the teacher bring their own files before generating**  
  Once a template is chosen, a section lists every place of the template that takes a file, labelled with what the marker asks for and the activity it belongs to. Each place has one optional file input that accepts only the kinds of file the place holds, shows the file already brought with a link to remove it, and says what happens with no file: a new one is generated from the reference, or the place is left out when one cannot be generated. Uploading again replaces the file. The section disappears with the template and for templates with no place for a file.

## [2.0.10] - 2026100211

### Added
- **The template screen can list the places of a template and take the file a teacher brings**  
  A new web service, local_coursegen_get_template_reference_slots, lists the places of a template that take a file with what each asks for, the activity it belongs to, the kinds of file it accepts and the file the teacher already brought. A new page, reference_file.php, takes the upload itself, a real file in a multipart request, or empties a place. Both check the session key and the capability to create a course from a template, and the page only accepts a place the template really has.

## [2.0.10] - 2026100210

### Added
- **Files a teacher brings for a course are kept apart from their drafts, checked, and cleaned up**  
  A file uploaded for a place of a template is kept in a private area of the plugin, in the teacher's own context, one file per place, a new upload replacing the old one. The site's upload limit applies, the name is made safe, and a place only takes the kind of file its element holds; anything the site would serve as a page or run as a script is refused. Only the teacher who brought a file can open it. When a generation starts its files are handed to it and deleted once the course is built; a daily task deletes the ones that waited more than a week, and a teacher's files go with their data when it is erased.

## [2.0.10] - 2026100209

### Added
- **The places of a template where the teacher may bring a file are found**  
  The activities saved as a template are searched with the same reference scanner the AI service run uses, so a place is numbered exactly as the run numbers it. Each place is named after its activity and its order, kept apart from the uid the payload gives the activity at each export, and takes the kind of file of the one the template holds: an image place takes images, a video place videos, any other place documents, and nothing the site would serve as a page or run as a script. A marker with no usable element below it stops the search with the name of the marker. Nothing is shown to the teacher yet.

## [2.0.10] - 2026100208

### Added
- **A template can ask the AI for a new image coherent with the new course, using the image already in the template as the reference**  
  The new image takes the original's place in every course made from the template; nothing of the original course is left in it. It works in any text of any activity and does not depend on the images switch. The files travel to the AI service and back as files, the review preview shows the new images, and a file of a kind the AI cannot make anything from (a document, a video) is removed together with its block so nothing of the original course leaks. Both services must be released together.

## [2.0.10] - 2026100207

### Added
- **A template can mark an image to be replaced by a new one made for the new course: the markers are read and the files they point at are sent to the AI service**  
  Write `[[coursegen:reference: your instruction]]` right above the image (or the block that holds it) in the activity used as template. Before the generation starts, every marker is checked (it needs an element below it holding exactly one file of the template activity) and the images are sent to the AI service as files, except those the teacher provides. A marker that cannot be used is reported before anything is sent.

## [2.0.10] - 2026100206

### Fixed
- **The preview shows the images of an activity the template keeps, whichever of its texts holds them**  
  A kept activity that declares several file areas, such as a page with a description area and a content area, had every image of its text pointed at the first area, so the images stored in the other one showed as broken. Each image is now pointed at the area that really stores its file, for any module type and any of its texts. Nothing changes for an activity that declares a single area.

## [2.0.10] - 2026100205

### Fixed
- **A course made from a template now shows the blocks of the template and its custom field values**  
  The new course came out with the blocks the site gives every new course instead of the template's: the template's search in forums, upcoming events and recent activity were missing, and the site's rating block took their place. That rating block also stopped the section cards of the grid format from opening their window. The new course now shows the blocks of the template, each with its configuration, its files and its place on the page, and the values the template gives to the course custom fields.

## [2.0.10] - 2026100204

### Fixed
- **The review card of a course made from a template read as if it were scolding the teacher**  
  The card explained that the structure could not be changed here. It now simply invites the teacher to review the generated activities, accept the course, or press Adjust to change something. The Spanish texts of that review and its progress messages now address the teacher in the same friendly tone as free creation.
- **The Spanish review texts no longer use regional forms**  
  The review form and the instance prompt placeholder asked the teacher to "previsualizá", "ajustá", "ingresá" or "describí" and said "si lo dejás vacío". They now use the neutral forms the rest of the Spanish texts use.
- **The review of a course made from a template did not look or behave like the review of free creation**  
  After the course was generated, the review card could be left without a visible Accept button, the send button of the composer turned into a clipped text button, and the status kept spinning while the system was only waiting for the teacher. The review now uses the same decision card as free creation, with Accept and Adjust. Choosing Adjust brings the composer back with the usual round send button and the slim bar that keeps Accept reachable. While the course waits for the review, the spinner gives way to a check in both columns and no working indicator is shown.
- **The preview of an activity the template keeps now shows its real content**  
  Opening the preview of a text and media area, a file, a forum, a survey or any other activity the template keeps as it is showed only the banner and a message saying there was nothing to preview. It now shows the activity as the new course will contain it, and says it will be copied unchanged.

## [2.0.10] - 2026100203

### Fixed
- **A course made from a template now keeps the template's format, its settings and the look of each section**  
  The new course was created with the site's default format and nothing else of the template: a template in the grid format came out as topics, without the format options, the section options, the summaries of the sections and their files, the pictures of the grid, or the language. Everything the template does not mark now carries over as it is: the format and how it is set up, the language, the course-wide settings (news items, grades and reports display, activity dates, completion conditions, upload size, groups and theme) and, for each section, its summary with its files, its format options and the picture the grid shows it with. The name, short name, description and category still come from the teacher, and the course is visible as a free one is.

## [2.0.10] - 2026100202

### Fixed
- **The preview of a generated activity shows the name the admin gave the instance in the template editor in its page heading**  
  The page heading is printed from the course module the preview is built on, which carries the name of the template activity ("Molde - Lección estándar"), while the breadcrumb showed the generated one. The preview now gives that course module the generated name for the request, which is the instance name, so both agree. Nothing is saved.

## [2.0.10] - 2026100201

### Fixed
- **The preview of a lesson whose page titles carry a marker no longer shows the template's own marker**  
  A page whose title the template leaves to the AI (for example "Semana" followed by a number) no longer matches any row by title, so its text stayed the template's. A title that matches no row now takes the row no other title reached, in the order the template is walked, as long as those rows are as many as the pages left.

- **The preview of a forum or an assignment no longer fails with a type error**  
  The helper that formats the description of an activity declared its parameter as `stdClass` without importing it, so PHP looked for a class of the plugin's own namespace and every activity preview that draws a description stopped with an exception.

## [2.0.10] - 2026100200

### Fixed
- **The preview of a lesson or a book no longer shows the template's own markers on a second page or chapter that shares a title with an earlier one**  
  The generated texts were laid over the template's rows by title, so two pages of a lesson (or two chapters of a book) with the same title both landed on the first row and the second one was drawn straight from the template. Each now takes its own row, in the order the template is walked.

## [2.0.10] - 2026100107

### Fixed
- **The left column was empty while a course made from a template was being generated**  
  Since the plan was removed, the left column only said that the template was being read and the activities were shown as spinners on the right, so the teacher could not tell what was being written. Each activity now appears in the left column as soon as its generation starts, with its name, type and section, in the same list free creation uses, and its spinner becomes a check when it is written. A counter in the heading shows how many are written out of how many there are, and the matching row on the right changes state at the same moment. Asking for changes starts a new list with only the activities named.

## [2.0.10] - 2026100106

### Changed
- **A course made from a template is generated as soon as the teacher clicks Generate**  
  The AI no longer writes a plan of every activity first and waits for it to be approved: the generation starts at once, the progress of each activity is shown while it is written, and the teacher then reviews the finished course. The review opens each generated activity in the preview, and offers two answers: approve the course, which goes straight to the name form and builds it from what was already generated with no further AI work, or ask for changes, either for the whole course or for one activity from its own row, which generates again only what was named and returns to the same review. The preview and change buttons of the rows, which the generation view hides, are shown again during the review.
- **The review answer of a template generation is now called `local_coursegen_template_review_feedback`**  
  It used to answer the plan review; it now answers the review of the generated course. The preview of an activity and of the course read the generated result only.

### Removed
- **The plan of every activity, with its lines under each row, its list in the left column and its preview over the template source**  
  Nothing needs them any more, so their screens, their strings in English and Spanish and their tests are gone.

## [2.0.10] - 2026100105

### Changed
- **A course made from a template is now named the way a free course is**  
  When the generation finishes, the teacher is shown the same form used at the end of a free generation, with the course name and short name proposed by the AI, and can change them, and the category, before the course exists. The course is created with what the teacher confirms, and the same final screen is shown, with the buttons to create another course and to open the new one. Before, the course was created as soon as the generation finished and took the name of the template's course. This must be released together with the matching change of the AI service, which proposes the name once the plan is approved and gives it to every generator as the course title, so the generated texts stop using the name of the template's course as the title.

## [2.0.10] - 2026100104

### Fixed
- **The links of the kept activities pointed at the template's course**  
  A kept activity is copied as it was written, so a label with buttons to the pages of the template's course sent the students of the new course to the template. Once the new course is built, the address of each activity that has a counterpart in it is changed to that counterpart: a kept activity to its copy, and a template source that produced a single activity to that activity. Labels, pages and lessons are read, and an address of any other activity is left as it is. A label that is generated from a template can now also carry links to other activities.
- **The activities of a course generated from a template were not in the order of the template**  
  The generated activities were created first and the copied kept ones were added after them, so the welcome label ended at the bottom of its section and each lesson was no longer followed by its forum. Each section of the new course now follows the order the template shows it in, and an activity the template does not account for stays after the ones it does.

## [2.0.10] - 2026100103

### Added
- **Links between the activities of a course generated from a template**  
  The AI service can now write a link to another activity of the course. Once every activity of the new course exists, the generated activities and the kept ones that were copied, each link is replaced by the address of the activity it names, in the content of the generated pages and in the pages of the generated lessons. A link is only replaced when it is the whole value of a link or image address, and any other text between dollar and at signs is left as it is. If a link names an activity that is not in the course, nothing is rewritten, the generation is marked as failed and the error names the activity and the link, so a broken link is never left in the content. This must be released together with the matching change of the AI service.

### Fixed
- **The virtual activities of a template were missing from the map of created activities**  
  The activities generated from a template source were not recorded under their identifier when the course was built, so nothing could be traced back to them. They are recorded now.

## [2.0.10] - 2026100102

### Changed
- The virtual activities of a template source are now sent to the AI service with the action `instance` instead of `modify`, and the course preview and the filter of the generated activities recognize that action. The `modify` action that regenerated a real activity no longer exists: the script of the template editor no longer defaults to it and a stored `modify` row is no longer shown to the professor. This must be released together with the matching change of the AI service.

## [2.0.10] - 2026100101

### Fixed
- The built script of the generated-course block reload was missing from the repository, so the course creation page could not load its scripts on a clean install; it is now included.

## [2.0.10] - 2026100100

### Added
- The course creation page opens on a start screen with two cards, free creation and from a template, and each card opens its own workspace. A bar at the top names the chosen path and leads back to the cards until planning starts, when it stays as the name of the path. Only users who can create in both ways see the cards and the bar.

### Removed
- The switch between free creation and from a template that sat inside the prompt box, which the start screen replaces.

## [2.0.10] - 2026093029

### Changed
- The preview of a course in the template editor and the configuration form of the editor ask for the capability of what the user is doing: to create a template when it is new and to edit it when it already exists, instead of accepting either one of them.

## [2.0.10] - 2026093028

### Changed
- The list of activity types the AI service can write content for lives in one small neutral class on the server, next to the other helpers of the plugin, and in one small neutral module in the template editor script, instead of in a constant of an interface and a hand-copied array. A test keeps the two lists equal.

### Removed
- The interface that only held that list, with no implementation and no user.
- The check that told whether a module name was one of the installed supported types, which nothing called.
- The helper and the base number of the old scheme that gave virtual instances a synthetic course module id, which nothing called either.

## [2.0.10] - 2026093026

### Changed
- The English strings of the space for the teacher say "teacher", the term Moodle uses for the role, instead of "professor": the action in the activity selector, its tip and the labels, descriptions and placeholder of the modal.

## [2.0.10] - 2026093024

### Changed
- The scope hint and the tooltip of each option of the template picker are computed with two independent conditions instead of conditions nested in one another. The options the picker shows are exactly the same.

## [2.0.10] - 2026093022

### Removed
- The `regenerate_detailed_item` external function. It was not declared as a web service, called a method of the AI client that no longer exists, and the AI service no longer has the endpoint it used; regenerating a part of the plan goes through the planning feedback.
- The unused `can_create_course` check of the chat hook. Nothing called it since the button to create a course with AI moved to the My courses page, which has its own check.

## [2.0.10] - 2026093021

### Added
- One capability per operation, replacing the broad ones. Course templates: `viewtemplates`, `createtemplates`, `edittemplates` and `deletetemplates`. System instructions: `viewsysteminstructions`, `createsysteminstructions`, `editsysteminstructions` and `deletesysteminstructions`. Image generation settings: `viewimagegenerationsettings` and `editimagegenerationsettings`. Creating a course with AI: `createfreecoursewithai` and `createtemplatecoursewithai`, one per mode, plus `uploadcoursesyllabus` and `generatecourseimages`. Creating an activity with AI keeps `createactivitywithai` and gains `generateactivityimages`.
- On upgrade every new capability copies the permission of the capability it comes from, in every role and every context where that one was set, including prevent and prohibit and the overrides in courses, categories and activities, so no role gains or loses anything. On a new install it starts with the same roles. `managetemplates`, `managesysteminstructions`, `manageimagegeneration` and `createcoursewithai` stay defined only as that source: no code checks them any more, so changing them after this upgrade has no effect, and they can be removed in a later release.

### Changed
- Every page and web service of those areas checks its own capability. Saving a template needs `createtemplates` for a new one and `edittemplates` for an existing one, nothing more, and the services the editor uses to build a template are open to who can create or edit. The services that create a course are checked by mode, the ones both modes use by either mode, and a user with neither mode gets the permission error on the course creation page.
- A request that asks for images needs `generatecourseimages` or `generateactivityimages`, and a template generation that carries a syllabus needs `uploadcoursesyllabus`; they are refused with a permission error instead of being ignored.
- The interface shows only what the user can do: the create, edit and delete controls of the template and system instruction lists, the mode switch (and only the mode the user can use), the syllabus controls, the generate-images controls of the course and activity windows. The image settings page is read-only without the save button for who can view but not change it. The creation page no longer sends the list of templates to a user who cannot use that mode.
- Creating a course and reading its final settings, answering its plan and resuming a session now check their capability; before, they only checked that the session belonged to the user.
- Saving the image generation settings keeps requiring the site configuration capability and also requires `editimagegenerationsettings`.

## [2.0.10] - 2026093015

### Changed
- The list of installed, AI-supported activity types is read straight from the supported list in one pass, instead of intersecting, sorting and re-indexing arrays: the supported list is already alphabetical, so the result is the same without the extra array calls.

### Added
- PHPUnit coverage that the supported list stays alphabetical, that the installed list has no repeats and that a hidden module is left out.

## [2.0.10] - 2026093014

### Changed
- The activity catalog of the template editor is sorted with Moodle's own collator instead of a byte comparison of the names, so the order follows the site language and an accented initial sits with the entries of its base letter. The two comparison helpers it needed are gone.

### Added
- PHPUnit coverage of the catalog the template editor receives: the types it lists, the shape of each entry, that it is a zero-indexed list and that it follows the collation order.

## [2.0.10] - 2026093013

### Changed
- The token of a section naming pattern that stands for the original section's name is now `{name}` instead of a word of another language, in the presets, the name-only option, the custom-pattern help and the live preview. `{N}` is unchanged and there is no support for both spellings at once. Stored patterns are not rewritten: a pattern saved with the old token keeps it as plain text, shown in the Custom field, and has to be edited by hand.
- The template editor scripts take the action, behaviour and scope names, the event names, the language string keys they share and the selectors and classes they share from two small constants modules instead of repeating them. The form now tells the script the custom-pattern value and both tokens, so the script holds no copy of them.
- Every selector the template editor scripts use now lives in one selectors module, and the scripts find their elements only through `data-action`, `data-region` and `data-form` hooks, never through ids or CSS classes. The hooks added with the spaces work and the naming preview are namespaced (`local_coursegen/template/...`); the ones that already existed keep their original attribute. The name and description fields, the extra sections and naming fields of the configuration form, the dropdown wrappers, the type cells and the icons of the rows carry the new hooks, and the modal ids are unique per render.
- The add menu items of the template editor are told apart by their own `data-action` instead of a value in `data-menu-action`, and the picked-template click no longer chains a promise.
- The loops of the naming preview, the virtual rows scrape and the rows of the sections review no longer keep a hand-maintained counter: the position comes from the loop itself or from the lists being built.
- The naming preview box is rendered from its own template instead of being built in the form class.
- The save payload, the persistence service and the row options no longer fall back to defaults that can never apply, and the action, behaviour, scope and row-kind names are constants of the classes that own them.

### Fixed
- The live naming preview built its lines as HTML from the section names of the base course, so a section called with markup would have been interpreted as markup in the page of the professor who edits the template. The preview is now rendered from a template that escapes the names, with its label coming from the language pack, and the space rows added in the editor print the activity icon through the template instead of a script extracting its address from markup.

## [2.0.10] - 2026093008

### Changed
- The rest of the template editor code follows the same code-quality rules as the spaces work: the row scripts, the structure rows for the professor, the row options, the layout ordering, the export and persistence services, the configuration form and the sections review no longer hide calls inside other calls' arguments, array literals or loop iterables, no longer use a ternary or an inline closure, and keep one non-nested loop per function. The layout ordering and the catalog sorting use small named comparison methods instead of inline closures.
- The tests of the template editor read every inner call into a named variable first and use small helpers instead of inline closures. Every assertion, data provider and fixture value is unchanged.
- The name validation of the save button collects its result through a named handler instead of a function defined inside another.

### Removed
- The summary step script of the old configuration wizard. Nothing in the plugin loads it any more.

## [2.0.10] - 2026093007

### Changed
- The limits step of the template editor (extra sections allowance, section naming pattern, first section number and the live naming preview) and the summary step are rewritten as small module-level functions that share one explicit context, with no function defined inside another and no nested or repeated loop. The functions these steps lost when the "Allowed activity types" block was removed were edited by that change, so they now follow the code-quality rules too. Behaviour is unchanged.
- The save payload reads the sections into a variable before building the request, and the save endpoint reads its parameter description and its saved name into variables first.

## [2.0.10] - 2026093006

### Changed
- The template editor scripts no longer define any function inside another function. Event handlers, callbacks, loop callbacks and promise executors are now named module-level functions that receive their state through an explicit context, or are bound to it, instead of closing over local variables. This covers the wizard start-up, the save payload, the row action and section behavior handlers, the add-from-template click and input handlers, the space modal, the space rows and the activity chooser.
- The callback chain of a row action change (scope modal, unmark confirmation, space modal) is now a flat sequence of named functions driven by a shared context. The action a row falls back to when a modal is cancelled is tracked in that context.
- Every loop over a list in those scripts is a plain `for...of` inside its own small function, and there is no nested or repeated loop left in any function the spaces work touched.
- The template layout and its tests build their row lists with a `foreach` instead of an inline closure. No behaviour changes.

## [2.0.10] - 2026093005

### Changed
- The code of the template editor and of the spaces for the professor now follows the project's code-quality rules that had been skipped: no loop inside another loop, one loop per function, no ternaries, no `switch`, no named function inside another function, no function call inside another call's arguments, an array or a loop's iterable, and no `??` fallback mixed into a larger expression. No behaviour changes.
- The template wizard state (`init.js`) seeds each section and each activity through their own small functions instead of a loop inside a loop. The rows, events, payload, menu, space modal and activity chooser modules were split the same way; the chooser now keeps its outcome in a small class instead of a function defined inside another.
- The section review (`sections_config`) reads the saved configuration through one helper per kind of record and builds each section and each real row through its own method, instead of a single method with five loops, one of them nested.
- The configuration form builds its limits and its naming fields in separate methods, and the pure pieces (extra sections default, naming options, naming defaults) are public and covered by tests.
- The tests of the editor and of the spaces read their inner calls into named variables first, and the spaces test got small helpers for the section id, the review render and the badge text.

### Fixed
- A test of the spaces (an activity marked as a space) called the fixture with one argument too few and would have stopped with an error instead of checking anything.

## [2.0.10] - 2026093004

### Fixed
- The section-naming presets of the template configuration (Unit, Module, Topic, Week) and the default pattern were written in Spanish whatever the site language, so an English site offered "Unidad 1 — …" and named the generated sections that way by default. The words now come from the language pack; the number and section-name tokens stay identical in every language, since they are what the course builder and the preview substitute (the section-name token is now `{name}`). A pattern saved in another language keeps working and is restored through the Custom option.
- The wizard no longer starts with a hardcoded naming pattern: its initial value comes from the server together with the rest of the page configuration.
- The placeholder of the custom naming pattern field, and the "Unidad" examples in the English help texts of the naming pattern and start number, are now proper language strings.

## [2.0.10] - 2026093003

### Added
- Spaces for the professor in the template editor, put back for review after the revert of build 2026093002. A space marks a place where the professor must, or may, provide an activity of their own when creating a course from the template. It is either a new entry (pick the activity type from Moodle's own activity chooser, limited to the types the plugin supports, then say whether it is required or optional and what the professor has to provide), or an existing activity set to the new "Space for the professor" action. A space is never copied into the new course nor written by the AI: it is left out of what is sent to the AI service. Making the professor provide it, and blocking the start while a required one is empty, comes in a later step.
- New table `local_coursegen_tpl_space` for the entries, and two fields on the template activity (`spacerequired`, `spaceinstruction`) for the marked activities. The upgrade step creates them only when they are missing, so it is safe on databases that already have them from build 2026093001.

### Changed
- The "+" between rows and the "Add" row at the end of each section open a menu with two options: add an activity from a template (the same list as before, with a Back item) or add a space for an activity.
- The professor's activity chooser offers every supported and installed activity type for every template.

### Removed
- The "Allowed activity types" setting of the template editor, with its "Select all" / "Select none" buttons and its summary line. The saved value is no longer read or written; the column stays so no schema change is needed. `save_template` no longer takes the `allowedtypes` parameter.

## [2.0.10] - 2026093002

### Reverted
- The spaces for the professor in the template editor (build 2026093001) were taken out until they are reviewed: the "add a space" menu and chooser, the "Space for the professor" action, the `local_coursegen_tpl_space` table and the two space fields on the template activity are no longer defined by the plugin, and the "Allowed activity types" setting of the template editor is back. Databases already upgraded to 2026093001 keep the table and the fields untouched; nothing reads them while this build is installed.

## [2.0.10] - 2026093001

### Added
- Spaces for the professor in the template editor. A space marks a place where the professor must, or may, provide an activity of their own when creating a course from the template. It is either a new entry (pick the activity type from Moodle's own activity chooser, limited to the types the plugin supports, then say whether it is required or optional and what the professor has to provide), or an existing activity set to the new "Space for the professor" action. A space is never copied into the new course nor written by the AI: it is left out of what is sent to the AI service. Making the professor provide it, and blocking the start while a required one is empty, comes in a later step.
- New table `local_coursegen_tpl_space` for the entries, and two fields on the template activity (`spacerequired`, `spaceinstruction`) for the marked activities. A space and a template-generated activity placed next to each other keep the order they were placed in.

### Changed
- The "+" between rows and the "Add" row at the end of each section now open a menu with two options: add an activity from a template (the same list as before, with a Back item) or add a space for an activity.
- The professor's activity chooser offers every supported and installed activity type for every template.

### Removed
- The "Allowed activity types" setting of the template editor, with its "Select all" / "Select none" buttons and its summary line. The saved value is no longer read or written; the column stays so no schema change is needed. `save_template` no longer takes the `allowedtypes` parameter.

## 2.0.10

**Released on:** 2026-09-30

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **A leftover image marker written with double brackets is now removed too**  
  When the AI service could not resolve an image, the marker it left behind was only cleaned up if it used the mathematical brackets, so a marker written with plain double brackets showed up as visible text in the generated activity. Both forms are now removed. Only markers that start with the image prefix are touched, so other double-bracket text, such as a wiki link, is left as it is.

## 2.0.9

**Released on:** 2026-09-29

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **A generated activity's rich text can carry over a file it points at**  
  A generated database, quiz, wiki, glossary or lesson can reference a file already stored on the site through its plain URL; that file is now copied into the new activity instead of being left as a broken link, provided the current user can manage activities in the course it comes from.

## Fixed

- **A generated course now explicitly opts into completion tracking**  
  A course created from a planning session left completion tracking at the database's own default rather than the site's default for new courses, so with the site default off, every completion setting on every generated activity was silently ignored.

- **A generated quiz question's tags no longer write before the permission to add them is checked**  
  Tags were attached to a newly created question one step before the capability check that can still reject adding it to the quiz, so the tag write could persist even when the operation was refused.

## 2.0.8

**Released on:** 2026-09-22

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **The plan is approved before the course is written**  
  A course generated from a template used to be read and written in one movement, so by the time the professor saw anything it had already been made. The run now stops once the plan is written and waits. The plan arrives a piece at a time, as each one is finished, and is shown as the text it will become rather than as a description of it, so what is approved is what will be received. The professor approves it or asks for an activity to be planned again. The structure itself is not offered for editing, because that is what the template fixes; what can be changed is the content that goes inside it.

- **Preview of every activity and of the whole course before either exists**  
  Every planned or kept activity can be opened in a preview, and the preview is drawn by that module's own rendering code, carried into the plugin and fed from the generation's data instead of the database, so a lesson, a book, a quiz or a forum look exactly as they will once created: a lesson is read one page at a time with its menu, a quiz shows its questions through Moodle's own question engine, a folder lists its real files. The whole course opens the same way and is drawn by the course's own format, so a template in grid format previews as a grid and a weekly course keeps its dates. Nothing in either preview can be used or changed, and nothing is created in Moodle to draw them: reviewing a plan is how the professor decides whether it is worth building, so building it first would answer the question by asking it.

## Changed

- **Every element of a generation is named by a uid**  
  The activities a template generates have no course module until they are created, so until now they travelled under an identifier derived from an internal record, first offset by a constant and then negated so it would not be mistaken for a real one. Each element now carries a uid of its own instead, which is what the preview links use.

- **The sidebar folds away and comes back on hover**  
  The sidebar's toggle and the way out of the page used to float over the content, one on each side, and the toggle covered the page title once the sidebar was folded. A thin bar across the top now holds both: the toggle at its left, which never moves, and the way back to My courses at its right. Folding the sidebar slides it out and the content over; hovering the toggle while it is folded shows the sidebar on top of the content, without moving anything, and moving away hides it again; a click pins it back. The choice is remembered, and the `[` key toggles it.

- **The way a course starts is chosen in the composer**  
  Free creation or from a template used to be two tabs at the top of the sidebar, always on show, naming a mode for a course that might not be about to start. The choice now sits inside the composer, next to the "+" menu, as a small two-way switch: it is read where the writing happens, the page never presumes a mode, and once a plan exists the switch is gone because the mode is settled. "New course" simply opens a clean page.

## Fixed

- **Only the activities marked to be rewritten are rewritten**  
  Which activities a run created was decided from the shape of their identifier rather than from the decision saved for each one, so the result depended on how identifiers happened to be numbered.

## 2.0.7

**Released on:** 2026-09-15

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Live view of a course being generated from a template**  
  Generating a course from a template previously disabled the button and then asked the service every few seconds whether it had finished, so several minutes could pass with nothing to look at. The generation is now watched as it happens, and it looks exactly like course creation without a template already does: the activities still waiting are shown dimmed, the one being generated spins on its own icon, and each is marked as it lands. Because activities are generated at the same time and finish in whatever order they finish, each one updates its own row rather than the next one in the list. While the run is in progress the structure becomes read-only, the instruction written by the professor is kept on screen, and a single live message names the phase the generation is in.

## 2.0.6

**Released on:** 2026-09-15

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **Activity molds inside a template**  
  An activity in a template can now be marked as a mold: it is never copied into the generated course, but its structure is what the AI fills in. Text written between the reserved markers is replaced with real content taken from the reference document, and a marked repeating block is expanded as many times as that document calls for. The mold's own configuration, its page order and each page's navigation buttons are preserved on every activity generated from it.

- **Course generation from a template for the professor**  
  The professor picks a template, attaches a reference document such as a syllabus, writes a single general instruction and chooses Generate. The plugin then creates the course: activities the template keeps are copied from the base course with their configuration and files intact, activities based on a mold are generated once per instance the template declares, and excluded items are left out. Progress is followed until the finished course is ready to open.

## Changed

- **Clearer names for two template settings**  
  The section behaviour previously stored as "custom" is now "aimodify", and an instance's anchor is now stored as "aftercmid". Existing templates are migrated automatically.

## Fixed

- **Separator between the last activity and the add-activity row**  
  In the template editor the add-activity row sat flush against the activity above it, with nothing marking where one ended and the other began.

## 2.0.5

**Released on:** 2026-08-26

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **Course generation from a template**  
  Choosing "Generate" in template mode now creates a real course: sections and activities marked to keep are copied from the base course as-is, activities marked to regenerate are rebuilt with AI-generated content using the template's per-activity instructions and any designated reference material, excluded items are left out, and activities the professor added manually are created alongside them. Activity types and section counts are enforced against what the template allows.

## 2.0.4

**Released on:** 2026-08-26

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **Course template picker on the AI course creation page**  
  Selecting "Create course from template" now starts with a single native, searchable field to pick the template — no other fields are shown until a template is chosen. Once selected, the template's sections and activities render inline, with locked items clearly marked and support for adding new activities at any point in the list, even next to locked ones.

## 2.0.3

**Released on:** 2026-08-24

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **Spanish translation corrected across the language file**  
  Several activity names and UI strings used incorrect literal translations, inconsistent terminology for the same activity type across different strings, and grammar issues. Activity names are now aligned with the site's installed Spanish language pack.

## 2.0.2

**Released on:** 2026-08-20

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **Workshop settings handle the initial phase and AI-generated criteria**  
  Workshop activities generated with AI now correctly set the initial submission phase and apply AI-generated assessment criteria, covered by a new test suite.

## 2.0.1

**Released on:** 2026-08-19

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **Site H5P framework version sent to the AI service**  
  New `h5p_core_api` helper resolves the site's H5P core API version and attaches it as `h5p_core_api` to both the single-activity and full-course payloads, so the service packages generated `.h5p` files with a library set compatible with the site's H5P framework. When unresolvable the field is omitted and the service falls back to its most-compatible set.
- **H5P activity settings applied from the AI result**  
  New `h5pactivity_settings` class maps the AI's `passing_score` to the activity's pass grade (`gradepass`) idempotently and emits debugging for unconsumed settings keys.
- **H5P activities properly labeled in the course plan UI**  
  Plan cards now show "H5P interactive content" with its own icon instead of a raw "H5pactivity" label with no icon.
- **Automated test coverage for the H5P generation flow**  
  New PHPUnit suites covering the creation contract, permissions, download configuration and H5P settings, plus testable fixtures and three Behat features for the activity AI modal, activity generation and course generation flows.

## Changed

- **API client construction centralized**  
  New `api_client_factory` builds the provider HTTP client honoring the configured service URL overrides, with a PHPUnit-only injection seam, replacing scattered direct instantiations.

## Fixed

- **Enrolled users could launch paid AI generation jobs**  
  `create_mod_stream`, `create_mod` and the activity feedback, file upload and filepicker endpoints now require `moodle/course:manageactivities` and `local/coursegen:createactivitywithai` on the course, matching the UI entry point. Previously being enrolled was enough to start jobs that consume service credits via direct AJAX calls.
- **Disabled image policy no longer overrides the teacher's image toggle**  
  A disabled (or never configured) site image policy is now omitted from the activity payload instead of being sent, where it suppressed the activity description image even when the teacher enabled images. Guarded by a dedicated regression test.
- **Generated package download hardened**  
  `file_path`/`file_name` from the AI response are sanitized before download (also for SCORM, IMS CP and resource packages), missing package fields are validated with a clear error, the downloaded `.h5p` package is validated before the activity is created, and a failed download produces a clear error without leaving residue — the full-course flow continues with the remaining activities.
- **Unknown or disabled module types rejected with clear errors**  
  An AI result naming a module type that is not installed or is disabled on the site is now rejected with a translated error message instead of failing silently, and the parameter-class resolver emits a diagnostic when no handler matches. New lang strings: `error_invalid_package`, `error_missing_package_info`, `error_module_disabled`.
- **Failed generation can be retried**  
  Initialization failures are now retriable from the chat UI: when no session exists yet, the retry replays the original prompt from scratch.
- **Plugin CI runs the PHPUnit suite against its provider dependency**  
  The CI workflow now installs `aiprovider_datacurso` (the declared hard dependency) before running the test suite, and the codebase was cleaned to pass Moodle CodeSniffer with zero errors.
- **Version bump**  
  Internal version bumped to **2026081801** and release bumped to **2.0.1**.

## 2.0.0

**Released on:** 2026-08-14

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added

- **Database activities are now fully built by the AI**  
  New `data_settings` mod settings class creates the AI-generated database fields through mod_data's native field API, seeds the AI example entries, applies the AI-designed display templates, and sorts the activity by its primary field. Field creation is hardened against per-field failures, only choice values matching the defined options are stored, and non-numeric example values for number fields are skipped.
- **Folder activities ingest AI-generated documents**  
  New `folder_parameters` class downloads every AI-generated document into a single draft area — each at its planned subfolder path — and points the folder's `files` parameter at it, so the standard module creation places every file and its nested folders into the folder's content. When no files were produced the folder is simply created empty.

## Changed

- **Image policy builder extracted into its own domain class**  
  The site-wide image policy assembly moved from `course_planning_service` into the new shared `image_policy_builder` class under `classes/local/image_generation/`, so the full-course flow and the single-activity flow build the exact same `image_policy` payload from a single place.
- **Minimum required version of `aiprovider_datacurso` raised to 2026081000**  
  The image policy payload sent by this release requires the matching provider support. This breaking dependency requirement is the reason for the major version bump.
- **Version bump**  
  Internal version bumped to **2026081800** and release bumped to **2.0.0**.

## Fixed

- **Calculated question datasets validated before any write**  
  Calculated question datasets are now validated before anything is written, so a question with invalid datasets is skipped cleanly even inside an outer transaction instead of leaving partial data behind.
- **Silent discard of unhandled module settings**  
  A `mod_settings` payload arriving for a module type with no handler class now emits a debugging warning instead of being silently ignored.

## 1.7.3

**Released on:** 2026-08-13

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **Global image settings were ignored in the single-activity flow**  
  When creating a single activity with "Create with AI" and the image generation option enabled, the payload sent to the AI service included only the image toggle (`with_images`) and never the site-wide image generation policy, so the global settings (generation mode, per-activity enables, and per-part image caps) had no effect on standalone activities. The activity payload now includes the same `image_policy` object the full-course flow already sends, built by the shared `image_policy_builder::build()`. Requires the matching AI service change; older services ignore the field.
- **Version bump**  
  Internal version bumped to **2026081300** and release bumped to **1.7.3**.

## 1.7.2

**Released on:** 2026-08-12

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **AI-generated rubric was silently discarded**  
  When the user asked for an assignment graded with a rubric, the AI service generated the full rubric and sent it in `mod_settings.rubric`, but the plugin had no `assign_settings` class, so the rubric was never created and the assignment was left on the rubric grading method with no definition ("rubric not defined"). A new `assign_settings` mod settings class now creates the rubric definition through Moodle's advanced grading API (`gradingform_rubric`), marks it ready, and activates the rubric method only after the definition exists — if creation fails, the assignment degrades to simple direct grading instead of becoming ungradeable.
- **Version bump**  
  Internal version bumped to **2026081202** and release bumped to **1.7.2**.

## 1.7.1

**Released on:** 2026-08-12

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Fixed

- **File-type catalog was missing from the full-course flow**  
  The site file-type group catalog (`filetype_groups`) introduced in 1.7.0 was only attached to the standalone activity payload (`/activity/init`), so assignments generated inside a full course could not restrict accepted file types against the site's real groups. The catalog builder now lives in a shared `filetype_catalog_service` and is attached to the course planning payload as well. Requires the matching AI service change; older services ignore the field.
- **Version bump**  
  Internal version bumped to **2026081201** and release bumped to **1.7.1**.

## 1.7.0

**Released on:** 2026-08-12

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Site file-type catalog sent to the AI service**  
  The activity generation payload (`/activity/init`) now includes `filetype_groups`: the site's real file-type group catalog (group key and its extensions) built from `\core_form\filetypes_util::get_groups_info()`, custom file types included. This lets the AI service infer the accepted file types for an assignment from the described deliverable (e.g. "upload a short video" restricts submissions to the `video` group) and validate the generated value against groups that actually exist on the site, instead of assuming the stock Moodle catalog. If the catalog cannot be resolved, the field is omitted and the service falls back to the standard Moodle groups. Requires the matching AI service change to take effect; older services ignore the field.
- **Version bump**  
  Internal version bumped to **2026081200** and release bumped to **1.7.0**.

## 1.6.0

**Released on:** 2026-06-02

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Course review panel before creation**  
  Added a review step in the streaming UI that shows AI-generated course settings (fullname, shortname, category) before the course is created. Users can override any value. The category selector uses Moodle's `form-autocomplete` with full path display (e.g. "Miscellaneous / My Subcategory").
- **New `get_course_settings` webservice**  
  Added `local_coursegen_get_course_settings` (read, ajax) that returns the AI-generated course fullname, shortname, category, and the full category list with paths — all from the server via `core_course_category::make_categories_list()`.
- **Create course accepts overrides**  
  The `local_coursegen_create_course` webservice now accepts optional `fullname`, `shortname`, and `category` parameters. When provided, they override the AI-generated values.
- **Version bump**  
  Internal version bumped to **2026081101** and release bumped to **1.6.0**.

## Fixed

- **Transcript and plan card described the same activity differently**  
  The plan card showed the detailed description an activity was planned with — what the student submits, the instructions, the criteria — while the transcript beside it showed only the one-line summary written before the activity was detailed. Both now show the detailed one, falling back to the summary for an activity that has not been detailed yet.
- **Markdown shown raw in the plan cards**  
  Activity descriptions, and the chapter and question lines inside an expanded activity, were written into the page as plain text. Anything the model emphasised therefore arrived with its asterisks visible, for example `**[assign] Digital Culture Case Study Analysis**: Students will research…`. All three now render through the bundled `marked`, inline so the markup nests correctly inside the paragraph it already sits in.
- **Rendered plan text is sanitised with DOMPurify**  
  The HTML produced from the model's Markdown was cleaned with regular expressions, which removed dangerous tags and inline event handlers but let a `javascript:` link through. DOMPurify is now bundled as an AMD module (`local_coursegen/purify`) alongside `marked` and cleans against an allow-list, so a link can only carry a safe URL. Moodle ships no sanitiser for JavaScript; the one in `theme_boost` is a theme's private copy of Bootstrap's internals, differs between the themes on a site, and still uses Bootstrap 4's parameter name.
- **Chat transcript rebuilt out of order after a page reload**  
  On reload the conversation was rebuilt by replaying only the user's messages and appending a single closing assistant line. Every instruction the user sent after the plan was therefore printed above the planned-structure card instead of below it, and the assistant's intermediate turns were missing entirely. The transcript is now rebuilt round by round in checkpoint order, closing each answered round with the same message the live stream used, and the feed switches to the post-plan container as soon as the plan card is rebuilt so later turns keep their place.
- **Conversation transcript lost on reload**  
  Reloading rebuilt the conversation from the free text of the user's messages alone, so every turn that depended on what an action actually did came back wrong or not at all: "You applied: move «Basics» after «Advanced»", "You added activity: …", the regenerated subtree of a replan, and the assistant's own turns. The service now records the transcript turn by turn and the plugin replays it, so a reload shows what was on screen. Sessions started before the service records it fall back to the previous rebuild. Requires the matching AI service change.
- **Pending proposals lost on reload**  
  A session paused on a set of proposals (for example a reordering the assistant offered) came back from a reload with the options gone, because the snapshot never carried them. The pending review payload is now read from the session state and the proposals card is re-rendered, so the choice the user was asked to make is still there. Requires the matching AI service change.
- **AI course creation menu entry no longer shown to users who cannot use it**  
  **Site administration > Courses > Create a new course with AI** declared only `local/coursegen:createcoursewithai`, while the page itself requires that capability *and* `moodle/course:create`. Because `admin_externalpage` treats its capability list as OR, a user holding only the plugin capability was shown the entry and then denied access on click. The entry is now registered only when both capabilities are held in the system context.

## 1.5.0

**Released on:** 2026-06-01

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Course creation data now comes from API response**  
  The `create_course` external function is now a thin controller that only validates params and loads the session. All business logic (coursedata parsing, category resolution, course data building) moved to `create_course_service`. Course data is built entirely from the API's `course_configuration` response instead of the session's stored coursedata.
- **Removed restrictive shortname sanitization**  
  Removed `sanitize_shortname_keyword()` which limited shortnames to alphanumeric+hyphens. Shortnames from the API are used directly (trimmed, truncated to 100 chars).
- **Removed `course_identity` references**  
  Renamed `apply_course_identity_to_coursedata` to `build_course_data_from_api` and updated all references from `course_identity` to `course_configuration`.
- **Session coursedata no longer includes course fields**  
  Removed `category`, `fullname`, and `shortname` from the session's `coursedata` payload in `start_course_planning` — these come from the API at creation time.
- **Planning UI spinner-to-checkmark transition**  
  Added `finalizePlanView` to transition the planning loading spinner to a done/checkmark state when streaming completes.
- **Planning overlay hidden on review state**  
  Fixed a bug where the centered planning-loading overlay remained visible during detailed planning review.
- **Version bump**  
  Internal version bumped to **2026060101** and release bumped to **1.5.0**.

## 1.4.0

**Released on:** 2026-05-14

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Changed

- **Refactored webservice layer: thin controllers + service classes**  
  Renamed `wizard_init` to `start_course_planning`, extracting all business logic into `course_planning_service`. The external function now acts as a thin controller that validates params and delegates to services.
- **Full frontend rename: `wizard` → `courseai`**  
  Renamed all AMD modules, Mustache template, CSS selectors, DOM IDs, and JS function/variable names from `wizard` to `courseai` for naming consistency across the codebase.
- **Language string keys renamed**  
  All `wizard_*` language string keys renamed to `courseai_*` across all 7 supported locales.
- **Removed hard-stop for unconfigured API URLs**  
  Removed validation that blocked Generate when `datacurso_service_url` settings were empty, allowing the API client to use its default fallback.
- **New light sidebar for course navigation**  
  Added a light/minimalist sidebar overlay panel that includes:
  - Logo-branded trigger in the navbar (hover reveals menu icon)
  - "New course" shortcut button
  - "Recent" section showing the last 5 in-progress sessions
  - "View all courses" button that switches to a paginated sessions grid (10/page) inside the wizard
  - Backdrop overlay when open, click to close
  - Sidebar slides over the entire page including the navbar (z-index 1040)
  - Separate `sidebar.css` for maintainability
  - New `get_user_inprogress_sessions()` method on `course_session_service`
  - Sidebar closes automatically on any navigation click
- **Version bump**  
  Internal version bumped to **2026051406** and release bumped to **1.4.0**.
- **Refined chat UI during planning/streaming**  
  Full-height sticky left panel with initial prompt shown as a chat history bubble. Compact chat controls are now icon-focused to prevent horizontal scroll. Borders removed from textarea, chat card, toolbar, and message bubble for a cleaner look. A light dividing line separates the chat panel from stream content.
- **Fixed stream content scroll**  
  Removed `max-height: 380px` constraint and `overflow: hidden` on `pc-details-panel` and review cards so the right column scrolls as one unit, no longer clipping stream output.
- **Removed floating sidebar toggle**  
  Removed the absolute-positioned toggle button that caused horizontal page scroll. Sidebar remains accessible via navbar trigger only.

## 1.3.3

**Released on:** 2026-04-10

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Automated prompt-based course creation service**
  Added a dedicated backend automation service to orchestrate end-to-end course creation from prompt context, including planning/execution/result stages and user enrolment when applicable.
- **Centralized result application service**
  Added a dedicated result service to apply remote AI course results in a structured and reusable way.

## Changed
- **More resilient remote automation flow**
  Improved planning stream handling, execute retries, and result polling to better tolerate transient backend/network issues during automated creation.
- **Completion enforcement support in module creation flow**
  Extended module manager parameter handling to support manual completion enforcement during internal automation paths.
- **Version bump**
  Internal version bumped to **2026041000** and release version bumped to **1.3.3**.

## Fixed
- **Static analysis and coding-style compliance**
  Updated PHPDoc parameter annotations and long-line formatting in automation/privacy files to satisfy CI checks (PHPDoc Checker and Codechecker).
- **Language pack consistency cleanup**
  Normalized language files formatting for repository consistency.

## 1.3.2

**Released on:** 2026-01-26

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Optional admin settings for DataCurso service URLs**  
  Added admin settings to optionally override the default DataCurso service base URLs for the **standard** service and the **EU-hosted** service.
- **Translations for service URL settings**  
  Added language strings for `datacurso_service_url` and `datacurso_service_url_eu` across supported locales.
- **CHANGES.md for version history**  
  Added a new **CHANGES.md** file to maintain a clear, versioned history of releases and changes.

## Changed
- **AI API client respects configured service URLs when provided**  
  Updated `ai_course_api` initialization to use the configured DataCurso service URLs when available, falling back to defaults otherwise.
- **Version bump**  
  Internal version bumped to **2026012300** and release version bumped to **1.3.2**.


## 1.3.1

**Released on:** 2025-12-16

**Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **AI response language selector on the course form**  
  Added a new **AI response language** field to the course generation form (autocomplete from Moodle’s language list), with a help button and a sensible default based on the current user language.
- **Per-course persistence of the selected language**  
  The selected language is stored in the course context record so it can be reused across AI interactions (planning, messaging, and execution).
- **Translations for the language selector**  
  Added language strings across supported locales for the language selector on course form.

## Changed
- **AI request payloads now include `lang` when available**  
  Course planning, message, and execute requests now send the selected language code so the backend can return AI output in the configured language.
- **Course context save flow extended**  
  Updated context saving to persist the selected language alongside context type, system instruction, and prompt/syllabus data.
- **Documentation updated**  
  Updated the README to document the new **AI response language** control in the Datacurso section.
- **Version bump**  
  Internal version bumped to **2025121601** and release version bumped to **1.3.1**.

## 1.3.0

**Released on:** 2025-12-11

 **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Optional image generation support for AI course planning**  
  Added a new course form setting to optionally enable AI image generation for planned courses. The option is disabled by default and, when enabled, is passed as a boolean flag to the course planning API.
- **Translations for image generation controls on the course form**  
  Introduced language strings for the new image generation setting so the course form remains fully localized.

## Changed
- **Course planning API payload extended**  
  The course planning request now includes an `image generation` flag, allowing the backend AI planning service to respect the course-level configuration.
- **Documentation and configuration examples updated**  
  Updated the README to document how to configure and use the new image generation option on the course form.
- **Version bump**  
  Internal version bumped to **2025121100** and release version bumped to **1.3.0**.

## 1.2.1

**Released on:** 2025-12-09

  **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.
 
 ## Fixed
 
  - Fixes an issue where the AI course-creation modal didn’t appear because course view URL validation was too strict.  
  - The previous logic required an exact path match to `/course/view.php`, which failed on subdirectory installs like `https://mysite.com/mymoodle/`.  
  - Updated the detection to use a substring check with `strpos()` for `/course/view.php`, so URL variations and extra path components are handled correctly.

## 1.2.0

**Released on:** 2025-12-05

 **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Optional system instruction support**
  System instructions can now be enabled via a checkbox as an optional complement to other context types, with conditional validation and selection when enabled.
- **Improved navigation for system instruction editing**
  Breadcrumbs/navigation were enhanced to make editing system instructions clearer.

## Changed
- **Terminology and entity rename: “model” → “system instruction”**
  Renamed classes, form fields, parameters, context type constants, DB table references, and API endpoints to use “system instruction” terminology across the codebase.
- **System instruction workflow integrated into context flow**
  System instructions are no longer a standalone context type; they’re integrated as an optional step after choosing a context type.
- **Form UX reordered**
  Reordered fields to: context type selector → custom prompt → syllabus upload → system instruction checkbox/selector.
- **Course planning API call updated**
  Simplified course planning to use the v2 API
- **Version bump**
  Internal version bumped to **2025120500** and release bumped to **1.2.0**.
- **Documentation and translations refreshed**
  Updated README, images, and language strings to match the new system instruction terminology and flow.

## Fixed
- **Help text improved**
  Clarified help text for the custom prompt textarea.
- **Coding standards cleanup**
  Addressed PHPCS line-length and spacing issues.
- **Privacy provider tests aligned**
  Updated privacy provider tests to reference the renamed system instruction table.

## 1.0.3

**Released on:** 2025-12-02

 **Compatibility note:** This version is compatible **from Moodle 4.5 to Moodle 5.1**.

## Added
- **Automated release workflow for the plugin.**  
  A new GitHub Actions workflow was added to streamline/automate Moodle plugin releases.
- **Support from moodle 4.5 to 5.1**  
  Added `$plugin->supported` in `version.php` to declare Moodle compatibility from 4.5 to 5.1

## Changed
- **Release bump to 1.0.3**  
  The plugin release number was updated to **1.0.3**.

