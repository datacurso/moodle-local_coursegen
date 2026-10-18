# Flujo "Importar el curso generado por IA a un curso existente" (ticket #9539)

Recorrido paso a paso del flujo de importación: qué archivos intervienen en cada paso y qué código lo ejecuta. Todos los bloques de código se copian literalmente del archivo indicado (ruta y rango de líneas relativos a la raíz del plugin `local/coursegen`).

## Qué hace y por qué existe el staging

Con el ajuste de administrador activado, el panel de confirmación permite elegir entre crear un curso nuevo o importar el contenido generado a un curso existente. En modo importación el plugin **no** genera el contenido directamente sobre el curso destino: materializa el resultado de la IA en un curso de **staging** (en la categoría del destino) y lanza la importación (en una tarea adhoc por defecto o, con el ajuste `importexecution` en `sync`, dentro de la misma petición; ver paso 4.1.1), que copia el destino **con todos sus datos de usuario** (matriculaciones, roles, grupos, calificaciones, finalización y datos de usuario de las actividades), vuelca el staging sobre la copia (reemplazando o añadiendo contenido según el ajuste `importcontentmode`) y borra el staging. El original queda renombrado y oculto como copia de seguridad. La razón es que `create_course_service::process_course_sections()` llama a `delete_course_sections()` de forma incondicional: generar directamente sobre un curso existente lo vaciaría antes de escribir el contenido nuevo.

`classes/local/service/create_course_service.php:374-382`

```php
    private static function process_course_sections(int $courseid, array $sectionsinfo): void {
        global $DB;

        // Get course format to handle sections properly.
        $course = get_course($courseid);
        $courseformat = course_get_format($course);

        // Delete all existing sections except section 0 (general section).
        self::delete_course_sections($courseid);
```

`classes/local/service/create_course_service.php:333-365`

```php
    private static function delete_course_sections(int $courseid): void {
        global $DB;

        // Get course object.
        $course = get_course($courseid);

        // First, clear all modules from section 0 (general section).
        $modinfo = get_fast_modinfo($course);
        $section0 = $modinfo->get_section_info(0);

        if ($section0 && !empty($section0->sequence)) {
            // Get all course modules in section 0.
            $cms = $modinfo->get_cms();
            foreach ($cms as $cm) {
                if ($cm->sectionnum == 0) {
                    course_delete_module($cm->id);
                }
            }
        }

        // Get all sections except section 0.
        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0',
            [$courseid],
            'section DESC'
        );

        foreach ($sections as $section) {
            // Use Moodle's core function to delete section safely.
            course_delete_section($course, $section->section);
        }
    }
```

---

## 0. El ajuste de administrador (y cómo llega a la plantilla y al JS)

El flujo está detrás de un checkbox `local_coursegen/enableimportexisting`, desactivado por defecto. La página `aicoursecreation.php` lo lee con `get_config` y lo pasa al contexto de la plantilla como `importexistingenabled`. La plantilla lo usa dos veces: para renderizar (o no) los controles del panel y para dejar el valor en el atributo `data-import-existing-enabled` de `#courseReviewPanel`, que es lo que lee el JS. El ajuste se vuelve a comprobar en el servidor en los dos web services (ver pasos 2 y 3).

`settings.php:56-63`

```php
    // Off by default: importing into an existing course copies and replaces
    // the target, so the site must opt in explicitly (ticket #9539).
    $settings->add(new admin_setting_configcheckbox(
        'local_coursegen/enableimportexisting',
        get_string('setting_enableimportexisting', 'local_coursegen'),
        get_string('setting_enableimportexisting_desc', 'local_coursegen'),
        0
    ));
```

### 0.1 El modo de importación (`importcontentmode`)

Un segundo ajuste, el select `local_coursegen/importcontentmode`, decide qué pasa con el contenido de la copia al volcar el staging: `replace` (por defecto) lo borra antes de importar; `add` lo conserva intacto y **añade después** las secciones generadas, cada una con su nombre y sus actividades (nunca se mezclan con las existentes; las cadenas `importcontentmode_add` y `setting_importcontentmode_desc` lo describen así). Solo tiene sentido con la importación activada, así que se oculta con `hide_if` cuando `enableimportexisting` está desmarcado. No llega ni a la plantilla ni al JS: lo lee únicamente el servicio de importación al elegir el target del restore (ver paso 4.7).

`settings.php:65-78`

```php
    // Only meaningful while the import is enabled: "replace" deletes the copy
    // content before importing the generated course, "add" keeps the existing
    // sections and activities and merges the generated ones on top.
    $settings->add(new admin_setting_configselect(
        'local_coursegen/importcontentmode',
        get_string('setting_importcontentmode', 'local_coursegen'),
        get_string('setting_importcontentmode_desc', 'local_coursegen'),
        'replace',
        [
            'replace' => get_string('importcontentmode_replace', 'local_coursegen'),
            'add' => get_string('importcontentmode_add', 'local_coursegen'),
        ]
    ));
    $settings->hide_if('local_coursegen/importcontentmode', 'local_coursegen/enableimportexisting', 'notchecked');
```

`aicoursecreation.php:119-133`

```php
// Import-into-existing-course controls render only when the site opted in (ticket #9539).
$importexistingenabled = !empty(get_config('local_coursegen', 'enableimportexisting'));

// Prepare template context.
$templatecontext = [
    'guidelines' => json_encode($systeminstructions),
    'languages' => json_encode($languageoptions),
    'defaultlang' => current_language(),
    'logourl' => $logourl->out(),
    'hassessions' => !empty($recent5),
    'sessions' => $recent5,
    'allsessions' => $allsessionsdata,
    'isresuming' => $resumesessionid > 0,
    'subsectionsenabled' => $subsectionsenabled,
    'importexistingenabled' => $importexistingenabled,
```

`templates/courseai_page.mustache:665-667`

```mustache
      {{! Review step — Bootstrap card shown inline before course creation }}
      <div class="card" id="courseReviewPanel" style="display:none"
           data-import-existing-enabled="{{#importexistingenabled}}1{{/importexistingenabled}}{{^importexistingenabled}}0{{/importexistingenabled}}">
```

`amd/src/local/courseai/actions/course-create.js:91-94`

```javascript
const setupImportControls = (panel, confirmBtn, texts, FormAutocomplete) => {
    if (panel.dataset.importExistingEnabled !== '1') {
        return null;
    }
```

---

## 1. El panel de confirmación: selector de modo y campos

Dentro de `#courseReviewPanel`, y solo si `importexistingenabled` es verdadero, la plantilla añade un `<select id="targetMode">` (opciones `new`, seleccionada por defecto, e `import`) como primer campo del panel, encima de `#reviewFullname`, y un bloque oculto `#importTargetFields` con los selects `#importCategory` e `#importCourse`. Los tres campos del curso nuevo (`#reviewFullname`, `#reviewShortname`, `#reviewCategory`) van envueltos en `#reviewFullnameGroup`, `#reviewShortnameGroup` y `#reviewCategoryGroup`. En el JS, `setupImportControls` engancha el `change` del select a `applyMode`: en modo `import` muestra los campos de destino y oculta los tres grupos (nombre completo, nombre corto y categoría: la copia conserva los del destino); `syncConfirm` deshabilita el botón de confirmar hasta que haya un curso destino elegido. El controlador se construye una sola vez (`importControls`) y se reinicia en cada apertura del panel con `reset`, que devuelve el select a `new`.

`templates/courseai_page.mustache:683-692`

```mustache
          {{#importexistingenabled}}
          {{! Destination of the generated content: a new course (default) or an existing one (ticket #9539). }}
          <div class="form-group" id="targetModeGroup">
            <label for="targetMode">{{#str}}courseai_targetmode_label, local_coursegen{{/str}}</label>
            <select id="targetMode" class="form-control">
              <option value="new" selected>{{#str}}courseai_targetmode_new, local_coursegen{{/str}}</option>
              <option value="import">{{#str}}courseai_targetmode_import, local_coursegen{{/str}}</option>
            </select>
          </div>
          {{/importexistingenabled}}
```

`templates/courseai_page.mustache:694-698`

```mustache
          <div class="form-group" id="reviewFullnameGroup">
            <label for="reviewFullname">{{#str}}courseai_review_fullname_label, local_coursegen{{/str}}</label>
            <input type="text" id="reviewFullname" class="form-control" maxlength="255"
                   placeholder="{{#str}}courseai_review_fullname_placeholder, local_coursegen{{/str}}">
          </div>
```

`templates/courseai_page.mustache:712-726`

```mustache
          {{#importexistingenabled}}
          {{! Target of the import: category first, then a course of that category (AJAX autocomplete). }}
          <div id="importTargetFields" style="display:none">
            <h6 class="mb-1">{{#str}}courseai_import_title, local_coursegen{{/str}}</h6>
            <p class="text-muted mb-3" style="font-size:.875rem">{{#str}}courseai_import_help, local_coursegen{{/str}}</p>
            <div class="form-group" id="importCategoryGroup">
              <label for="importCategory">{{#str}}courseai_import_category_label, local_coursegen{{/str}}</label>
              <select id="importCategory" class="form-control"></select>
            </div>
            <div class="form-group mb-0" id="importCourseGroup">
              <label for="importCourse">{{#str}}courseai_import_course_label, local_coursegen{{/str}}</label>
              <select id="importCourse" class="form-control"></select>
            </div>
          </div>
          {{/importexistingenabled}}
```

`amd/src/local/courseai/actions/course-create.js:95-120`

```javascript
    const modeSelect = document.getElementById('targetMode');
    const targetFields = document.getElementById('importTargetFields');
    const categorySelect = document.getElementById('importCategory');
    const courseGroup = document.getElementById('importCourseGroup');
    const fullnameGroup = document.getElementById('reviewFullnameGroup');
    const shortnameGroup = document.getElementById('reviewShortnameGroup');
    const newCategoryGroup = document.getElementById('reviewCategoryGroup');
    if (!modeSelect || !targetFields || !categorySelect || !courseGroup) {
        return null;
    }

    // core/form-autocomplete does not re-read the underlying select once enhanced, so
    // the course field is rebuilt from its pristine markup (and re-enhanced) whenever
    // the category changes: the previous selection no longer belongs to the category.
    const courseFieldHtml = courseGroup.innerHTML;
    let courseSelect = null;
    let categoryEnhanced = false;

    const getMode = () => (modeSelect.value === 'import' ? 'import' : 'new');
    const getTargetCourseId = () => {
        const id = parseInt(courseSelect?.value || '', 10);
        return id > 0 ? id : 0;
    };
    const syncConfirm = () => {
        confirmBtn.disabled = getMode() === 'import' && getTargetCourseId() === 0;
    };
```

`amd/src/local/courseai/actions/course-create.js:137-152`

```javascript
    const applyMode = () => {
        const importing = getMode() === 'import';
        targetFields.style.display = importing ? '' : 'none';
        // The copy keeps the target's names and category: those fields are for new courses.
        if (fullnameGroup) { fullnameGroup.style.display = importing ? 'none' : ''; }
        if (shortnameGroup) { shortnameGroup.style.display = importing ? 'none' : ''; }
        if (newCategoryGroup) { newCategoryGroup.style.display = importing ? 'none' : ''; }
        syncConfirm();
    };

    modeSelect.addEventListener('change', applyMode);
    categorySelect.addEventListener('change', () => {
        buildCourseField();
        syncConfirm();
    });
    buildCourseField();
```

`amd/src/local/courseai/actions/course-create.js:233-242`

```javascript
    if (!importControls) {
        importControls = setupImportControls(panel, confirmBtn, texts, FormAutocomplete);
    }
    if (importControls) {
        // Same category list as the new-course field: already filtered by moodle/course:create,
        // which is exactly the permission the target's category needs.
        importControls.reset(categories);
    } else {
        confirmBtn.disabled = false;
    }
```

---

## 2. Los autocompletes: categoría (lista reutilizada) y curso (datasource + web service de búsqueda)

`#importCategory` se rellena con la **misma lista de categorías** que `#reviewCategory` (la que devuelve `getCourseSettings`, ya filtrada por `moodle/course:create`) y se mejora con `core/form-autocomplete` sin datasource remoto (`ajax = false`), una sola vez. `#importCourse` se reconstruye desde su marcado original cada vez que cambia la categoría y se mejora con el módulo AMD `local_coursegen/local/courseai/target-course-datasource`, cuyo `transport` lee `#importCategory` en el momento de la petición y llama al web service `local_coursegen_search_target_courses`. Ambas mejoras pasan por el envoltorio `enhanceSelect`, que es el único punto que invoca `FormAutocomplete.enhance`.

> Nota: en `course-create.js` hay tres llamadas a `enhanceSelect` (categoría del curso nuevo en 225-231, categoría de importación en 165-171 y curso destino en 129-135). Las dos que pertenecen a este flujo son las de importación; `FormAutocomplete.enhance` solo aparece una vez, dentro de `enhanceSelect`.

`amd/src/local/courseai/actions/course-create.js:71-77`

```javascript
const enhanceSelect = (FormAutocomplete, select, ajaxModule, placeholder, noResults) => {
    try {
        FormAutocomplete.enhance(select, false, ajaxModule, placeholder, false, true, noResults);
    } catch (e) {
        // Fall through — plain select still works.
    }
};
```

`amd/src/local/courseai/actions/course-create.js:161-175`

```javascript
        reset: (categories) => {
            fillCategoryOptions(categorySelect, categories, texts.courseai_import_category_label || '');
            if (!categoryEnhanced) {
                categoryEnhanced = true;
                enhanceSelect(
                    FormAutocomplete,
                    categorySelect,
                    false,
                    texts.courseai_import_category_placeholder || '',
                    texts.courseai_no_results || ''
                );
            }
            buildCourseField();
            modeSelect.value = 'new';
            applyMode();
```

`amd/src/local/courseai/actions/course-create.js:121-136`

```javascript
    const buildCourseField = () => {
        courseGroup.innerHTML = courseFieldHtml;
        courseSelect = courseGroup.querySelector('select');
        if (!courseSelect) {
            return;
        }
        // Core dispatches a native change on the select when a suggestion is picked.
        courseSelect.addEventListener('change', syncConfirm);
        enhanceSelect(
            FormAutocomplete,
            courseSelect,
            TARGET_COURSE_DATASOURCE,
            texts.courseai_import_course_placeholder || '',
            texts.courseai_no_results || ''
        );
    };
```

`amd/src/local/courseai/target-course-datasource.js:47-63`

```javascript
export const transport = async(selector, query, success, failure) => {
    const categorySelect = document.getElementById(CATEGORY_SELECT_ID);
    const categoryid = parseInt(categorySelect?.value || '', 10);
    if (!(categoryid > 0)) {
        success([]);
        return;
    }
    try {
        const results = await Ajax.call([{
            methodname: 'local_coursegen_search_target_courses',
            args: {categoryid, query: String(query || ''), limit: RESULT_LIMIT},
        }])[0];
        success(results);
    } catch (error) {
        failure(error);
    }
};
```

`amd/src/local/courseai/target-course-datasource.js:72-75`

```javascript
export const processResults = (selector, results) => {
    const rows = Array.isArray(results) ? results : [];
    return rows.map((course) => ({value: course.id, label: course.displayname}));
};
```

El web service está declarado en `db/services.php` como función de lectura con `ajax => true`. En el servidor comprueba, en este orden: contexto de sistema y `local/coursegen:createcoursewithai`, el ajuste `enableimportexisting`, existencia de la categoría y `moodle/course:create` sobre ella. Después recorre los cursos de la categoría de forma **recursiva**, filtra por texto (`matches` sobre fullname/shortname en minúsculas) y descarta los cursos donde el usuario no tenga a la vez `moodle/backup:backupcourse` y `moodle/restore:restorecourse`. El `limit` se acota a `1..50` (por defecto 20) y la respuesta incluye `displayname` = "fullname (shortname)".

`db/services.php:151-159`

```php
    'local_coursegen_search_target_courses' => [
        'classname' => 'local_coursegen\\external\\search_target_courses',
        'methodname' => 'execute',
        'description' => 'Search courses in a category the user may import an AI-generated course into',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coursegen:createcoursewithai,moodle/course:create',
        'loginrequired' => true,
    ],
```

`classes/external/search_target_courses.php:86-123`

```php
        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/coursegen:createcoursewithai', $systemcontext);

        if (empty(get_config('local_coursegen', 'enableimportexisting'))) {
            throw new moodle_exception('error_import_existing_disabled', 'local_coursegen');
        }

        $category = core_course_category::get($params['categoryid'], MUST_EXIST, true);
        require_capability('moodle/course:create', context_coursecat::instance($category->id));

        $needle = \core_text::strtolower(trim($params['query']));
        $limit = max(self::LIMIT_MIN, min(self::LIMIT_MAX, (int) $params['limit']));

        $courses = [];
        foreach ($category->get_courses(['recursive' => true]) as $course) {
            if ($needle !== '' && !self::matches($course, $needle)) {
                continue;
            }
            $coursecontext = \context_course::instance($course->id);
            if (!has_all_capabilities(['moodle/backup:backupcourse', 'moodle/restore:restorecourse'], $coursecontext)) {
                continue;
            }
            $fullname = format_string($course->fullname, true, ['context' => $coursecontext]);
            $courses[] = [
                'id' => (int) $course->id,
                'fullname' => $fullname,
                'shortname' => $course->shortname,
                'categoryid' => (int) $course->category,
                'displayname' => $fullname . ' (' . $course->shortname . ')',
            ];
            if (count($courses) >= $limit) {
                break;
            }
        }

        return $courses;
    }
```

---

## 3. Confirmar: el web service `create_course` en modo importación

Al pulsar confirmar, `onConfirm` lee el modo y el curso destino del controlador; en modo `import` devuelve **solo** `targetmode = 'import'` y `targetcourseid` y sale antes de leer los inputs (`fullname`, `shortname` y `category` se leen únicamente en modo `new`). `createCourseFromSession` copia esos dos campos al payload y el repositorio los reenvía a `local_coursegen_create_course`. En el servidor, `execute` valida `targetmode`, exige `moodle/course:create` y `local/coursegen:createcoursewithai` en contexto de sistema, carga la sesión del usuario y, **antes de pedir el resultado a la API y de materializar nada**, llama a `require_import_target`: ajuste activado, `targetcourseid > 0`, el curso existe y no es el sitio (`(int)SITEID`), y capacidades de backup/restore y `moodle/course:visibility` sobre el destino (la importación oculta el original), `moodle/course:create` sobre su categoría y, en último lugar, `moodle/backup:userinfo` sobre el destino y `moodle/restore:userinfo` sobre su categoría: la copia lleva los datos de usuario del original y, como aún no existe, hereda los permisos de la categoría del destino. Luego fuerza la categoría del staging a la del destino, llama a `create_course_service::create_course` y, si tuvo éxito, lee el ajuste `importexecution`: en `adhoc` (por defecto) encola `import_generated_course_task` y responde `importstate = 'queued'`; en `sync` llama a `course_import_service::mark_queued` e `import_into_existing` dentro de la propia petición y responde el estado terminal (`importstate = 'done'` con `copycourseid`, o `'failed'`). En los dos casos responde `importqueued = true` con el `courseid` del **staging** (ver paso 4.1.1).

`amd/src/local/courseai/actions/course-create.js:253-281`

```javascript
        const onConfirm = () => {
            if (resolved) { return; }
            const targetMode = importControls ? importControls.getMode() : '';
            const targetCourseId = importControls ? importControls.getTargetCourseId() : 0;
            if (targetMode === 'import' && !targetCourseId) {
                // Never submit an import without a target; the button is disabled anyway.
                confirmBtn.disabled = true;
                return;
            }
            resolved = true;
            cleanup();
            hidePanel();
            const overrides = {};
            if (targetMode === 'import') {
                // The copy keeps the target's names and category: no fullname/shortname/category.
                overrides.targetmode = 'import';
                overrides.targetcourseid = targetCourseId;
                resolve(overrides);
                return;
            }
            if (targetMode === 'new') { overrides.targetmode = 'new'; }
            const fullname = fullnameInput.value.trim();
            if (fullname) { overrides.fullname = fullname; }
            const shortname = shortnameInput.value.trim();
            if (shortname) { overrides.shortname = shortname; }
            const category = parseInt(categorySelect.value, 10);
            if (category > 0) { overrides.category = category; }
            resolve(overrides);
        };
```

`amd/src/local/courseai/actions/course-create.js:347-354`

```javascript
        const payload = {recordid: state.sessionid};
        if (overrides) {
            if (overrides.fullname) { payload.fullname = overrides.fullname; }
            if (overrides.shortname) { payload.shortname = overrides.shortname; }
            if (overrides.category) { payload.category = overrides.category; }
            if (overrides.targetmode) { payload.targetmode = overrides.targetmode; }
            if (overrides.targetcourseid) { payload.targetcourseid = overrides.targetcourseid; }
        }
```

`amd/src/repository/course.js:79-104`

```javascript
export async function createCourse({recordid, fullname, shortname, category, targetmode, targetcourseid}) {
    const args = {
        recordid: Number(recordid) || 0,
    };
    if (typeof fullname === 'string' && fullname.trim()) {
        args.fullname = fullname.trim();
    }
    if (typeof shortname === 'string' && shortname.trim()) {
        args.shortname = shortname.trim();
    }
    if (typeof category === 'number' && category > 0) {
        args.category = category;
    }
    if (targetmode === 'new' || targetmode === 'import') {
        args.targetmode = targetmode;
    }
    if (typeof targetcourseid === 'number' && targetcourseid > 0) {
        args.targetcourseid = targetcourseid;
    }
    return ajax.call([
        {
            methodname: "local_coursegen_create_course",
            args,
        },
    ])[0];
}
```

`classes/external/create_course.php:51-87`

```php
    /** @var string Target mode: create a new course (today's behaviour). */
    const TARGETMODE_NEW = 'new';

    /** @var string Target mode: generate a staging course and import it into an existing course. */
    const TARGETMODE_IMPORT = 'import';

    /** @var string Import execution: queue an adhoc task that cron runs (default). */
    const IMPORTEXECUTION_ADHOC = 'adhoc';

    /** @var string Import execution: run the import inside the request. */
    const IMPORTEXECUTION_SYNC = 'sync';

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Course planning session record ID'),
            'fullname' => new external_value(PARAM_TEXT, 'Override course fullname', VALUE_DEFAULT, ''),
            'shortname' => new external_value(PARAM_TEXT, 'Override course shortname', VALUE_DEFAULT, ''),
            'category' => new external_value(PARAM_INT, 'Override course category ID', VALUE_DEFAULT, 0),
            'targetmode' => new external_value(
                PARAM_ALPHA,
                'Target mode: "new" creates a course, "import" imports the generated course into an existing one',
                VALUE_DEFAULT,
                self::TARGETMODE_NEW
            ),
            'targetcourseid' => new external_value(
                PARAM_INT,
                'Existing course to import into (import mode only)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }
```

`classes/external/create_course.php:122-143`

```php
        if (!in_array($params['targetmode'], [self::TARGETMODE_NEW, self::TARGETMODE_IMPORT], true)) {
            throw new invalid_parameter_exception('Unknown targetmode: ' . $params['targetmode']);
        }
        $importing = $params['targetmode'] === self::TARGETMODE_IMPORT;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $recordid = (int)$params['recordid'];

        // Load session (validates ownership).
        $session = course_session_service::get_user_session($recordid, $USER->id);

        // In import mode the target is validated and the import permissions are
        // checked before anything is fetched or materialised: a denial must leave
        // no staging course behind.
        $target = null;
        if ($importing) {
            $target = self::require_import_target((int)$params['targetcourseid']);
        }
```

`classes/external/create_course.php:226-252`

```php
    private static function require_import_target(int $targetcourseid): \stdClass {
        global $DB;

        if (empty(get_config('local_coursegen', 'enableimportexisting'))) {
            throw new moodle_exception('error_import_existing_disabled', 'local_coursegen');
        }
        if ($targetcourseid <= 0) {
            throw new invalid_parameter_exception('targetcourseid is required in import mode');
        }
        $target = $DB->get_record('course', ['id' => $targetcourseid]);
        if (!$target || (int)$target->id === (int)SITEID) {
            throw new moodle_exception('error_import_target_invalid', 'local_coursegen');
        }

        $targetcontext = context_course::instance($target->id);
        require_capability('moodle/backup:backupcourse', $targetcontext);
        require_capability('moodle/restore:restorecourse', $targetcontext);
        require_capability('moodle/course:create', context_coursecat::instance($target->category));
        // The import hides the original course, so the acting user must be allowed to change its visibility.
        require_capability('moodle/course:visibility', $targetcontext);
        // The copy carries the user data of the original: backup side on the target, restore side on
        // the copy, which does not exist yet and inherits its permissions from the target's category.
        require_capability('moodle/backup:userinfo', $targetcontext);
        require_capability('moodle/restore:userinfo', context_coursecat::instance($target->category));

        return $target;
    }
```

`classes/external/create_course.php:164-196`

```php
        if ($target !== null) {
            // The staging course always lives next to the target, so the user needs
            // no course creation permission anywhere else.
            $overrides['category'] = (int)$target->category;
        }

        $outcome = create_course_service::create_course($session, $resultdata, $overrides);
        $outcome['importqueued'] = false;
        $outcome['targetcourseid'] = 0;
        $outcome['importstate'] = '';
        $outcome['copycourseid'] = 0;
        if ($target !== null && !empty($outcome['success']) && !empty($outcome['courseid'])) {
            $sessionid = (int)$session->get('id');
            $stagingid = (int)$outcome['courseid'];
            $execution = (string)get_config('local_coursegen', 'importexecution');
            if ($execution === self::IMPORTEXECUTION_SYNC) {
                // Foreground: the copy and the import run inside this request. The
                // service never throws on an import failure; it rolls back, marks
                // the session failed and reports it in its result.
                $importservice = static::get_import_service();
                $importservice::mark_queued($sessionid, $stagingid, (int)$target->id);
                $import = $importservice::import_into_existing($sessionid, $stagingid, (int)$target->id, (int)$USER->id);
                $outcome['importstate'] = !empty($import['success'])
                    ? course_import_service::STATE_DONE
                    : course_import_service::STATE_FAILED;
                $outcome['copycourseid'] = !empty($import['success']) ? (int)$import['copycourseid'] : 0;
            } else {
                import_generated_course_task::queue($sessionid, $stagingid, (int)$target->id, (int)$USER->id);
                $outcome['importstate'] = course_import_service::STATE_QUEUED;
            }
            $outcome['importqueued'] = true;
            $outcome['targetcourseid'] = (int)$target->id;
        }
```

`classes/external/create_course.php:283-320`

```php
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
            'partial' => new external_value(PARAM_BOOL, 'Whether course content was only partially applied', VALUE_DEFAULT, false),
            'haswarnings' => new external_value(
                PARAM_BOOL,
                'Whether activity creation warnings were detected',
                VALUE_DEFAULT,
                false
            ),
            'warningscount' => new external_value(PARAM_INT, 'Count of skipped activity creations', VALUE_DEFAULT, 0),
            'importqueued' => new external_value(
                PARAM_BOOL,
                'Whether an import into an existing course was queued',
                VALUE_DEFAULT,
                false
            ),
            'targetcourseid' => new external_value(PARAM_INT, 'Existing course the import targets', VALUE_DEFAULT, 0),
            'importstate' => new external_value(
                PARAM_ALPHA,
                'Import state: empty when no import, "queued" for a background import, "done" or "failed" when run in the request',
                VALUE_DEFAULT,
                ''
            ),
            'copycourseid' => new external_value(
                PARAM_INT,
                'Course that replaced the target, only when the import ran in the request and succeeded',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }
}
```

De vuelta en el navegador, `createCourseFromSession` bifurca según `importqueued`: si está activo muestra la tarjeta de progreso de importación (paso 5) con el `recordid` de la sesión; si no, la vista de finalización de siempre. La tarjeta decide por sí sola si sondea: con un `importstate` terminal (ejecución `sync`) pinta el resultado directamente.

`amd/src/local/courseai/actions/course-create.js:363-375`

```javascript
        if (!result || !result.success) {
            throw new Error(result?.message || texts.courseai_error_create_course);
        }

        if (result.importqueued) {
            // courseid is the staging course. The import into the target either runs in an
            // adhoc task (importstate=queued, the view polls) or already ran in this request
            // (importstate=done|failed, the view renders the terminal state without polling).
            showImportProgressView({...result, recordid: state.sessionid});
        } else {
            showCompletionView(result);
        }
        return result;
```

---

## 4. La tarea adhoc y el servicio de importación

### 4.1 Encolado y ejecución de la tarea

Con `importexecution = adhoc` (por defecto), `execute` encola la tarea; con `sync` llama al servicio directamente (paso 4.1.1). `queue` primero marca la sesión como `queued` (vía `course_import_service::mark_queued`) y después encola la tarea adhoc con `sessionid`, `stagingcourseid` y `targetcourseid` como custom data, ejecutándola como el usuario que pidió la importación. `execute` delega todo en `course_import_service::import_into_existing`. `retry_until_success` devuelve `false`: la tarea **nunca se reintenta** por cron, porque un reintento ciego podría ejecutarse con el curso original ya renombrado; reintentar es una acción explícita del usuario.

`classes/task/import_generated_course_task.php:43-54`

```php
    public static function queue(int $sessionid, int $stagingcourseid, int $targetcourseid, int $userid): void {
        course_import_service::mark_queued($sessionid, $stagingcourseid, $targetcourseid);

        $task = new self();
        $task->set_custom_data([
            'sessionid' => $sessionid,
            'stagingcourseid' => $stagingcourseid,
            'targetcourseid' => $targetcourseid,
        ]);
        $task->set_userid($userid);
        manager::queue_adhoc_task($task);
    }
```

`classes/local/service/course_import_service.php:169-177`

```php
    public static function mark_queued(int $sessionid, int $stagingcourseid, int $targetcourseid): void {
        $session = new course_session($sessionid);
        self::update_session($session, null, null, [
            'targetcourseid' => $targetcourseid,
            'stagingcourseid' => $stagingcourseid,
            'state' => self::STATE_QUEUED,
            'errorclass' => '',
        ]);
    }
```

`classes/task/import_generated_course_task.php:70-78`

```php
    public function execute(): void {
        $data = $this->get_custom_data();
        course_import_service::import_into_existing(
            (int)$data->sessionid,
            (int)$data->stagingcourseid,
            (int)$data->targetcourseid,
            (int)$this->get_userid()
        );
    }
```

`classes/task/import_generated_course_task.php:80-89`

```php
    /**
     * Never retry blindly: the original course may already have been renamed.
     *
     * A retry is an explicit user action.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }
```

### 4.1.1 Modo de ejecución (`importexecution`): tarea adhoc o misma petición

Un tercer ajuste, el select `local_coursegen/importexecution`, decide **dónde** corre la importación. `adhoc` (por defecto) encola `import_generated_course_task` como se describe arriba y el navegador sondea el estado (paso 5). `sync` ejecuta la copia y la importación dentro de la propia petición de `create_course`. Como `importcontentmode`, se oculta con `hide_if` cuando `enableimportexisting` está desmarcado, y no llega ni a la plantilla ni al JS: lo lee únicamente `execute`.

`settings.php:80-93`

```php
    // Only meaningful while the import is enabled: "adhoc" queues a cron task
    // and the page polls until it ends, "sync" runs the copy and the import
    // inside the create_course request.
    $settings->add(new admin_setting_configselect(
        'local_coursegen/importexecution',
        get_string('setting_importexecution', 'local_coursegen'),
        get_string('setting_importexecution_desc', 'local_coursegen'),
        'adhoc',
        [
            'adhoc' => get_string('importexecution_adhoc', 'local_coursegen'),
            'sync' => get_string('importexecution_sync', 'local_coursegen'),
        ]
    ));
    $settings->hide_if('local_coursegen/importexecution', 'local_coursegen/enableimportexisting', 'notchecked');
```

En `execute`, tras crear el staging, se lee el ajuste. En `sync` el web service obtiene la clase del servicio por `get_import_service()` (factoría protegida para que PHPUnit inyecte una subclase por late static binding), llama a `mark_queued` y después a `import_into_existing`, sin `try/catch`: el servicio nunca lanza por un fallo de importación, hace rollback, marca la sesión como `failed` y lo devuelve en su resultado (`success`, `copycourseid`, `errorclass`). La respuesta lleva `importstate = done|failed` y, solo con éxito, `copycourseid`. En `adhoc` se encola la tarea y `importstate = queued`.

`classes/external/create_course.php:178-193`

```php
            $execution = (string)get_config('local_coursegen', 'importexecution');
            if ($execution === self::IMPORTEXECUTION_SYNC) {
                // Foreground: the copy and the import run inside this request. The
                // service never throws on an import failure; it rolls back, marks
                // the session failed and reports it in its result.
                $importservice = static::get_import_service();
                $importservice::mark_queued($sessionid, $stagingid, (int)$target->id);
                $import = $importservice::import_into_existing($sessionid, $stagingid, (int)$target->id, (int)$USER->id);
                $outcome['importstate'] = !empty($import['success'])
                    ? course_import_service::STATE_DONE
                    : course_import_service::STATE_FAILED;
                $outcome['copycourseid'] = !empty($import['success']) ? (int)$import['copycourseid'] : 0;
            } else {
                import_generated_course_task::queue($sessionid, $stagingid, (int)$target->id, (int)$USER->id);
                $outcome['importstate'] = course_import_service::STATE_QUEUED;
            }
```

`classes/external/create_course.php:266-276`

```php
    /**
     * Name of the import service class used by the foreground execution.
     *
     * Protected factory so PHPUnit tests can inject a testable subclass of
     * course_import_service through late static binding; the service API is static.
     *
     * @return string Class name of a course_import_service.
     */
    protected static function get_import_service(): string {
        return course_import_service::class;
    }
```

`execute_returns` gana dos campos: `importstate` (`''` sin importación, `queued` en segundo plano, `done`/`failed` cuando corrió en la petición) y `copycourseid` (solo cuando corrió en la petición y tuvo éxito).

`classes/external/create_course.php:306-317`

```php
            'importstate' => new external_value(
                PARAM_ALPHA,
                'Import state: empty when no import, "queued" for a background import, "done" or "failed" when run in the request',
                VALUE_DEFAULT,
                ''
            ),
            'copycourseid' => new external_value(
                PARAM_INT,
                'Course that replaced the target, only when the import ran in the request and succeeded',
                VALUE_DEFAULT,
                0
            ),
```

**Límites de tiempo en `sync`.** Toda la importación (backup del destino con usuarios, renombrado y ocultación del original, creación de la copia y restore sobre ella, backup e importación del staging, borrado del staging y, si algo falla, el rollback) corre dentro de la petición de `create_course`, bajo el servidor web y el navegador: cron no interviene. La petición debe caber en el tope del cliente, `CREATE_COURSE_TIMEOUT_MS` (180 s) de `course-create.js`, y en el `Timeout` de Apache (o el equivalente del servidor que sirva PHP). Si se supera el tope del cliente, el navegador rechaza con `courseai_error_connection` (`course-create.js:356`) aunque el servidor pueda seguir ejecutando la importación; el estado real queda en la sesión y se recupera al reanudar (paso 5.3). En `adhoc` esos límites afectan solo a la generación del staging.

`amd/src/local/courseai/actions/course-create.js:320-320`

```javascript
    const CREATE_COURSE_TIMEOUT_MS = 180000;
```

En el navegador no cambia la bifurcación de `createCourseFromSession`: `showImportProgressView` aplica primero el resultado recibido y, si `importstate` ya es terminal, no arranca el sondeo.

`amd/src/local/courseai/actions.js:338-344`

```javascript
        if (applyImportState(result)) {
            return;
        }
        const recordid = Number(result?.recordid || state.sessionid || 0);
        if (recordid > 0) {
            startImportPolling(recordid);
        }
```

Como `create_course` no devuelve `copycourseurl` (solo `get_course_session_state` lo hace), `applyImportState` construye la URL de la copia a partir de `copycourseid`.

`amd/src/local/courseai/actions.js:262-264`

```javascript
            const copyCourseId = Number(info?.copycourseid || 0);
            const copyCourseUrl = String(info?.copycourseurl || '')
                || (copyCourseId > 0 ? M.cfg.wwwroot + '/course/view.php?id=' + copyCourseId : '');
```

### 4.2 Secuencia completa de `import_into_existing`

El método orquesta todo el proceso. Antes de cualquier escritura carga destino y staging, vuelve a exigir las capacidades (ahora incluyendo `moodle/backup:backuptargetimport` sobre el staging, que no existía cuando se validó en `create_course`) y pone la sesión en `STATUS_IMPORTING` con el bloque `import` en estado `running`, guardando también la visibilidad original del destino (`originalvisible`, `originalvisibleold`). El orden real dentro del `try` es: (1) backup del destino con sus datos de usuario (`$withusers = true`), (2) renombrado y ocultación del original + creación de la copia en una transacción, (3) restore del backup sobre la copia con esos datos de usuario, (4) backup del staging y restore sobre la copia con el target que fija `importcontentmode` (en modo `add`, con la renumeración transitoria de secciones del paso 4.7). Cualquier `Throwable` entra en el rollback.

`classes/local/service/course_import_service.php:84-159`

```php
    public static function import_into_existing(int $sessionid, int $stagingcourseid, int $targetcourseid, int $userid): array {
        global $DB;

        self::load_backup_libraries();

        $target = $DB->get_record('course', ['id' => $targetcourseid], '*', MUST_EXIST);
        $staging = $DB->get_record('course', ['id' => $stagingcourseid], '*', MUST_EXIST);
        self::require_import_capabilities($target, $staging, $userid);

        $session = new course_session($sessionid);
        self::update_session($session, course_session::STATUS_IMPORTING, null, [
            'targetcourseid' => (int)$target->id,
            'stagingcourseid' => (int)$staging->id,
            'copycourseid' => 0,
            'originalshortname' => $target->shortname,
            'originalfullname' => $target->fullname,
            'originalvisible' => (int)$target->visible,
            'originalvisibleold' => (int)$target->visibleold,
            'renamedshortname' => '',
            'state' => self::STATE_RUNNING,
            'errorclass' => '',
            'timestarted' => time(),
            'timefinished' => 0,
        ]);

        $copyid = 0;
        $renamed = false;
        $tempdirs = [];
        try {
            // Back up the target while it still carries the original names: the
            // restore engine takes the new course names from the backup whenever
            // the name settings are locked for the acting user.
            $backupid = static::backup_course((int)$target->id, \backup::MODE_SAMESITE, $userid, $tempdirs, true);

            [$names, $copyid] = self::rename_original_and_create_copy($target);
            $renamed = true;
            self::update_session($session, null, null, [
                'copycourseid' => $copyid,
                'renamedshortname' => $names['shortname'],
            ]);

            self::restore_copy_with_original_names($backupid, $target, $copyid, $userid);
            self::import_staging_into_copy($staging, $copyid, $userid, $tempdirs);
        } catch (\Throwable $e) {
            $copydeleted = self::rollback($target, $copyid, $renamed, $tempdirs);
            self::update_session($session, course_session::STATUS_FAILED, (int)$staging->id, [
                'copycourseid' => $copydeleted ? 0 : $copyid,
                'state' => self::STATE_FAILED,
                'errorclass' => get_class($e),
                'timefinished' => time(),
            ]);
            generation_failed::create([
                'context' => \context_system::instance(),
                'userid' => $userid,
                'other' => ['reason' => get_class($e)],
            ])->trigger();

            return ['success' => false, 'copycourseid' => 0, 'errorclass' => get_class($e)];
        }

        self::remove_temp_dirs($tempdirs);
        // Point the session at the copy before deleting the staging course: the
        // course_deleted observer removes every session bound to a deleted course.
        self::update_session($session, course_session::STATUS_CREATED, $copyid, [
            'state' => self::STATE_DONE,
            'timefinished' => time(),
        ]);
        self::delete_course_bypassing_bin((int)$staging->id);
        generation_result_applied::create([
            'context' => \context_course::instance($copyid),
            'userid' => $userid,
            'other' => ['courseid' => $copyid],
        ])->trigger();

        return ['success' => true, 'copycourseid' => $copyid, 'errorclass' => ''];
    }
```

### 4.3 Comprobación de capacidades (antes de escribir nada)

Además de backup/restore sobre el destino, creación en su categoría y `backuptargetimport` sobre el staging, exige `moodle/course:visibility` sobre el destino: el original se oculta cuando la copia ocupa su lugar. Las dos últimas son `moodle/backup:userinfo` sobre el destino y `moodle/restore:userinfo` sobre la categoría del destino: la copia lleva los datos de usuario del original, y el motor comprueba el lado del restore sobre el contexto del curso de destino de la restauración, es decir, la copia, que hereda de esa categoría (`backup/util/checks/restore_check.class.php:65` y `:121`). Son de arquetipo Gestor en Moodle: hay que añadirlas al rol que use la función (ver §7 de la estrategia).

`classes/local/service/course_import_service.php:215-227`

```php
    private static function require_import_capabilities(\stdClass $target, \stdClass $staging, int $userid): void {
        $targetcontext = \context_course::instance($target->id);
        require_capability('moodle/backup:backupcourse', $targetcontext, $userid);
        require_capability('moodle/restore:restorecourse', $targetcontext, $userid);
        require_capability('moodle/course:create', \context_coursecat::instance($target->category), $userid);
        require_capability('moodle/backup:backuptargetimport', \context_course::instance($staging->id), $userid);
        // The original is hidden once the copy takes its place.
        require_capability('moodle/course:visibility', $targetcontext, $userid);
        // The copy carries the user data of the original; the restore engine
        // checks the restore side on the copy, which inherits from the category.
        require_capability('moodle/backup:userinfo', $targetcontext, $userid);
        require_capability('moodle/restore:userinfo', \context_coursecat::instance($target->category), $userid);
    }
```

### 4.4 Backup del destino ANTES del renombrado

El backup del destino se hace con `MODE_SAMESITE` mientras el curso todavía tiene sus nombres originales: si los ajustes de nombre del restore están bloqueados para el usuario, el motor toma los nombres del backup, y así la copia conserva fullname y shortname originales. `backup_course` recibe un quinto parámetro `$withusers`: el backup del destino lo pide con `true` (línea 116 del bloque 4.2) y los dos backups del staging con `false` (4.7 y 4.7.1). El ajuste `users` solo se escribe si no está bloqueado; el motor lo fuerza a `false` cuando el usuario carece de `moodle/backup:userinfo` y lo bloquea en su valor por defecto cuando carece de `moodle/backup:configure` (`backup/util/checks/backup_check.class.php:159` y `:222`), y en `MODE_IMPORT` siempre va apagado. Si se pidieron datos de usuario y el ajuste acaba apagado, lanza `error_import_users_required` y la importación entra en el rollback. Fuera del modo import, descomprime el `.mbz` resultante en el directorio temporal y borra el archivo almacenado.

`classes/local/service/course_import_service.php:602-644`

```php
    protected static function backup_course(int $courseid, int $mode, int $userid, array &$tempdirs, bool $withusers): string {
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            $mode,
            $userid
        );
        $basepath = $bc->get_plan()->get_basepath();
        $tempdirs[] = $basepath;
        // The engine locks the setting (forced off) when the user lacks
        // moodle/backup:userinfo, and locks every setting at its default when
        // the user lacks moodle/backup:configure; import mode always forces it off.
        $users = $bc->get_plan()->get_setting('users');
        if ($users->get_status() === \base_setting::NOT_LOCKED) {
            $users->set_value($withusers);
        }
        if ($withusers && !$users->get_value()) {
            $bc->destroy();
            throw new \moodle_exception('error_import_users_required', 'local_coursegen');
        }
        $backupid = $bc->get_backupid();
        self::execute_silently(function () use ($bc) {
            $bc->execute_plan();
        });
        $results = $bc->get_results();
        $bc->destroy();

        // Outside import mode the engine zips the backup into the course backup
        // area and drops the unpacked directory. Unpack it again for the restore
        // and remove the stored file so nothing is left behind (same as
        // core_course_external::duplicate_course()).
        if (!empty($results['backup_destination'])) {
            $file = $results['backup_destination'];
            if (!file_exists($basepath . '/moodle_backup.xml')) {
                $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $basepath);
            }
            $file->delete();
        }

        return $backupid;
    }
```

### 4.5 Renombrado y ocultación del original + creación de la copia en una transacción

No hay índice único sobre `course.shortname`, así que renombrar el original y crear la copia con los nombres originales comparten una transacción para cerrar la ventana en la que otra petición podría ocupar el shortname liberado. En el mismo paso el original se oculta (`set_course_visibility(…, 0, 0)`), de modo que `rollback` lo cubra; si la importación termina bien, queda renombrado y oculto. `compute_renamed_names` sigue el patrón de `delete_user()`: prefijo truncado + `.` + sello de 10 dígitos, incrementado mientras el shortname exista; el fullname recibe el sufijo ` (sello)`.

`classes/local/service/course_import_service.php:240-251`

```php
    private static function rename_original_and_create_copy(\stdClass $target): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $names = self::compute_renamed_names($target->shortname, $target->fullname, time());
        self::set_course_names((int)$target->id, $names['shortname'], $names['fullname']);
        self::set_course_visibility((int)$target->id, 0, 0);
        $copyid = (int)\restore_dbops::create_new_course($target->fullname, $target->shortname, $target->category);
        $transaction->allow_commit();

        return [$names, $copyid];
    }
```

`classes/local/service/course_import_service.php:190-204`

```php
    public static function compute_renamed_names(string $shortname, string $fullname, int $stamp): array {
        global $DB;

        $prefixlength = self::SHORTNAME_MAXLENGTH - 1 - self::STAMP_LENGTH;
        do {
            $newshortname = sprintf('%s.%10d', core_text::substr($shortname, 0, $prefixlength), $stamp);
            $stamp++;
        } while ($DB->record_exists('course', ['shortname' => $newshortname]));
        $usedstamp = $stamp - 1;

        $suffix = ' (' . $usedstamp . ')';
        $newfullname = core_text::substr($fullname, 0, self::FULLNAME_MAXLENGTH - strlen($suffix)) . $suffix;

        return ['shortname' => $newshortname, 'fullname' => $newfullname, 'stamp' => $usedstamp];
    }
```

#### 4.5.1 Ocultar el original: `set_course_visibility`

Escribe `visible` y `visibleold` directamente (en vez de `course_change_visibility()`, que siempre iguala `visibleold` a `visible` y perdería un curso oculto por su categoría), rebuild + purge de cachés y evento `course_updated`, imitando la ruta de visibilidad de `update_course()`. No comprueba capacidades: ya lo hizo `require_import_capabilities`. El rollback la reutiliza con los valores guardados en el registro `$target`.

`classes/local/service/course_import_service.php:290-313`

```php
    private static function set_course_visibility(int $courseid, int $visible, int $visibleold): void {
        global $DB;

        $DB->update_record('course', (object) [
            'id' => $courseid,
            'visible' => $visible,
            'visibleold' => $visibleold,
            'timemodified' => time(),
        ]);
        rebuild_course_cache($courseid, true);
        \cache_helper::purge_by_event('changesincourse');
        \cache_helper::purge_by_event('changesincoursecat');

        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname', MUST_EXIST);
        \core\event\course_updated::create([
            'objectid' => $courseid,
            'context' => \context_course::instance($courseid),
            'other' => [
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'updatedfields' => ['visible' => $visible, 'visibleold' => $visibleold],
            ],
        ])->trigger();
    }
```

### 4.6 Restore del destino sobre la copia (`TARGET_NEW_COURSE`)

Se restaura el backup del destino sobre la copia con `TARGET_NEW_COURSE`, pasando explícitamente los nombres originales y los ajustes de una restauración completa con usuarios: `users`, `enrolments => \backup::ENROL_WITHUSERS` (instancias de matriculación con sus matrículas), `role_assignments`, `userscompletion` y `grade_histories`; grupos, miembros y datos de usuario de las actividades siguen al ajuste `users`. `restore_course` ignora los ajustes que el plan no expone (`setting_exists`), escribe los de `OWNED_RESTORE_SETTINGS` a través de `set_owned_setting_value` (4.6.1) y el resto solo si no están bloqueados; si se pidió `users` y el ajuste acaba apagado lanza `error_import_users_required` (el motor lo fuerza a `false` cuando el usuario carece de `moodle/restore:userinfo` sobre la copia), y lanza `error_import_precheck_failed` si el precheck reporta errores.

`classes/local/service/course_import_service.php:332-342`

```php
    private static function restore_copy_with_original_names(string $backupid, \stdClass $target, int $copyid, int $userid): void {
        static::restore_course($backupid, $copyid, $userid, \backup::TARGET_NEW_COURSE, [
            'course_fullname' => $target->fullname,
            'course_shortname' => $target->shortname,
            'users' => true,
            'enrolments' => \backup::ENROL_WITHUSERS,
            'role_assignments' => true,
            'userscompletion' => true,
            'grade_histories' => true,
        ]);
    }
```

`classes/local/service/course_import_service.php:659-688`

```php
    protected static function restore_course(string $backupid, int $courseid, int $userid, int $target, array $settings): void {
        $rc = new \restore_controller($backupid, $courseid, \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $userid, $target);
        $plan = $rc->get_plan();
        foreach ($settings as $name => $value) {
            if (!$plan->setting_exists($name)) {
                continue;
            }
            $setting = $plan->get_setting($name);
            if (in_array($name, self::OWNED_RESTORE_SETTINGS, true)) {
                self::set_owned_setting_value($setting, $value);
            } else if ($setting->get_status() === \base_setting::NOT_LOCKED) {
                $setting->set_value($value);
            }
        }
        if (!empty($settings['users']) && !$plan->get_setting('users')->get_value()) {
            $rc->destroy();
            throw new \moodle_exception('error_import_users_required', 'local_coursegen');
        }
        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            if (!empty($results['errors'])) {
                $rc->destroy();
                throw new \moodle_exception('error_import_precheck_failed', 'local_coursegen');
            }
        }
        self::execute_silently(function () use ($rc) {
            $rc->execute_plan();
        });
        $rc->destroy();
    }
```

#### 4.6.1 Ajustes propios del servicio: `OWNED_RESTORE_SETTINGS` y `set_owned_setting_value`

Cuando el usuario carece de `moodle/restore:configure`, el motor bloquea por permiso todos los ajustes del restore en su valor por defecto (`backup/util/checks/restore_check.class.php:154`). `keep_roles_and_enrolments` y `keep_groups_and_groupings` (los ajustes con los que un restore `TARGET_EXISTING_DELETING` conserva personas y grupos, ver 4.7) no tienen capacidad propia y el usuario nunca configura este restore, así que el servicio los decide él mismo: `set_owned_setting_value` levanta un bloqueo por permiso para escribir y lo repone después, igual que hace `core\task\asynchronous_copy_task` con `users` (`lib/classes/task/asynchronous_copy_task.php:83-86`); un bloqueo por configuración del sitio (`LOCKED_BY_CONFIG`) se respeta y el ajuste se deja como está.

`classes/local/service/course_import_service.php:71-72`

```php
    /** @var string[] Restore settings the service decides whatever the acting user may configure. */
    private const OWNED_RESTORE_SETTINGS = ['keep_roles_and_enrolments', 'keep_groups_and_groupings'];
```

`classes/local/service/course_import_service.php:705-717`

```php
    private static function set_owned_setting_value(\base_setting $setting, $value): void {
        $status = $setting->get_status();
        if ($status === \base_setting::LOCKED_BY_CONFIG) {
            return;
        }
        if ($status !== \base_setting::NOT_LOCKED) {
            $setting->set_status(\base_setting::NOT_LOCKED);
        }
        $setting->set_value($value);
        if ($status !== \base_setting::NOT_LOCKED) {
            $setting->set_status($status);
        }
    }
```

#### 4.6.2 Nota: remapeo de roles y acceso del restaurador (comportamiento de core)

El motor de restore no copia las asignaciones de rol tal cual: para cada rol del backup busca el mejor rol **asignable por el usuario que restaura** en el contexto de la copia (`restore_dbops::precheck_included_roles`, `backup/util/dbops/restore_dbops.class.php:185`, que llama a `get_best_assignable_role`, `backup/util/dbops/restore_dbops.class.php:362-398`): coincide por shortname, después por arquetipo, después `editingteacher` → `teacher`, y si no hay coincidencia el rol se omite (`0`, con el aviso `cannotfindassignablerole` en el precheck). La lista de roles asignables sale de `get_assignable_roles()` con el `$userid` del restaurador: un administrador del sitio puede asignar cualquier rol (`lib/accesslib.php:3281`), pero un rol al que no se le permita asignar `editingteacher` verá a los demás profesores con permiso de edición convertidos en profesores sin permiso de edición en la copia. La solución es permitir a ese rol la asignación de los roles del curso (pestaña «Permitir asignaciones de roles» en la definición del rol), ver §7 de la estrategia. Además, `restore_fix_restorer_access_step` (`backup/moodle2/restore_stepslib.php:2560-2605`, registrado en `backup/moodle2/restore_root_task.class.php:46`) comprueba que el restaurador pueda acceder a la copia con `moodle/course:update`; si no puede y `$CFG->restorernewroleid` está definido, le asigna ese rol y, en último término, lo matricula manualmente en la copia.

### 4.7 Backup del staging (`MODE_IMPORT`) y restore sobre la copia (`TARGET_EXISTING_DELETING` o `TARGET_EXISTING_ADDING`)

El contenido generado se lleva a la copia con un backup del staging en `MODE_IMPORT` y un restore cuyo target lo decide el ajuste `importcontentmode` (paso 0.1) a través de `get_import_target`: `add` devuelve `TARGET_EXISTING_ADDING`; cualquier otro valor devuelve `TARGET_EXISTING_DELETING`, que borra el contenido de la copia antes de volcar el del staging. `import_staging_into_copy` bifurca sobre ese target. Los dos backups del staging van sin usuarios (`$withusers = false`: el staging no tiene). En `replace` el restore recibe `keep_roles_and_enrolments` y `keep_groups_and_groupings`, de modo que el borrado previo de `TARGET_EXISTING_DELETING` conserva las matriculaciones, las asignaciones de rol, los grupos y los agrupamientos de la copia; los datos de usuario ligados a las actividades reemplazadas (calificaciones, intentos, entregas) se pierden con ellas. En `add` no se borra nada y los usuarios conservan sus datos en las actividades originales; el backup pasa por `backup_staging_appended` (4.7.1) y, tras el restore, por `extend_numsections`.

Por qué hace falta algo más en modo `add`: el motor de restore empareja las secciones **por número**. Con `TARGET_EXISTING_ADDING` una sección entrante cuyo número ya existe en la copia conserva el nombre y el resumen de la copia y solo recibe las actividades entrantes, es decir, el contenido generado se **mezclaría** con las secciones existentes. Para **anexar** en vez de mezclar, el staging se renumera antes del backup, con `T = MAX(section)` de la copia. Hay que contar con la forma real de un staging generado: el plan de la IA numera sus secciones **desde cero**, así que la sección 0 del staging no es una cabecera vacía sino la primera sección de contenido (con nombre y módulos). Si la sección 0 tiene módulos, nombre o resumen (`$materialise`), se **materializa** como una sección nueva `T+1` y las demás se desplazan `+T+1` para quedar detrás de ella; si está vacía, las demás se desplazan `+T`. En ambos casos la sección 0 del staging queda en blanco, de modo que su merge con la sección 0 de la copia no cambia nada. La renumeración es **transitoria**: `finally` repone el layout original del staging justo después del backup (y borra la sección materializada), de modo que un fallo posterior nunca deja el staging con huecos en la numeración ni filas de más. Al terminar, `extend_numsections` solo actúa si el formato de la copia expone la opción `numsections` (los formatos de core en 4.5, `format_topics` incluido, ya no la usan; formatos de terceros pueden hacerlo).

`classes/local/service/course_import_service.php:359-372`

```php
    private static function import_staging_into_copy(\stdClass $staging, int $copyid, int $userid, array &$tempdirs): void {
        $target = self::get_import_target();
        if ($target === \backup::TARGET_EXISTING_ADDING) {
            $backupid = self::backup_staging_appended((int)$staging->id, $copyid, $userid, $tempdirs);
            static::restore_course($backupid, $copyid, $userid, $target, []);
            self::extend_numsections($copyid);
            return;
        }
        $backupid = static::backup_course((int)$staging->id, \backup::MODE_IMPORT, $userid, $tempdirs, false);
        static::restore_course($backupid, $copyid, $userid, $target, [
            'keep_roles_and_enrolments' => true,
            'keep_groups_and_groupings' => true,
        ]);
    }
```

`classes/local/service/course_import_service.php:583-589`

```php
    private static function get_import_target(): int {
        $mode = (string)get_config('local_coursegen', 'importcontentmode');
        if ($mode === self::CONTENT_MODE_ADD) {
            return \backup::TARGET_EXISTING_ADDING;
        }
        return \backup::TARGET_EXISTING_DELETING;
    }
```

#### 4.7.1 Modo `add`: `backup_staging_appended` y sus ayudantes

`backup_staging_appended` captura el layout del staging (`snapshot_section_layout`: número, nombre, resumen y secuencia de cada sección, más la sección de cada módulo), calcula `T`, lee la sección 0 (`id, name, summary, summaryformat, sequence`) y decide `$materialise`: verdadero si la sección 0 tiene módulos, nombre o resumen. Desplaza las secciones `> 0` en `+T+1` cuando materializa (si no, en `+T`), materializa la sección 0 como sección `T+1`, reconstruye la caché y hace el backup en `MODE_IMPORT`; el `finally` repone el layout y vuelve a reconstruir la caché tanto si el backup salió bien como si lanzó.

`classes/local/service/course_import_service.php:394-418`

```php
    private static function backup_staging_appended(int $stagingid, int $copyid, int $userid, array &$tempdirs): string {
        global $DB;

        $snapshot = self::snapshot_section_layout($stagingid);
        try {
            $offset = (int)$DB->get_field_sql('SELECT MAX(section) FROM {course_sections} WHERE course = ?', [$copyid]);
            $zero = $DB->get_record(
                'course_sections',
                ['course' => $stagingid, 'section' => 0],
                'id, name, summary, summaryformat, sequence',
                MUST_EXIST
            );
            $zeromodules = self::section_zero_modules($stagingid, $zero);
            $materialise = $zeromodules || (string)$zero->name !== '' || (string)$zero->summary !== '';
            self::shift_section_numbers($stagingid, $materialise ? $offset + 1 : $offset);
            if ($materialise) {
                self::materialise_section_zero($stagingid, $zero, $zeromodules, $offset + 1);
            }
            rebuild_course_cache($stagingid, true);
            return static::backup_course($stagingid, \backup::MODE_IMPORT, $userid, $tempdirs, false);
        } finally {
            self::restore_section_layout($stagingid, $snapshot);
            rebuild_course_cache($stagingid, true);
        }
    }
```

`shift_section_numbers` suma `$offset` a toda sección `> 0`, en orden **descendente** por el índice único `(course, section)`; las secciones delegadas (`mod_subsection`) se desplazan igual, porque solo su número las ordena.

`classes/local/service/course_import_service.php:431-447`

```php
    private static function shift_section_numbers(int $courseid, int $offset): void {
        global $DB;

        if ($offset <= 0) {
            return;
        }
        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0',
            [$courseid],
            'section DESC',
            'id, section'
        );
        foreach ($sections as $section) {
            $DB->set_field('course_sections', 'section', $section->section + $offset, ['id' => $section->id]);
        }
    }
```

`section_zero_modules` devuelve los ids de los módulos cuya `course_modules.section` es la sección 0, en el orden de `sequence` y detrás los que no figuren en ella.

`classes/local/service/course_import_service.php:456-465`

```php
    private static function section_zero_modules(int $courseid, \stdClass $zero): array {
        global $DB;

        $cmids = array_map(
            'intval',
            $DB->get_fieldset_select('course_modules', 'id', 'course = ? AND section = ?', [$courseid, $zero->id])
        );
        $sequence = array_map('intval', array_filter(explode(',', (string)$zero->sequence)));
        return array_merge(array_values(array_intersect($sequence, $cmids)), array_values(array_diff($cmids, $sequence)));
    }
```

`materialise_section_zero` inserta una fila **nueva** de `course_sections` con el número `T+1` (debe estar libre: por eso el desplazamiento previo fue `+T+1`), copiando nombre, resumen y formato de la sección 0 y su secuencia de módulos en orden; reapunta `course_modules.section` de esos módulos a la fila nueva y deja la sección 0 con secuencia, nombre y resumen vacíos (el restore solo rellena nombre o resumen vacíos en la sección destino). La fila es transitoria: `restore_section_layout` la elimina tras el backup.

`classes/local/service/course_import_service.php:482-501`

```php
    private static function materialise_section_zero(int $courseid, \stdClass $zero, array $cmids, int $number): void {
        global $DB;

        $section = (object) [
            'course' => $courseid,
            'section' => $number,
            'name' => $zero->name,
            'summary' => (string)$zero->summary,
            'summaryformat' => (int)$zero->summaryformat,
            'sequence' => implode(',', $cmids),
            'visible' => 1,
            'timemodified' => time(),
        ];
        $section->id = $DB->insert_record('course_sections', $section);
        if ($cmids) {
            [$insql, $params] = $DB->get_in_or_equal($cmids);
            $DB->set_field_select('course_modules', 'section', $section->id, "id $insql", $params);
        }
        $DB->update_record('course_sections', (object) ['id' => $zero->id, 'sequence' => '', 'name' => null, 'summary' => '']);
    }
```

`restore_section_layout` borra las secciones que no existían en el snapshot (la materializada para la sección 0, si la hubo), devuelve cada módulo a su sección y repone los números en orden ascendente: cada sección baja a un número que ninguna otra fila puede ocupar todavía, así que el índice único no salta.

`classes/local/service/course_import_service.php:535-548`

```php
    private static function restore_section_layout(int $courseid, array $snapshot): void {
        global $DB;

        $current = $DB->get_fieldset_select('course_sections', 'id', 'course = ?', [$courseid]);
        foreach (array_diff($current, array_keys($snapshot['sections'])) as $id) {
            $DB->delete_records('course_sections', ['id' => $id]);
        }
        foreach ($snapshot['modules'] as $cmid => $sectionid) {
            $DB->set_field('course_modules', 'section', $sectionid, ['id' => $cmid]);
        }
        foreach ($snapshot['sections'] as $section) {
            $DB->update_record('course_sections', $section);
        }
    }
```

`extend_numsections` existe porque el paso `course.xml` del restore se omite al restaurar sobre un curso existente, así que nadie más subiría la opción tras anexar secciones. Es condicional: sale sin hacer nada si el formato no expone `numsections`.

`classes/local/service/course_import_service.php:560-576`

```php
    private static function extend_numsections(int $courseid): void {
        global $DB;

        $format = course_get_format($courseid);
        $options = $format->get_format_options();
        if (!isset($options['numsections'])) {
            return;
        }
        $last = (int)$DB->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ? AND component IS NULL',
            [$courseid]
        );
        if ($last > (int)$options['numsections']) {
            $format->update_course_format_options(['numsections' => $last]);
            rebuild_course_cache($courseid, true);
        }
    }
```

### 4.8 Éxito: reapuntar la sesión a la copia ANTES de borrar el staging

Al terminar, la sesión pasa a `STATUS_CREATED` con `courseid = copia` y `state = done` **antes** de borrar el staging: el observer de `course_deleted` elimina toda sesión ligada a un curso borrado, así que si el borrado fuese antes, la sesión desaparecería. El borrado del staging marca `deletesource = 'restore'`, que es lo que `tool_recyclebin_pre_course_delete()` respeta para no dejar copia en la papelera (ver `course_import_service.php:145-151` en el bloque 4.2).

`classes/local/service/course_import_service.php:760-770`

```php
    private static function delete_course_bypassing_bin(int $courseid): void {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }
        $course->deletesource = 'restore';
        delete_course($course, false);
        fix_course_sortorder();
    }
```

### 4.9 Rollback

Ante cualquier fallo, `rollback` borra la copia (si llegó a crearse), restaura los nombres y la visibilidad originales del destino (si se renombró; `$target->visible`/`visibleold` son los valores leídos antes de tocar nada, los mismos que guarda la sesión como `originalvisible`/`originalvisibleold`) y limpia los temporales. Si la copia no se pudo borrar, sigue adelante (restaurar los nombres importa más) y deja el `copycourseid` en la sesión. El staging **se conserva** a propósito: la sesión queda en `STATUS_FAILED` apuntando al staging, con `state = failed` y la clase de la excepción en `errorclass` (ver `course_import_service.php:128-141` en el bloque 4.2).

`classes/local/service/course_import_service.php:730-749`

```php
    private static function rollback(\stdClass $target, int $copyid, bool $renamed, array $tempdirs): bool {
        global $DB;

        $copydeleted = true;
        if ($copyid > 0) {
            try {
                self::delete_course_bypassing_bin($copyid);
            } catch (\Throwable $ignored) {
                // Keep going: restoring the original names matters more. The copy id stays in the session.
                $copydeleted = !$DB->record_exists('course', ['id' => $copyid]);
            }
        }
        if ($renamed) {
            self::set_course_names((int)$target->id, $target->shortname, $target->fullname);
            self::set_course_visibility((int)$target->id, (int)$target->visible, (int)$target->visibleold);
        }
        self::remove_temp_dirs($tempdirs);

        return $copydeleted;
    }
```

### 4.10 Persistencia del estado: el JSON `import` dentro de `coursedata`

Toda la contabilidad de la importación se guarda como objeto `import` dentro del JSON `coursedata` de la sesión; `update_session` hace un merge sobre las claves existentes y, opcionalmente, actualiza `status` y `courseid`.

`classes/local/service/course_import_service.php:803-818`

```php
    private static function update_session(course_session $session, ?int $status, ?int $courseid, array $import): void {
        $coursedata = json_decode((string)$session->get('coursedata'), true);
        if (!is_array($coursedata)) {
            $coursedata = [];
        }
        $existing = isset($coursedata['import']) && is_array($coursedata['import']) ? $coursedata['import'] : [];
        $coursedata['import'] = array_merge($existing, $import);
        $session->set('coursedata', json_encode($coursedata, JSON_UNESCAPED_UNICODE));
        if ($status !== null) {
            $session->set('status', $status);
        }
        if ($courseid !== null) {
            $session->set('courseid', $courseid);
        }
        $session->update();
    }
```

---

## 5. El estado de la sesión y el sondeo

### 5.1 `STATUS_IMPORTING` y `get_course_session_state`

El modelo `course_session` añade el estado `5`. El web service `get_course_session_state` lee el bloque `import` de `coursedata` con `describe_import` y expone `importstate` (`''|queued|running|done|failed`), `importmessage` (cadena localizada), `copycourseid` y, solo en `done`, `copycourseurl`.

`classes/local/models/course_session.php:44-45`

```php
    /** Status: generated course is being imported into an existing course. */
    const STATUS_IMPORTING = 5;
```

`classes/external/get_course_session_state.php:98-111`

```php
        $import = self::describe_import($coursedata);

        return $import + [
            'success' => true,
            'recordid' => (int)$session->get('id'),
            'sessionid' => $sessionid,
            'streamingurl' => (string)$streamingurl,
            'sessionstatus' => (int)$session->get('status'),
            'courseid' => (int)($session->get('courseid') ?? 0),
            'iscreated' => (int)$session->get('status') === course_session::STATUS_CREATED,
            'coursedatajson' => json_encode($coursedata, JSON_UNESCAPED_UNICODE),
            'snapshotjson' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ];
    }
```

`classes/external/get_course_session_state.php:119-143`

```php
    private static function describe_import(array $coursedata): array {
        $import = isset($coursedata['import']) && is_array($coursedata['import']) ? $coursedata['import'] : [];
        $messages = [
            course_import_service::STATE_QUEUED => 'import_queued',
            course_import_service::STATE_RUNNING => 'import_running',
            course_import_service::STATE_DONE => 'import_completed',
            course_import_service::STATE_FAILED => 'import_failed',
        ];
        $state = (string)($import['state'] ?? '');
        if (!isset($messages[$state])) {
            return ['importstate' => '', 'importmessage' => '', 'copycourseid' => 0];
        }

        $copycourseid = (int)($import['copycourseid'] ?? 0);
        $result = [
            'importstate' => $state,
            'importmessage' => get_string($messages[$state], 'local_coursegen'),
            'copycourseid' => $copycourseid,
        ];
        if ($state === course_import_service::STATE_DONE && $copycourseid > 0) {
            $result['copycourseurl'] = (new \moodle_url('/course/view.php', ['id' => $copycourseid]))->out(false);
        }

        return $result;
    }
```

`classes/external/get_course_session_state.php:173-181`

```php
            'importstate' => new external_value(
                PARAM_ALPHA,
                'Import into existing course: empty, queued, running, done or failed',
                VALUE_DEFAULT,
                ''
            ),
            'copycourseid' => new external_value(PARAM_INT, 'Course that replaced the target once imported', VALUE_DEFAULT, 0),
            'copycourseurl' => new external_value(PARAM_URL, 'URL of the copy course, present when done', VALUE_OPTIONAL),
            'importmessage' => new external_value(PARAM_TEXT, 'Localised message for the import state', VALUE_DEFAULT, ''),
```

### 5.2 La tarjeta `#importProgressView` y el sondeo

La tarjeta se renderiza siempre (no depende del ajuste) para poder reanudar una sesión iniciada cuando el ajuste estaba activo. `showImportProgressView` la muestra con el spinner, guarda la URL del staging como fallback para el botón "abrir curso generado" y, solo si el resultado recibido no es terminal, arranca el sondeo (con `importexecution = sync` la respuesta de `create_course` ya trae `done` o `failed` y no se sondea): cada **5 s** (`IMPORT_POLL_INTERVAL_MS`) llama a `getSessionState`, con un tope de **10 min** (`IMPORT_POLL_MAX_MS`), ignorando errores transitorios. `applyImportState` resuelve `done` (vista de finalización con `copycourseid`/`copycourseurl` e `importcompleted`) y `failed` (`renderImportFailed`, sin confeti, con el staging accesible).

`templates/courseai_page.mustache:621-635`

```mustache
      {{! Import-into-existing-course progress card (ticket #9539). Always rendered: a session
          started while the setting was on can be resumed after it was switched off. }}
      <div class="pc-completion-card" id="importProgressView" style="display:none">
        <span class="pc-completion-icon" aria-hidden="true">
          <svg id="importProgressSpinner" class="prv-spin" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
          </svg>
          <span class="pc-completion-emoji" id="importProgressFailedIcon" style="display:none">&#9888;&#65039;</span>
        </span>
        <h3 class="pc-completion-title" id="importProgressTitle">{{#str}}courseai_import_progress_title, local_coursegen{{/str}}</h3>
        <p class="pc-completion-summary" id="importProgressSummary">{{#str}}courseai_import_progress_body, local_coursegen{{/str}}</p>
        <div class="pc-completion-actions">
          <button class="btn-completion-secondary" id="btnOpenGeneratedCourse" type="button">{{#str}}courseai_import_btn_open_generated, local_coursegen{{/str}}</button>
        </div>
      </div>
```

`amd/src/local/courseai/actions.js:205-230`

```javascript
    // ---- Import into an existing course (ticket #9539) -------------------------------
    // The backend answers create_course with importqueued=true and the STAGING course.
    // In the background execution an adhoc task copies the target and imports the
    // staging into the copy, and the page polls the session state until importstate
    // is done|failed (capped). In the foreground execution the answer already carries
    // the terminal importstate (and copycourseid), so the terminal view is rendered
    // straight away and nothing is polled.
    const IMPORT_POLL_INTERVAL_MS = 5000;
    const IMPORT_POLL_MAX_MS = 10 * 60 * 1000;
    let importPollTimer = null;
    let importPollStartedAt = 0;
    let importPollInFlight = false;
    /** @type {string} URL of the staging (generated) course, the fallback while importing. */
    let generatedCourseUrl = '';

    /**
     * Stop polling the import state (terminal state, timeout or page unload).
     *
     * @returns {void}
     */
    const stopImportPolling = () => {
        if (importPollTimer) {
            window.clearInterval(importPollTimer);
            importPollTimer = null;
        }
    };
```

`amd/src/local/courseai/actions.js:324-345`

```javascript
    const showImportProgressView = (result) => {
        stopImportPolling();
        state.createdCourseResult = result || null;
        state.createdCourseUrl = result?.courseurl || '';
        state.currentStage = 'importing';
        generatedCourseUrl = String(result?.courseurl || '');
        finishGenerationLayout();
        if (completionView) { completionView.style.display = 'none'; }
        if (importProgressSpinner) { importProgressSpinner.style.display = ''; }
        if (importProgressFailedIcon) { importProgressFailedIcon.style.display = 'none'; }
        if (importProgressTitle) { importProgressTitle.textContent = texts.courseai_import_progress_title; }
        if (importProgressSummary) { importProgressSummary.textContent = texts.courseai_import_progress_body; }
        if (btnOpenGeneratedCourse) { btnOpenGeneratedCourse.disabled = !generatedCourseUrl; }
        if (importProgressView) { importProgressView.style.display = 'flex'; }
        if (applyImportState(result)) {
            return;
        }
        const recordid = Number(result?.recordid || state.sessionid || 0);
        if (recordid > 0) {
            startImportPolling(recordid);
        }
    };
```

`amd/src/local/courseai/actions.js:291-314`

```javascript
    const startImportPolling = (recordid) => {
        stopImportPolling();
        importPollStartedAt = Date.now();
        importPollInFlight = false;
        importPollTimer = window.setInterval(async() => {
            if (importPollInFlight) {
                return;
            }
            if (Date.now() - importPollStartedAt > IMPORT_POLL_MAX_MS) {
                stopImportPolling();
                if (importProgressSummary) { importProgressSummary.textContent = texts.courseai_import_timeout; }
                return;
            }
            importPollInFlight = true;
            try {
                const info = await CourseaiRepository.getSessionState(recordid);
                applyImportState(info);
            } catch (error) {
                // Transient failure: keep polling until the cap.
            } finally {
                importPollInFlight = false;
            }
        }, IMPORT_POLL_INTERVAL_MS);
    };
```

`amd/src/local/courseai/actions.js:256-283`

```javascript
    const applyImportState = (info) => {
        const importState = String(info?.importstate || '');
        if (importState === 'done') {
            // The copy is the course the user asked for; the staging course is gone.
            // get_course_session_state sends copycourseurl; create_course (foreground
            // execution) only sends copycourseid, so the URL is built from it.
            const copyCourseId = Number(info?.copycourseid || 0);
            const copyCourseUrl = String(info?.copycourseurl || '')
                || (copyCourseId > 0 ? M.cfg.wwwroot + '/course/view.php?id=' + copyCourseId : '');
            showCompletionView({
                success: true,
                importcompleted: true,
                courseid: copyCourseId,
                courseurl: copyCourseUrl || generatedCourseUrl,
            });
            return true;
        }
        if (importState === 'failed') {
            renderImportFailed(String(info?.importmessage || ''));
            return true;
        }
        if (importProgressSummary) {
            const progressText = importState === 'queued' ? texts.import_queued : texts.import_running;
            importProgressSummary.textContent = String(info?.importmessage || '')
                || (importState ? progressText : texts.courseai_import_progress_body);
        }
        return false;
    };
```

`amd/src/local/courseai/actions.js:240-248`

```javascript
    const renderImportFailed = (message) => {
        stopImportPolling();
        state.currentStage = 'import_failed';
        if (importProgressSpinner) { importProgressSpinner.style.display = 'none'; }
        if (importProgressFailedIcon) { importProgressFailedIcon.style.display = ''; }
        if (importProgressTitle) { importProgressTitle.textContent = texts.courseai_import_failed_title; }
        if (importProgressSummary) { importProgressSummary.textContent = message || texts.import_failed; }
        if (btnOpenGeneratedCourse) { btnOpenGeneratedCourse.disabled = !generatedCourseUrl; }
    };
```

### 5.3 Reanudar una sesión (`resume-snapshot.js`)

Al recargar o reabrir la página, el bootstrap lee `sessionstatus` e `importstate` del snapshot. Si el estado es `5` o hay un `importstate` no terminal, muestra la tarjeta de importación y retoma el sondeo (un `failed` se pinta de inmediato); si `importstate === 'done'`, va directo a la vista de finalización con la copia. En ese punto el `courseid` de la sesión ya es la copia, no el staging.

`amd/src/courseai/bootstrap/resume-snapshot.js:308-354`

```javascript
        const sessionStatus = Number(resume?.sessionstatus || 0);
        const hasCreatedStatus = sessionStatus === 3;
        const hasCreatedCourse = Number(resume?.courseid || 0) > 0;
        const isCreated = Boolean(resume?.iscreated) || hasCreatedStatus || hasCreatedCourse;
        // Import into an existing course (ticket #9539): status 5 means the adhoc import
        // is pending; importstate carries queued|running|done|failed once it ran.
        const importState = String(resume?.importstate || '');
        const isImportDone = importState === 'done';
        const isImporting = sessionStatus === 5 || (importState !== '' && !isImportDone);

        if (isCreated || isImporting) {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            }
            if (typeof detailedUi.enableAllActionControls === 'function') {
                detailedUi.enableAllActionControls();
            }

            const generatedCourse = {
                success: true,
                recordid: Number(resume?.recordid || resumeSessionId || 0),
                courseid: Number(resume?.courseid || 0),
                courseurl: buildCourseUrlFromResume(resume),
            };
            if (isImporting) {
                // Queued/running keeps polling; failed renders the error right away.
                actions.showImportProgressView({
                    ...generatedCourse,
                    importstate: importState,
                    importmessage: String(resume?.importmessage || ''),
                });
                return true;
            }
            if (isImportDone) {
                // The copy is the course the user asked for; the staging course is gone.
                actions.showCompletionView({
                    success: true,
                    importcompleted: true,
                    courseid: Number(resume?.copycourseid || 0),
                    courseurl: String(resume?.copycourseurl || '') || generatedCourse.courseurl,
                });
                return true;
            }
```

---

## 6. Resumen: Paso → Archivo → Función/método

| Paso | Archivo | Función / método |
| --- | --- | --- |
| 0. Ajuste de administrador | `settings.php` | `admin_setting_configcheckbox('local_coursegen/enableimportexisting')` |
| 0.1 Modo de importación | `settings.php` | `admin_setting_configselect('local_coursegen/importcontentmode')` + `hide_if` |
| 0. Flag al template | `aicoursecreation.php` | `$importexistingenabled` → `$templatecontext['importexistingenabled']` |
| 0. Flag al JS | `templates/courseai_page.mustache` | `data-import-existing-enabled` en `#courseReviewPanel` |
| 1. Selector de modo y campos | `templates/courseai_page.mustache` | `#targetModeGroup` / `#targetMode`, `#importTargetFields`, `#importCategory`, `#importCourse` |
| 1. Toggling / validación | `amd/src/local/courseai/actions/course-create.js` | `setupImportControls` (`getMode`, `getTargetCourseId`, `syncConfirm`, `applyMode`, `reset`) |
| 2. Autocomplete categoría | `amd/src/local/courseai/actions/course-create.js` | `reset` → `enhanceSelect(…, false, …)` |
| 2. Autocomplete curso | `amd/src/local/courseai/actions/course-create.js` | `buildCourseField` → `enhanceSelect(…, TARGET_COURSE_DATASOURCE, …)` |
| 2. Datasource AJAX | `amd/src/local/courseai/target-course-datasource.js` | `transport`, `processResults` |
| 2. Declaración WS | `db/services.php` | `local_coursegen_search_target_courses` |
| 2. Búsqueda de cursos | `classes/external/search_target_courses.php` | `execute`, `matches`, `execute_returns` |
| 3. Confirmar (UI) | `amd/src/local/courseai/actions/course-create.js` | `onConfirm`, `createCourseFromSession` |
| 3. Repositorio | `amd/src/repository/course.js` | `createCourse` |
| 3. WS de creación | `classes/external/create_course.php` | `execute_parameters`, `execute`, `require_import_target`, `get_import_service`, `execute_returns` |
| 3. Staging | `classes/local/service/create_course_service.php` | `create_course` (categoría forzada a la del destino) |
| 4. Encolado | `classes/task/import_generated_course_task.php` | `queue` → `course_import_service::mark_queued` |
| 4. Ejecución / sin reintentos | `classes/task/import_generated_course_task.php` | `execute`, `retry_until_success` |
| 4.1.1 Modo de ejecución | `settings.php` | `admin_setting_configselect('local_coursegen/importexecution')` + `hide_if` |
| 4.1.1 Ejecución en la petición (`sync`) | `classes/external/create_course.php` | `execute` (rama `IMPORTEXECUTION_SYNC`) → `get_import_service` → `course_import_service::mark_queued`, `import_into_existing` |
| 4. Importación | `classes/local/service/course_import_service.php` | `import_into_existing`, `require_import_capabilities`, `backup_course`, `rename_original_and_create_copy`, `compute_renamed_names`, `set_course_visibility`, `restore_copy_with_original_names`, `import_staging_into_copy`, `get_import_target`, `restore_course`, `set_owned_setting_value`, `rollback`, `delete_course_bypassing_bin`, `update_session` |
| 4.7 Modo `add` (anexar) | `classes/local/service/course_import_service.php` | `backup_staging_appended`, `snapshot_section_layout`, `shift_section_numbers`, `section_zero_modules`, `materialise_section_zero`, `restore_section_layout`, `extend_numsections` |
| 5. Estado | `classes/local/models/course_session.php` | `STATUS_IMPORTING = 5` |
| 5. Lectura de estado | `classes/external/get_course_session_state.php` | `execute`, `describe_import`, `execute_returns` |
| 5. Tarjeta y sondeo | `amd/src/local/courseai/actions.js` | `showImportProgressView`, `startImportPolling`, `applyImportState`, `renderImportFailed`, `stopImportPolling` |
| 5. Reanudar | `amd/src/courseai/bootstrap/resume-snapshot.js` | bloque `isImporting` / `isImportDone` |

## Dónde vive el estado

No hay cambio de esquema. Todo el estado de la importación se guarda en la fila existente de `local_coursegen_course_sessions`: el campo `status` toma el nuevo valor `STATUS_IMPORTING = 5` mientras corre la importación (en la tarea adhoc o en la propia petición de `create_course`) (y vuelve a `STATUS_CREATED` o `STATUS_FAILED` al terminar), `courseid` apunta al staging durante el proceso y a la copia al finalizar (o al staging si falló), y el campo `coursedata` lleva un objeto JSON `import` con `targetcourseid`, `stagingcourseid`, `copycourseid`, `originalshortname`, `originalfullname`, `originalvisible`, `originalvisibleold`, `renamedshortname`, `state` (`queued|running|done|failed`), `errorclass`, `timestarted` y `timefinished`. `get_course_session_state` traduce ese objeto a `importstate`, `importmessage`, `copycourseid` y `copycourseurl` para el navegador.
