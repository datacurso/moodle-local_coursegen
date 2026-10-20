# Importación de un curso generado por IA en un curso existente (ticket #9539)

Documento técnico del flujo de importación del plugin `local_coursegen` (rama `import-course`). Las referencias `archivo:línea` son relativas a la raíz del plugin salvo que se indique una ruta de core de Moodle.

## Índice

1. [Resumen](#1-resumen)
2. [Ajustes de administrador](#2-ajustes-de-administrador)
3. [Permisos](#3-permisos)
   - [3.1 Portón de creación (`creation_permissions`)](#31-portón-de-creación-creation_permissions)
   - [3.2 Conjunto de importación (`import_permissions`)](#32-conjunto-de-importación-import_permissions)
   - [3.3 Remapeo de roles al restaurar](#33-remapeo-de-roles-al-restaurar)
   - [3.4 Nota de cachés](#34-nota-de-cachés)
   - [3.5 Roles creados en el sitio](#35-roles-creados-en-el-sitio)
4. [Interfaz](#4-interfaz)
5. [Proceso de importación paso a paso](#5-proceso-de-importación-paso-a-paso)
6. [Ejecución en primer o segundo plano](#6-ejecución-en-primer-o-segundo-plano)
7. [Rollback y fallos](#7-rollback-y-fallos)
8. [Estado de la sesión](#8-estado-de-la-sesión)
9. [Archivos involucrados](#9-archivos-involucrados)
10. [Pendientes conocidos](#10-pendientes-conocidos)

## 1. Resumen

- Con el ajuste `enableimportexisting` activado, el panel de confirmación permite elegir entre crear un curso nuevo o importar el contenido generado en un curso existente.
- En modo importación la IA materializa el resultado en un curso de **staging** creado en la categoría del destino; después el destino se **copia con todos sus datos de usuario** (matriculaciones, roles, grupos, calificaciones, finalización y datos de usuario de las actividades), el staging se **importa en la copia** (reemplazando o añadiendo contenido según `importcontentmode`) y el staging se **borra**.
- El curso original queda **renombrado y oculto** como respaldo; la copia conserva el nombre completo, el nombre corto y la categoría originales.
- Existe el staging porque `create_course_service::process_course_sections()` llama a `delete_course_sections()` de forma incondicional (`create_course_service.php:374-382`): generar directamente sobre un curso existente lo vaciaría.
- La importación corre en una tarea adhoc (por defecto) o dentro de la propia petición de `create_course` (`importexecution`). No hay cambio de esquema: todo el estado vive en la fila de `local_coursegen_course_sessions`.

## 2. Ajustes de administrador

Los tres ajustes viven en `settings.php` bajo `local_coursegen/`.

| Ajuste | Tipo | Valor por defecto | Ubicación |
| --- | --- | --- | --- |
| `enableimportexisting` | checkbox | desactivado | `settings.php:56-63` |
| `importcontentmode` | select `replace` / `add` | `replace` | `settings.php:65-78` |
| `importexecution` | select `adhoc` / `sync` | `adhoc` | `settings.php:80-93` |

- **`enableimportexisting`**: apagado, el flujo de creación queda intacto. La página calcula `importexistingenabled` con `import_permissions::is_offered()` (`aicoursecreation.php:122`), de modo que los controles solo se renderizan con el ajuste activado y el conjunto de importación completo en al menos un curso. Los dos web services de importación vuelven a comprobar el ajuste y lanzan `error_import_existing_disabled` (`search_target_courses.php:92`, `create_course.php:232`).
- **`importcontentmode`**: decide el target del restore del staging sobre la copia; lo lee únicamente `get_import_target` (`course_import_service.php:577-583`). `replace` borra el contenido de la copia antes de volcar el generado (`TARGET_EXISTING_DELETING`). `add` conserva secciones y actividades existentes y **anexa** las generadas detrás de la última, cada una con su nombre y actividades (`TARGET_EXISTING_ADDING`); como el plan de la IA numera sus secciones desde cero, la sección 0 del staging se **materializa** como sección nueva, la numeración del staging se desplaza de forma **transitoria** para el backup y, al terminar, `extend_numsections` sube la opción `numsections` si el formato de la copia la expone (ver [5](#5-proceso-de-importación-paso-a-paso)). Se oculta con `hide_if` cuando `enableimportexisting` está desmarcado.
- **`importexecution`**: `adhoc` encola `import_generated_course_task` y la página sondea el estado; `sync` ejecuta copia e importación dentro de la petición de `create_course`. Lo lee únicamente `create_course::execute` (`create_course.php:179`). Mismo `hide_if` que el anterior.
- Ni `importcontentmode` ni `importexecution` llegan a la plantilla ni al JS.

## 3. Permisos

### 3.1 Portón de creación (`creation_permissions`)

Portón previo y común a todo el flujo de creación con IA, definido en `classes/local/service/creation_permissions.php`.

- **Conjunto**: todas las capacidades que el arquetipo `editingteacher` permite por defecto (`get_default_capabilities`, solo `CAP_ALLOW`, calculadas en tiempo de ejecución, descartando capacidades no instaladas y memoizadas por petición, `creation_permissions.php:81-99`) más las cuatro de `EXTRA_CAPABILITIES`: `local/coursegen:createactivitywithai`, `local/coursegen:createcoursewithai`, `moodle/course:create` y `moodle/course:view` (`creation_permissions.php:42-66`). Equivale al rol «Profesor Creador de curso IA».
- **Contexto**: `local/coursegen:createcoursewithai` (`SYSTEM_CAPABILITIES`) se exige sobre el contexto de sistema; el resto sobre la categoría destino, de la que hereda el curso nuevo.
- **Entradas**: `require_for_category($categoryid)` (primero las de sistema, luego la lista ordenada, así la excepción nombra siempre la misma capacidad), `can_create_in($categoryid)` (`has_all_capabilities`) y `can_create_anywhere()` / `require_anywhere()`.
- **Regla "en alguna categoría"**: administrador → sí. Si no, candidatas = categorías donde tiene `moodle/course:create`, en orden de `sortorder`, con tope `CANDIDATE_LIMIT = 200` (consulta propia con contextos precargados), hasta la primera que cumpla el conjunto. Quien supere el tope sin cumplirlo en ninguna se trata como no apto.
- **Capacidad reportada por `require_anywhere`** (`creation_permissions.php:164-210`), de forma determinista: (1) una de sistema que falte; (2) si no tiene `moodle/course:create` en ninguna categoría, esa sobre el contexto de sistema; (3) en otro caso, la primera que falte (orden alfabético) en la categoría candidata con menos ausencias, la más temprana en caso de empate.
- **Dónde se exige**:
  - página por URL: `aicoursecreation.php:34` (`require_anywhere`);
  - botón «Crear con IA» de Mis cursos: `mycourses_header_hook.php:155` y chat `chat_hook.php:177` (`can_create_anywhere`);
  - los siete servicios AJAX del flujo, todos con `require_anywhere`: `start_course_planning.php:90`, `course_planning_feedback.php:100`, `get_course_settings.php:76`, `get_course_session_state.php:82`, `courseai_syllabus_upload.php:121`, `courseai_filepicker_init.php:72`, `create_course.php:131`;
  - `get_course_settings.php:94` filtra además las categorías ofrecidas con `can_create_in`, de modo que el panel solo lista categorías aceptables;
  - categoría efectiva: `create_course_service.php:56` (`require_for_category`) y, en modo importación, `create_course.php:245` sobre la categoría del destino, **antes** del conjunto de importación.
- **Consecuencia**: el arquetipo `manager` de serie carece de doce capacidades que `editingteacher` sí permite por defecto, así que un Gestor estándar no pasa el portón (el administrador del sitio pasa siempre). Coste: hasta unas 508 llamadas a `has_capability` por categoría candidata, en memoria y sin caché entre peticiones.

### 3.2 Conjunto de importación (`import_permissions`)

Única fuente del conjunto, en `classes/local/service/import_permissions.php`.

- **`COURSE_CAPABILITIES`** (contexto del curso destino, `import_permissions.php:39-54`): `moodle/backup:backupcourse`, `moodle/restore:restorecourse`, `moodle/course:visibility` (el original se oculta) y `moodle/backup:userinfo` (la copia lleva los datos de usuario del original).
- **`CATEGORY_CAPABILITIES`** (contexto de la categoría del destino): `moodle/course:create` y `moodle/restore:userinfo` (la copia aún no existe y hereda de esa categoría; el motor comprueba el lado restore sobre el curso destino de la restauración, `backup/util/checks/restore_check.class.php:65` y `:121`).
- `moodle/backup:userinfo` y `moodle/restore:userinfo` son de arquetipo Gestor: hay que añadirlas al rol que use la función.
- **`require_for_target`** exige las seis en el orden fijo `CHECK_ORDER` (`import_permissions.php:56-93`), así la `required_capability_exception` nombra siempre la misma capacidad. **`can_import_into`** comprueba el mismo conjunto con `has_all_capabilities` (`import_permissions.php:102-105`).
- **`can_import_anywhere`** (`import_permissions.php:118-147`): administrador → `true`; si no, una sola consulta `get_user_capability_course('moodle/backup:userinfo')` (la capacidad más rara del conjunto) con tope `CANDIDATE_LIMIT = 500`, precarga de contexto, salto de `SITEID` y `can_import_into` candidato a candidato hasta el primero que cumpla. Coste máximo: una consulta más 500 comprobaciones por carga de página. **`is_offered`** = ajuste activado y `can_import_anywhere` (`import_permissions.php:155-157`).
- **Dónde se exige**:
  - la página: `aicoursecreation.php:122` (`is_offered`);
  - el buscador de destinos descarta curso a curso los que no cumplen `can_import_into` (`search_target_courses.php:107`), así solo se ofrecen destinos que el servidor aceptará;
  - `create_course.php:246` (`require_for_target`), antes de materializar nada;
  - dentro del servicio, `require_import_capabilities` (`course_import_service.php:218-221`) repite `require_for_target` y añade `moodle/backup:backuptargetimport` sobre el staging, que no existía cuando se validó en `create_course`.

### 3.3 Remapeo de roles al restaurar

Comportamiento de core que afecta a la copia:

- El motor no copia las asignaciones de rol tal cual: `restore_dbops::precheck_included_roles` (`backup/util/dbops/restore_dbops.class.php:185`) llama a `get_best_assignable_role` (`:362-398`), que busca el mejor rol **asignable por el usuario que restaura** en el contexto de la copia: coincidencia por shortname, después por arquetipo, después `editingteacher` → `teacher`; sin coincidencia el rol se omite (aviso `cannotfindassignablerole` en el precheck).
- La lista sale de `get_assignable_roles()` con el `userid` del restaurador: un administrador asigna cualquier rol (`lib/accesslib.php:3281`), pero un rol sin permiso para asignar `editingteacher` verá a los demás profesores con permiso de edición convertidos en profesores sin edición en la copia. Solución: pestaña «Permitir asignaciones de roles» en la definición del rol.
- `restore_fix_restorer_access_step` (`backup/moodle2/restore_stepslib.php:2560-2605`, registrado en `restore_root_task.class.php:46`) comprueba que el restaurador tenga `moodle/course:update` en la copia; si no, le asigna `$CFG->restorernewroleid` cuando está definido y, en último término, lo matricula manualmente.

### 3.4 Nota de cachés

`creation_permissions` e `import_permissions` son clases nuevas: tras desplegar la rama hay que purgar las cachés para que `core_component` reconstruya el mapa de clases y se invaliden las cachés de acceso; hasta entonces las páginas y servicios que las usan fallan con clase no encontrada o aplican definiciones de rol antiguas.

### 3.5 Roles creados en el sitio

Dos roles de sitio, ambos de arquetipo `editingteacher`, asignables en los contextos Sistema, Curso y Módulo de actividad. Datos verificados en la base de datos de desarrollo.

| | Rol 1 | Rol 2 |
| --- | --- | --- |
| Shortname | `editingteachercoursegenai` | `editingteachercoursegenimportai` |
| Nombre | Profesor Creador de curso IA | Importador Creador de curso IA |
| Capacidades permitidas | 510 | 512 |
| Quitadas respecto al arquetipo | 0 | 0 |
| Añadidas respecto al arquetipo | `moodle/course:create`, `local/coursegen:createcoursewithai`, `moodle/course:view`, `block/globalsearch:addinstance`, `block/lp:addinstance` | Las cinco anteriores más `moodle/backup:userinfo` y `moodle/restore:userinfo` |
| Permite asignar roles | `teacher`, `student` | `teacher`, `student` |
| Resultado | Ve el botón y crea cursos nuevos con IA; **no** ve la opción de importar | Ve el botón, crea cursos nuevos y ve «Importar en un curso existente» |

- **Rol 1** es exactamente el conjunto que exige `creation_permissions`. `moodle/course:view` permite ver cursos sin participar (el creador no queda matriculado). Las dos capacidades de bloques son accesorias: no las exige el portón. `local/coursegen:createactivitywithai` no hace falta añadirla porque ya viene en el arquetipo. No ve la opción de importar porque le faltan `moodle/backup:userinfo` y `moodle/restore:userinfo`.
- **Rol 2** añade las dos capacidades de datos de usuario, ambas de arquetipo Gestor. Las otras cuatro del conjunto de importación (`moodle/backup:backupcourse`, `moodle/restore:restorecourse`, `moodle/course:visibility`, `moodle/backup:backuptargetimport`) ya las trae el arquetipo `editingteacher`. Como solo puede asignar `teacher` y `student`, aplica la consecuencia de [3.3](#33-remapeo-de-roles-al-restaurar): los demás profesores con permiso de edición del original quedan como profesores sin permiso de edición en la copia; para evitarlo hay que permitirle asignar `editingteacher` en «Permitir asignaciones de roles».
- Ambos roles se asignan en el contexto de Sistema, de modo que aplican a todos los cursos del sitio (compromiso aceptado). El rol Gestor de serie no pasa el portón de creación (le faltan doce capacidades de `editingteacher`); el administrador del sitio pasa siempre.
- **Creación automática**: `role_installer::install_missing_roles()` (`classes/local/role_installer.php:22`) crea los dos roles en la instalación (`db/install.php:37`) y en la actualización a la versión `2026102000` (`db/upgrade.php:275-281`). Solo crea el rol cuya `shortname` no existe; nunca modifica ni borra uno existente, aunque sus capacidades difieran. Cada rol se construye con `create_role` + `reset_role_capabilities` (arquetipo `editingteacher`) + las extras en contexto de Sistema (`creation_permissions::EXTRA_CAPABILITIES`, más `backup:userinfo` y `restore:userinfo` para el importador), contextos Sistema/Curso/Módulo y asignación permitida de `teacher` y `student`; no incluye las dos capacidades de bloques accesorias. Nombre y descripción salen de las cadenas `role_creator_*` / `role_importer_*` en el idioma del sitio en el momento de crearlos. En una instalación limpia registra antes las capacidades del plugin (`update_capabilities`), porque Moodle ejecuta `install.php` antes de registrarlas.

## 4. Interfaz

- **Flag**: `aicoursecreation.php:122` → contexto `importexistingenabled` → atributo `data-import-existing-enabled` de `#courseReviewPanel` (`courseai_page.mustache:665-667`) → `setupImportControls` sale sin hacer nada si no vale `1` (`course-create.js:91-94`).
- **Campos** (`courseai_page.mustache:683-692` y `:712-726`, solo con el flag activo): `<select id="targetMode">` con opciones `new` (seleccionada por defecto) e `import` como **primer campo** del panel, encima de `#reviewFullname`; bloque oculto `#importTargetFields` con `#importCategory` e `#importCourse`. Los tres campos del curso nuevo van envueltos en `#reviewFullnameGroup`, `#reviewShortnameGroup` y `#reviewCategoryGroup`.
- **Modo** (`course-create.js:137-152`): `applyMode` en `import` muestra los campos de destino y oculta los tres grupos (la copia conserva nombres y categoría del destino); `syncConfirm` deshabilita el botón de confirmar hasta que haya curso destino. El controlador se construye una sola vez y `reset` lo reinicia en cada apertura del panel devolviendo el select a `new` (`course-create.js:161-175`, invocado en `:233-242`).
- **Autocompletado de categoría**: `#importCategory` recibe la misma lista que `#reviewCategory` (la de `getCourseSettings`, ya filtrada por `can_create_in`) y se mejora con `core/form-autocomplete` sin datasource remoto, una sola vez. Toda mejora pasa por `enhanceSelect` (`course-create.js:71-77`), único punto que llama a `FormAutocomplete.enhance`.
- **Autocompletado de curso**: `#importCourse` se reconstruye desde su marcado original en cada cambio de categoría (`buildCourseField`, `course-create.js:121-136`) porque form-autocomplete no relee el select, y se mejora con `target-course-datasource.js`: `transport` (`:47-63`) lee `#importCategory` en el momento de la petición y llama a `local_coursegen_search_target_courses` con `categoryid`, `query` y `limit`; `processResults` (`:72-75`) mapea a `{value: id, label: displayname}`.
- **Servicio de búsqueda**: declarado en `db/services.php:151-159` (tipo `read`, `ajax`). `search_target_courses.php:88-125` comprueba, en orden, contexto de sistema y `local/coursegen:createcoursewithai`, el ajuste, la existencia de la categoría y `moodle/course:create` sobre ella; recorre los cursos de la categoría de forma recursiva, filtra por texto sobre fullname/shortname en minúsculas, descarta los que no cumplen `can_import_into`, acota `limit` a 1..50 (20 por defecto) y devuelve `displayname` = "fullname (shortname)".
- **Envío** (`onConfirm`, `course-create.js:253-281`): en modo `import` resuelve **solo** `targetmode = 'import'` y `targetcourseid`, sin leer los inputs; en modo `new` resuelve `targetmode = 'new'` más `fullname`, `shortname` y `category` si tienen valor. `createCourseFromSession` copia los campos al payload (`course-create.js:347-354`) y `repository/course.js:79-104` los reenvía a `local_coursegen_create_course`.
- **Respuesta** (`create_course.php:280-317`): `importqueued`, `targetcourseid`, `importstate` (`''` sin importación, `queued` en segundo plano, `done`/`failed` en primer plano) y `copycourseid` (solo `sync` con éxito). `courseid` es siempre el **staging**. `createCourseFromSession` bifurca por `importqueued` (`course-create.js:363-375`): tarjeta de importación o vista de finalización habitual.
- **Vista de progreso**: `#importProgressView` (`courseai_page.mustache:621-635`) se renderiza siempre, independientemente del ajuste, para poder reanudar sesiones. `showImportProgressView` (`actions.js:324-345`) muestra el spinner, guarda la URL del staging como fallback del botón `#btnOpenGeneratedCourse` y aplica primero el resultado recibido: si `importstate` ya es terminal no sondea.
- **Polling** (`startImportPolling`, `actions.js:291-314`): cada **5 s** (`IMPORT_POLL_INTERVAL_MS`) llama a `getSessionState`, con tope de **10 min** (`IMPORT_POLL_MAX_MS`), ignorando errores transitorios y evitando solapar peticiones; al vencer el tope detiene el sondeo y muestra `courseai_import_timeout`.
- **Estados** (`applyImportState`, `actions.js:256-283`): `done` → vista de finalización con `importcompleted`, `copycourseid` y la URL de la copia (`copycourseurl` si llega, si no construida desde el id); `failed` → `renderImportFailed` (`actions.js:240-248`), sin confeti, con el botón de abrir el curso generado activo; `queued`/`running` → actualiza el texto con `importmessage`.
- **Reanudación** (`resume-snapshot.js:308-354`): con `sessionstatus = 5` o un `importstate` no terminal muestra la tarjeta y retoma el sondeo (`failed` se pinta de inmediato); con `importstate = 'done'` va directo a la vista de finalización con la copia. En ese punto el `courseid` de la sesión ya es la copia.

## 5. Proceso de importación paso a paso

Orquestado por `course_import_service::import_into_existing` (`course_import_service.php:84-159`), invocado por la tarea adhoc o por la rama `sync`. Pasos previos en `create_course::execute`:

- `require_import_target` (`create_course.php:229-249`), **antes de pedir el resultado a la API y de materializar nada**: ajuste activado, `targetcourseid > 0`, el curso existe y no es `SITEID` (`error_import_target_invalid`), conjunto de creación sobre la categoría del destino y conjunto de importación. Una denegación no deja staging.
- La categoría del staging se fuerza a la del destino (`create_course.php:165-169`) y `create_course_service::create_course` lo genera.

Secuencia del servicio:

1. **Carga y capacidades**: destino y staging con `MUST_EXIST`; `require_import_capabilities` (seis del destino más `backuptargetimport` sobre el staging).
2. **Sesión a `STATUS_IMPORTING`** con el bloque `import` en `running`, guardando nombres y visibilidad originales del destino y `timestarted`.
3. **Backup del destino con usuarios** (`backup_course`, `course_import_service.php:596-638`): `MODE_SAMESITE`, `withusers = true`, hecho **antes** del renombrado porque el motor toma los nombres del backup cuando los ajustes de nombre están bloqueados. El ajuste `users` solo se escribe si no está bloqueado; el motor lo fuerza a `false` sin `moodle/backup:userinfo` y lo bloquea en su valor por defecto sin `moodle/backup:configure` (`backup/util/checks/backup_check.class.php:159` y `:222`). Si se pidieron usuarios y el ajuste acaba apagado lanza `error_import_users_required`. Fuera de `MODE_IMPORT` descomprime el `.mbz` resultante en el directorio temporal y borra el archivo almacenado.
4. **Transacción: renombrar y ocultar el original + crear la copia** (`rename_original_and_create_copy`, `course_import_service.php:234-245`). No hay índice único en `course.shortname`, así que ambas operaciones comparten una transacción para cerrar la ventana en que otra petición ocupe el shortname liberado.
   - `compute_renamed_names` (`:190-204`) sigue el patrón de `delete_user()`: prefijo truncado, punto y marca de 10 dígitos, incrementada mientras el shortname exista; el fullname recibe el sufijo ` (marca)`.
   - `set_course_names` (`:255`) escribe los nombres y purga cachés.
   - `set_course_visibility(…, 0, 0)` (`:284-307`) escribe `visible` y `visibleold` directamente porque `course_change_visibility()` fuerza `visibleold = visible` y perdería un curso oculto por su categoría; reconstruye y purga cachés y dispara `course_updated`.
   - `restore_dbops::create_new_course` crea la copia con los nombres originales. La sesión guarda `copycourseid` y `renamedshortname`.
5. **Restore de la copia** (`restore_copy_with_original_names`, `course_import_service.php:326-336` → `restore_course`, `:653-682`): `TARGET_NEW_COURSE`, `MODE_SAMESITE`, con `course_fullname`/`course_shortname` originales y los ajustes de una restauración completa: `users`, `enrolments = ENROL_WITHUSERS`, `role_assignments`, `userscompletion`, `grade_histories` (grupos, miembros y datos de usuario de las actividades siguen a `users`). `restore_course` ignora los ajustes que el plan no expone, escribe los de `OWNED_RESTORE_SETTINGS` con `set_owned_setting_value` y el resto solo si no están bloqueados; lanza `error_import_users_required` si `users` queda apagado y `error_import_precheck_failed` si el precheck reporta errores.
6. **Importación del staging en la copia** (`import_staging_into_copy`, `course_import_service.php:353-366`), con el target de `get_import_target`. Los backups del staging van siempre sin usuarios (no tiene).
   - **`replace`**: backup en `MODE_IMPORT` y restore `TARGET_EXISTING_DELETING` con `keep_roles_and_enrolments` y `keep_groups_and_groupings`, de modo que el borrado previo conserva matriculaciones, asignaciones de rol, grupos y agrupamientos. Esos dos ajustes no tienen capacidad propia y sin `moodle/restore:configure` el motor bloquea por permiso todos los ajustes en su valor por defecto (`backup/util/checks/restore_check.class.php:154`): `OWNED_RESTORE_SETTINGS` (`course_import_service.php:71-72`) y `set_owned_setting_value` (`:699-711`) levantan el candado por permiso solo para esos dos, escriben y lo reponen, como hace `core\task\asynchronous_copy_task` con `users` (`lib/classes/task/asynchronous_copy_task.php:83-86`); un `LOCKED_BY_CONFIG` se respeta y el ajuste se deja como está.
   - **`add`**: el motor empareja secciones **por número** y con `TARGET_EXISTING_ADDING` una sección entrante cuyo número existe se mezclaría con la de la copia; para anexar, el staging se renumera antes del backup.
     - `backup_staging_appended` (`course_import_service.php:388-412`) captura el layout del staging (`snapshot_section_layout`, `:503`), calcula `T = MAX(section)` de la copia y decide si materializar la sección 0 (tiene módulos, nombre o resumen).
     - `shift_section_numbers` (`:425-441`) suma `T+1` (o `T` si no se materializa) a toda sección mayor que cero, en orden descendente por el índice único `(course, section)`, secciones delegadas incluidas.
     - `materialise_section_zero` (`:476-495`) inserta una fila nueva con número `T+1`, copia nombre, resumen y secuencia, reapunta `course_modules.section` y deja la sección 0 vacía, de modo que su merge con la sección 0 de la copia no cambia nada.
     - Tras reconstruir la caché hace el backup en `MODE_IMPORT`; el bloque `finally` ejecuta `restore_section_layout` (`:529-542`), que borra la fila materializada, devuelve cada módulo a su sección y repone los números en orden ascendente: la renumeración es **transitoria** y un fallo posterior nunca deja huecos.
     - El restore va sin ajustes y, al terminar, `extend_numsections` (`:554-570`) sube `numsections` hasta la última sección solo si el formato lo expone (los formatos de core en 4.5 no lo usan).
7. **Limpieza de temporales** (`remove_temp_dirs`, `course_import_service.php:772`).
8. **Sesión apuntando a la copia**: `STATUS_CREATED`, `courseid = copia`, `state = done`, `timefinished`, **antes** de borrar el staging: el observer de `course_deleted` (`db/events.php:30`, `observer.php:36`) elimina toda sesión ligada a un curso borrado.
9. **Borrado del staging sin papelera** (`delete_course_bypassing_bin`, `course_import_service.php:754-764`): marca `deletesource = 'restore'`, que `tool_recyclebin_pre_course_delete()` respeta para no dejar copia, llama a `delete_course($course, false)` y a `fix_course_sortorder()`.
10. **Evento** `generation_result_applied` en el contexto de la copia (`course_import_service.php:152`) y retorno `success`, `copycourseid`, `errorclass` vacío.

Datos de usuario que sobreviven en la copia:

| Modo | Se conserva | Se pierde |
| --- | --- | --- |
| `replace` | Matriculaciones, asignaciones de rol, grupos y agrupamientos (`keep_*`) | Datos de usuario ligados a las actividades reemplazadas: calificaciones, intentos, entregas |
| `add` | Todo lo anterior y los datos de usuario de las actividades originales, que no se tocan | Nada |

## 6. Ejecución en primer o segundo plano

La rama está en `create_course.php:179-194`, tras crear el staging, leyendo `importexecution`.

**`adhoc` (por defecto)**

- `import_generated_course_task::queue` (`import_generated_course_task.php:43-54`) marca la sesión como `queued` (`course_import_service::mark_queued`, `course_import_service.php:169-177`) y encola la tarea con `sessionid`, `stagingcourseid` y `targetcourseid` como custom data y `set_userid` del solicitante: la tarea **corre como el usuario que la encoló**. `execute` (`:70-78`) delega en `import_into_existing`; `retry_until_success` devuelve `false` (`:80-89`).
- La respuesta lleva `importstate = 'queued'`; la página sondea ([4](#4-interfaz)).
- La tarea queda en la tabla `task_adhoc` y la ejecuta el cron del sitio (recomendado cada minuto). `core\task\manager::get_next_adhoc_task` reparte por clase (las menos cargadas primero) y dentro de cada clase por `nextruntime` (`lib/classes/task/manager.php:915`); la concurrencia la limita `task_adhoc_concurrency_limit` (`:692`).
- Con el cron deshabilitado (`cron_enabled`) la tarea espera indefinidamente y la sesión queda en `queued`; se fuerza desde la línea de comandos con `php admin/cli/adhoc_task.php --execute --force` (`admin/cli/adhoc_task.php:109`), opcionalmente con `--id`.
- Los límites de tiempo de la petición afectan solo a la generación del staging.

**`sync`**

- `create_course::execute` obtiene la clase del servicio con `get_import_service()` (`create_course.php:263-273`, factoría protegida para que PHPUnit inyecte una subclase), llama a `mark_queued` y después a `import_into_existing` sin `try/catch`: el servicio no lanza por un fallo de importación, hace rollback y lo devuelve en su resultado. La respuesta lleva `importstate = done|failed` y, solo con éxito, `copycourseid`; la tarjeta pinta el estado terminal sin sondear.
- Toda la importación (backup, renombrado, copia, restore, importación, borrado y, si procede, rollback) corre dentro de la petición HTTP: no interviene cron. Debe caber en `CREATE_COURSE_TIMEOUT_MS` = 180 s del cliente (`course-create.js:320`) y en el `Timeout` de Apache o equivalente. Al vencer el tope del cliente el navegador rechaza con `courseai_error_connection` (`course-create.js:356`) aunque el servidor siga ejecutando; el estado real queda en la sesión y se recupera al reanudar.

## 7. Rollback y fallos

- Cualquier `Throwable` dentro del `try` de `import_into_existing` entra en `rollback` (`course_import_service.php:724-743`), que **deshace**:
  - borra la copia si llegó a crearse, sin papelera; si el borrado falla sigue adelante (restaurar los nombres importa más) y deja `copycourseid` en la sesión;
  - si el original se renombró, repone nombres y visibilidad con los valores leídos antes de tocar nada;
  - elimina los directorios temporales.
- Se **conserva**: el staging, a propósito. La sesión queda en `STATUS_FAILED` con `courseid = staging`, `state = failed`, `errorclass` = clase de la excepción y `timefinished`; se dispara `generation_failed` con la clase como `reason` (`course_import_service.php:128-141`).
- La tarea **nunca se reintenta** por cron: un reintento ciego podría correr con el original ya renombrado. Reintentar es una acción explícita del usuario desde la sesión.
- Fallos previos al servicio (permisos, `error_import_existing_disabled`, `error_import_target_invalid`, `targetcourseid` inválido) se lanzan en `create_course` antes de materializar nada: no queda staging.
- Mensajes al usuario: `import_failed` (el original no se modificó y el generado sigue disponible) con el título `courseai_import_failed_title`; `courseai_import_timeout` al agotar el sondeo; `error_import_users_required` y `error_import_precheck_failed` como excepciones del servicio (solo visibles en `sync` o en el log de la tarea; en la tarjeta se muestra `import_failed`). `errorclass` no se muestra al usuario.

## 8. Estado de la sesión

- `course_session::STATUS_IMPORTING = 5` (`course_session.php:44-45`). Durante la importación `status = 5`; al terminar vuelve a `STATUS_CREATED` (3) o `STATUS_FAILED`. `courseid` apunta al staging durante el proceso, a la copia al finalizar o al staging si falló.
- El objeto `import` dentro del JSON `coursedata` lo escribe `update_session` (`course_import_service.php:797-812`) con merge sobre las claves existentes. Campos: `targetcourseid`, `stagingcourseid`, `copycourseid`, `originalshortname`, `originalfullname`, `originalvisible`, `originalvisibleold`, `renamedshortname`, `state` (`queued|running|done|failed`), `errorclass`, `timestarted`, `timefinished`.
- `get_course_session_state` (`get_course_session_state.php:98-111`) añade al retorno lo que calcula `describe_import` (`:119-143`): `importstate` (`''|queued|running|done|failed`), `importmessage` (cadena localizada `import_queued`, `import_running`, `import_completed` o `import_failed`), `copycourseid` y, solo en `done` con copia, `copycourseurl`. Declaración de retornos en `:173-181`.

## 9. Archivos involucrados

| Archivo | Responsabilidad |
| --- | --- |
| `settings.php` | Ajustes `enableimportexisting`, `importcontentmode`, `importexecution` y sus `hide_if` |
| `aicoursecreation.php` | Portón `require_anywhere`; flag `importexistingenabled` vía `is_offered` |
| `templates/courseai_page.mustache` | `#targetMode`, `#importTargetFields`, `#importCategory`, `#importCourse`, `#importProgressView`, atributo `data-import-existing-enabled` |
| `amd/src/local/courseai/actions/course-create.js` | `setupImportControls` (modo, campos, autocompletes, `reset`), `onConfirm`, `createCourseFromSession`, `CREATE_COURSE_TIMEOUT_MS` |
| `amd/src/local/courseai/target-course-datasource.js` | `transport` y `processResults` del autocompletado de curso |
| `amd/src/repository/course.js` | `createCourse`: reenvío de `targetmode` y `targetcourseid` |
| `amd/src/local/courseai/actions.js` | Tarjeta de progreso, `startImportPolling`, `applyImportState`, `renderImportFailed` |
| `amd/src/courseai/bootstrap/resume-snapshot.js` | Reanudación por `sessionstatus = 5` / `importstate` |
| `db/services.php` | Declaración de `local_coursegen_search_target_courses` |
| `classes/external/search_target_courses.php` | Búsqueda de destinos filtrada por `can_import_into` |
| `classes/external/create_course.php` | `targetmode`/`targetcourseid`, `require_import_target`, rama `adhoc`/`sync`, `get_import_service`, retornos de importación |
| `classes/external/get_course_session_state.php` | `describe_import` y retornos `importstate`, `importmessage`, `copycourseid`, `copycourseurl` |
| `classes/external/get_course_settings.php` | Filtro de categorías con `can_create_in` |
| `classes/external/start_course_planning.php`, `course_planning_feedback.php`, `courseai_syllabus_upload.php`, `courseai_filepicker_init.php` | Portón `require_anywhere` |
| `classes/hook/mycourses_header_hook.php`, `classes/hook/chat_hook.php` | Botón y chat condicionados por `can_create_anywhere` |
| `classes/local/service/creation_permissions.php` | Conjunto de creación y sus comprobaciones |
| `classes/local/service/import_permissions.php` | Conjunto de importación y sus comprobaciones |
| `classes/local/service/create_course_service.php` | Generación del staging; `require_for_category` sobre la categoría efectiva |
| `classes/local/service/course_import_service.php` | Orquestación completa: backup, renombrado, copia, restore, modos `replace`/`add`, rollback, sesión |
| `classes/task/import_generated_course_task.php` | Tarea adhoc: `queue`, `execute`, `retry_until_success` |
| `classes/local/models/course_session.php` | `STATUS_IMPORTING = 5` |
| `classes/observer.php`, `db/events.php` | Observer `course_deleted` que elimina sesiones del curso borrado |
| `lang/en/local_coursegen.php`, `lang/es/local_coursegen.php` | Cadenas de ajustes, interfaz, estados y errores |
| `tests/creation_permissions_test.php`, `tests/creation_gate_external_test.php`, `tests/course_confirmation_permissions_test.php` | PHPUnit del portón de creación, de su aplicación en los siete servicios y de los servicios del flujo |
| `tests/import_permissions_test.php`, `tests/search_target_courses_test.php` | PHPUnit del conjunto de importación y del buscador |
| `tests/create_course_import_mode_test.php`, `tests/get_course_session_state_import_test.php` | PHPUnit de `create_course` en modo importación y del estado de sesión |
| `tests/course_import_service_test.php`, `tests/task/import_generated_course_task_test.php` | PHPUnit del servicio (modos, rollback, sesión) y de la tarea |
| `tests/fixtures/testable_course_import_service.php`, `testable_create_course.php`, `testable_get_course_session_state.php` | Subclases inyectables para los tests |
| `tests/behat/import_existing_course.feature`, `tests/behat/create_course_permissions.feature` | Behat de la interfaz (modo, campos, buscador) y del portón; Behat no se ejecuta en este entorno |

## 10. Pendientes conocidos

- `generation_result_applied` se dispara tres veces en la ruta de importación: al crear el staging (`create_course_service.php:87`, contexto del staging), al responder `create_course` (`create_course.php:202`, contexto de sistema, con el id del staging) y al terminar la importación (`course_import_service.php:152`, contexto de la copia). Decidir cuál es el evento de auditoría válido.
- El sondeo usa `get_course_session_state` completo cada 5 s, que devuelve también `coursedatajson`, `snapshotjson` y `streamingurl`; un endpoint ligero que devuelva solo el bloque `import` reduciría carga.
- Solo `lang/en` y `lang/es` tienen las cadenas nuevas; `de`, `fr`, `id`, `pt` y `ru` caen al inglés.
- La ayuda `courseai_import_help` describe siempre un reemplazo; con `importcontentmode = add` el texto no corresponde.
- Rol Gestor: el arquetipo `manager` de serie no pasa el portón de creación y carece por defecto de las capacidades de usuario de backup/restore. Falta decidir si se amplía ese rol o se documenta el rol dedicado como único soporte.
