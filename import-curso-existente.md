# Importar un curso generado con IA dentro de un curso existente — `local_coursegen`

> **Objetivo.** Ofrecer, al final del asistente de generación, la opción de **crear un curso nuevo**
> (comportamiento actual) o **importar el contenido generado en un curso ya existente**.
> Este documento es un análisis previo a la implementación: no se modificó código.
> Toda afirmación sobre el código lleva su referencia `archivo:línea`.
>
> **Nota (ticket #9539).** Las decisiones de producto se tomaron en
> [`9539-estrategia-import-curso-existente.md`](./9539-estrategia-import-curso-existente.md):
> copiar primero, la copia como borrador, reemplazo total del contenido, sin usuarios, staging
> borrado, nombre `<original>_ia`. Este documento queda como análisis de respaldo; donde ambos
> difieran, manda el de estrategia.

---

## 0. El hallazgo que condiciona todo el diseño

Antes de cualquier decisión de arquitectura, hay un hecho que descarta la opción más obvia.

`create_course_service::process_course_sections()` invoca **sin ninguna condición**
`delete_course_sections()` (`classes/local/service/create_course_service.php:382`), y ese método:

```php
// create_course_service.php:333-365
// 1) borra TODOS los módulos de la sección 0
foreach ($cms as $cm) {
    if ($cm->sectionnum == 0) {
        course_delete_module($cm->id);
    }
}
// 2) borra TODAS las secciones > 0 (y con ellas sus módulos)
foreach ($sections as $section) {
    course_delete_section($course, $section->section);
}
```

**Apuntar el servicio actual a un curso existente destruiría el curso del docente.** No es un riesgo
teórico: el borrado es incondicional, no hay confirmación, y `course_delete_module()` elimina también
los datos de los estudiantes. Además, la consulta de secciones (`:354-359`) selecciona todas las filas
con `section > 0`, incluidas las secciones delegadas de `mod_subsection`, de modo que el contenido
anidado también entra en el barrido.

Conviene registrar además que **ese código no tiene ninguna prueba**: ningún test cubre
`process_course_sections()` ni `delete_course_sections()`.

Conclusión de partida: la generación debe seguir ocurriendo en un curso propio del plugin, y el
traslado al curso destino debe hacerlo la API de backup/restore del núcleo, que sí sabe fusionar sin
destruir.

---

## 1. Cómo termina hoy el flujo

### 1.1 Secuencia del servicio

`create_course_service::create_course(course_session $session, array $resultdata, array $overrides = [])`
(`classes/local/service/create_course_service.php:48`):

| Paso | Línea | Qué hace |
|---|---|---|
| Resolución de categoría + permiso | `:55-56` | `require_capability('moodle/course:create', context_coursecat::instance(...))` |
| Creación del curso | `:85` | `create_course($coursedata)` del núcleo |
| Persistencia de sesión | `:93-98` | guarda `courseid`, estado → `STATUS_CREATING` |
| **Secciones** | `:101-103` | `process_course_sections()` ← **borra lo existente** |
| Índice de subsecciones | `:107` | transformación pura del payload, sin `courseid` |
| **Actividades** | `:110-117` | `process_generated_activities()` |
| Subsecciones sobrantes | `:121` | `materialize_remaining_subsections()` |
| Reparación y caché | `:124-143` | repara secuencias; lanza excepción si queda inconsistente |
| Estado final | `:146` | → `STATUS_CREATED` |

El payload de la IA **no sale de la sesión ni del cliente**: se pide por HTTP en cada confirmación
(`classes/external/create_course.php:94-96` → `ai_course_api_service::get_course_result()`). Sus claves
de primer nivel consumidas son `course_configuration`, `sections_info`, `subsections_info` y
`generated_activities`.

### 1.2 Qué parte es reutilizable

Buena noticia: **toda la fontanería ya está parametrizada por `$courseid`**. Los doce métodos privados
(`:333`, `:374`, `:486`, `:534`, `:580`, `:656`, `:706`, `:771`, `:784`…) reciben el id y resuelven el
curso con `get_course($courseid)`. Y el escritor por actividad,
`create_mod_service::create_from_ai_result($resultinfo, $course, $sectionnum, $beforemod)`
(`classes/local/service/create_mod_service.php:44`), **ya se usa hoy contra cursos arbitrarios** desde el
flujo de actividad individual (`classes/external/create_mod.php`).

Lo que no es reutilizable, además del borrado, son tres supuestos de "curso vacío":

1. **Numeración absoluta de secciones.** El número del payload se escribe tal cual como número de
   sección del curso (`:389`, `:413`), con un `$DB->insert_record('course_sections', ...)` directo en
   lugar de `course_create_section()`. No hay rebase ni desplazamiento.
2. **`numsections` se sobrescribe** con el máximo del payload, no con `max(existente, payload)`
   (`:418-424`).
3. **La reparación barre el curso entero**, no solo lo generado (`:660`, `:689`). Sobre un curso sano
   es inocuo, pero una inconsistencia preexistente en el destino haría fallar la generación
   **después** de haber escrito el contenido.

### 1.3 Dónde se ofrecería la elección

El panel de confirmación es estático en una única plantilla —
`templates/courseai_page.mustache:647-693` — y el JavaScript solo alterna `display` y rellena valores.
El selector "curso nuevo / curso existente" iría dentro de `#courseReviewPanel`, entre `:660` y `:682`,
junto a los campos de nombre, nombre corto y categoría que ya existen.

El flujo cliente es: `handlers-lifecycle.js:384` → `create-course-callback.js:88-94` →
`actions.showCourseReviewPanel()` → `actions.createCourseFromSession(overrides)`.

---

## 2. La API del núcleo

### 2.1 Dos correcciones a las premisas

- **`core_backup\copy_helper` no existe.** La clase es **global y sin namespace**: `copy_helper`,
  en `backup/util/helper/copy_helper.class.php:28`. No hay `backup/classes/copy_helper.php`.
- **No existe `moodle/course:copy` ni `moodle/backup:copycourse`.** Copiar un curso se autoriza con el
  trío que devuelve `\core_course\management\helper::get_course_copy_capabilities()`
  (`course/classes/management/helper.php:1008`).

### 2.2 Los modos de destino

`backup/backup.class.php:98-102`:

```php
const TARGET_CURRENT_DELETING  = 0;
const TARGET_CURRENT_ADDING    = 1;
const TARGET_NEW_COURSE        = 2;
const TARGET_EXISTING_DELETING = 3;
const TARGET_EXISTING_ADDING   = 4;
```

**«CURRENT» y «EXISTING» son la misma cosa para el motor**; la diferencia es solo de interfaz (desde
qué pantalla se eligió el destino). Lo que de verdad decide el comportamiento es *adding* contra
*deleting* — `restore_controller.class.php:393-400`:

```php
if ($this->get_target() == backup::TARGET_CURRENT_DELETING || $this->get_target() == backup::TARGET_EXISTING_DELETING) {
    restore_dbops::delete_course_content($this->get_courseid(), $options);
}
```

| Destino | Fila del curso | Contenido |
|---|---|---|
| `TARGET_NEW_COURSE` | hay que crear antes un esqueleto | vacío → poblado |
| `TARGET_*_ADDING` | **no se toca** (salvo `overwrite_conf`) | lo existente se conserva, lo nuevo se **añade** |
| `TARGET_*_DELETING` | se conserva el id | `remove_course_contents()` y luego poblado |

Para esta funcionalidad el modo es **`TARGET_EXISTING_ADDING`**.

**Granularidad de la fusión**, dos reglas que hay que conocer:

- **Las secciones se fusionan por número** (`restore_stepslib.php:1637-1641`). Si el destino ya tiene
  una sección con ese número, se reutiliza; el nombre y el resumen solo se rellenan si estaban vacíos
  (`:1682-1710`). Las secciones delegadas siempre se añaden como nuevas.
- **Las actividades nunca se deduplican** (`restore_stepslib.php:4732`, `insert_record` incondicional).
  Importar dos veces deja dos copias de todo.

### 2.3 Secuencia programática mínima

El núcleo ya tiene un ejemplo real y funcionando, `admin/cli/import.php:92-103`:

```php
$bc = new backup_controller(backup::TYPE_1COURSE, $srccourse->id, backup::FORMAT_MOODLE,
    backup::INTERACTIVE_NO, backup::MODE_IMPORT, $admin->id);
$backupid = $bc->get_backupid();
$bc->execute_plan();
$bc->destroy();

$rc = new restore_controller($backupid, $dstcourse->id, backup::INTERACTIVE_NO,
    backup::MODE_SAMESITE, $admin->id, backup::TARGET_EXISTING_ADDING);
$rc->execute_precheck();
$rc->execute_plan();
$rc->destroy();
```

La misma forma aparece en `backup/tests/backup_restore_base_testcase.php:104-121`
(`perform_import()`), y una versión con ajustes y manejo de errores de precomprobación en
`core_course_external::import_course` (`course/externallib.php:1735-1800`).

Dato operativo importante: **`$CFG->enableasyncbackup` no afecta ni a la importación ni a la copia**.
Solo cambia `backup/restore.php` y `backup/backup.php`. Una importación se ejecuta en línea salvo que
nosotros la pongamos en una tarea.

---

## 3. Importar directo contra copiar primero

### Opción A — importar directamente sobre el curso destino

```
curso staging (generado por IA)  --backup MODE_IMPORT-->  .mbz
.mbz  --restore TARGET_EXISTING_ADDING-->  curso del docente
```

**A favor:** una sola operación; es exactamente lo que hace «Importar» de Moodle; el docente conserva
su curso, su id, sus matriculaciones y sus calificaciones.

**En contra:** **no hay transacción ni rollback**. Está verificado: `start_delegated_transaction` no
aparece ni una vez en todo el árbol `backup/`. Si la restauración falla a mitad, el curso del docente
queda con contenido a medias mezclado con el suyo, y no hay forma de deshacerlo.
`backup/import.php` ni siquiera envuelve `execute_plan()` en un `try/catch`.

### Opción B — copiar el curso destino y luego importar sobre la copia

```
curso del docente  --copy_helper::create_copy()-->  copia (tarea adhoc, asíncrona)
curso staging      --import-->  copia
```

**A favor:** el original queda intacto pase lo que pase; si algo falla, se borra la copia.

**En contra, y es más caro de lo que parece:**

- La copia es **asíncrona por diseño**: `copy_helper::create_copy()` encola
  `\core\task\asynchronous_copy_task` (`copy_helper.class.php:98-100`) y devuelve de inmediato
  `['backupid', 'restoreid']`. No hay variante síncrona. El usuario no tiene la copia al confirmar.
- La copia **nace oculta** y con nombres provisionales: `restore_dbops::create_new_course()` fuerza
  `$course->visible = 0` (`restore_dbops.class.php:1906-1907`) y el nombre inicial es la cadena
  `copyingcourse` («Course copying in progress»). Los nombres reales y la visibilidad se aplican al
  final de la tarea (`asynchronous_copy_task.php:121-126`, `:187-192`).
- **`asynchronous_copy_task` no envuelve `execute_plan()` en `try/catch`.** Tiene cuatro salidas de
  limpieza que borran el curso destino (`:63`, `:99`, `:140`, `:170`), pero una excepción del plan no
  está entre ellas: deja un curso oculto a medio construir llamado «Course copying in progress». Y
  como la tarea **no sobrescribe `retry_until_success()`**, cron la reintenta.
- Exige tres capacidades en vez de dos, incluida `moodle/course:create` (ver §5).

### Opción C — generar directamente en el destino con el servicio actual

**Descartada.** Es la que destruye el curso (§0).

### Recomendación

**Opción A, con la copia como casilla opcional.** El coste de B es alto y su beneficio —el rollback—
se puede obtener de forma más barata y síncrona: hacer un **backup previo del curso destino** antes de
importar y conservar el `.mbz`. Si la importación falla, el administrador tiene con qué restaurar, sin
depender de una tarea asíncrona que el propio núcleo deja a medias en caso de error.

Si aun así se quiere la copia, debe presentarse al usuario como lo que es: una operación en segundo
plano cuyo resultado aparecerá más tarde.

---

## 4. Renombrar el curso original para que la copia conserve el nombre corto

El núcleo ya tiene el helper exacto: **`restore_dbops::calculate_course_names($courseid, $fullname, $shortname)`**
(`backup/util/dbops/restore_dbops.class.php:1826-1856`). Itera hasta encontrar un par libre, sufijando
`_1`, `_2`… el nombre corto y ` copy 1`, ` copy 2`… el nombre largo, excluyendo `$courseid` de la
comparación y respetando los límites de 100 y 254 caracteres en modo multibyte.

Dos advertencias sobre ese helper:

- Exige que **ambos** nombres estén libres a la vez: una colisión solo de nombre largo también
  incrementa el sufijo del corto.
- Es un bucle **TOCTOU sin bloqueo**. No hay índice único en `course.shortname` a nivel de base de
  datos, así que dos peticiones concurrentes pueden calcular el mismo nombre.

El patrón de sufijo con marca de tiempo que se pedía como referencia es el de `delete_user()`,
`lib/moodlelib.php:3712-3730`:

```php
$delnameprefix = clean_param($delemail, PARAM_USERNAME);
$delnamesuffix = $deltime;
do {
    $delname = sprintf("%s.%10d",
        core_text::substr($delnameprefix, 0, 100 - 1 - 10),
        $delnamesuffix);
    $delnamesuffix++;
} while ($DB->record_exists('user', ['username' => $delname]));
```

Es decir: prefijo truncado para dejar sitio al sufijo, marca de tiempo de 10 dígitos, y bucle que
incrementa ante colisión. Trasladado a cursos sería `"%s.%10d"` sobre `shortname` truncado a 89
caracteres.

**Recomendación:** usar `calculate_course_names()` y no reimplementar. Es el helper que el propio
motor de restauración aplica (`restore_stepslib.php:1986-1998`), está probado y mantiene la coherencia
con el resto de Moodle. El patrón de `delete_user()` solo merece la pena si se quiere un sufijo
estable y rastreable por fecha; en ese caso, envolver ambos en una transacción para mitigar el TOCTOU.

Conviene recordar que renombrar el curso original **cambia un identificador que los docentes usan**
(aparece en informes, en el selector de cursos y en integraciones externas). Es una decisión de
producto, no solo técnica.

---

## 5. Permisos

| Operación | Capacidad | Contexto | Dónde se exige |
|---|---|---|---|
| Copia de seguridad para importar | `moodle/backup:backuptargetimport` | curso **origen** | `backup/import.php:89`, `backup_check.class.php:132-138` |
| Copia de seguridad normal | `moodle/backup:backupcourse` | curso | `backup_check.class.php:114` |
| Restaurar como importación | `moodle/restore:restoretargetimport` | curso **destino** | `backup/import.php:52`, `restore_check.class.php:94-100` |
| Restaurar (otros modos) | `moodle/restore:restorecourse` | curso destino | `backup/restore.php:54`, `restore_check.class.php:104-113` |
| Crear curso | `moodle/course:create` | **categoría** | `restore_ui_components.php:398` |
| Copiar curso | las tres: `backup:backupcourse` + `restore:restorecourse` + `course:create` | curso origen | `backup/copy.php:41-42` |

**No existe `moodle/restore:restoretargetadding`.** La única capacidad `restoretarget*` del núcleo es
`restoretargetimport` (`lib/db/access.php:309`).

Arquetipos por defecto:

| Capacidad | Arquetipos |
|---|---|
| `moodle/backup:backupcourse` | `editingteacher`, `manager` |
| `moodle/backup:backuptargetimport` | `editingteacher`, `manager` |
| `moodle/restore:restorecourse` | `editingteacher`, `manager` |
| `moodle/restore:restoretargetimport` | `editingteacher`, `manager` |
| `moodle/course:create` | `coursecreator`, `manager` |

**Consecuencia práctica:** un profesor con permiso de edición puede importar de curso a curso sin
configuración adicional, pero **no puede copiar un curso** (le falta `moodle/course:create`). Eso
refuerza la recomendación de la §3: la opción A funciona con los permisos que el docente ya tiene; la
B exige ampliarlos.

Capacidades que además reconfiguran la restauración en silencio, todas en el contexto del curso
destino (`restore_check.class.php`): `moodle/restore:userinfo` (:121), `moodle/restore:configure`
(:154, se omite en `MODE_IMPORT`), `moodle/restore:rolldates` (:166),
`moodle/course:changefullname` (:178), `moodle/course:changeshortname` (:188) y
`moodle/course:update` (:198, que gobierna `overwrite_conf`).

---

## 6. Riesgos y casos límite

### 6.1 Curso destino con contenido

Es el caso normal, y hay que decidirlo explícitamente:

- Las secciones se fusionan **por número**. Si el plan trae «Sección 1» y el destino ya tiene una
  sección 1 con contenido, las actividades generadas se añaden **al final de esa sección**, junto a las
  del docente. No se reemplaza nada, pero tampoco se separa.
- **Decisión pendiente:** ¿se desplazan las secciones generadas al final del curso destino
  (renumerando el payload antes de generar en el staging) o se fusionan por número? Lo primero es más
  predecible para el docente; lo segundo es lo que hace Moodle por defecto.

### 6.2 Error a mitad de la importación

**No hay transacción, no hay rollback, y el destino queda modificado a medias.** Verificado:
cero ocurrencias de `start_delegated_transaction` en todo `backup/`. El único mecanismo de limpieza del
núcleo, `restore_ui::cleanup()` (`backup/util/ui/restore_ui.class.php:226-257`), solo actúa cuando el
destino es `TARGET_NEW_COURSE`; para cualquier destino *adding* devuelve `false` y no deshace nada.

Mitigaciones posibles, por orden de coste:

1. **Copia de seguridad previa del destino** y conservar el `.mbz` (recomendado).
2. Ejecutar `execute_precheck()` siempre y abortar si devuelve errores
   (`restore_controller.class.php:444-462`). Valida roles, usuarios y categorías de preguntas —
   **no** integridad a nivel de actividad.
3. Registrar en la sesión del plugin qué `cmid` se crearon, para poder borrarlos selectivamente.

### 6.3 Curso en categoría sin permisos

El listado de cursos destino debe filtrarse por `moodle/restore:restoretargetimport` en el contexto de
cada curso. No basta con filtrar por categoría: un docente puede ver una categoría y no poder escribir
en todos sus cursos.

### 6.4 Tiempo de ejecución

El flujo actual ya levanta límites (`create_course_service.php:60-63`:
`core_php_time_limit::raise()`, `raise_memory_limit(MEMORY_EXTRA)`, `session::write_close()`) y el
cliente impone un tope de **180 s** (`CREATE_COURSE_TIMEOUT_MS`, `course-create.js:153`).

Backup + restore de un curso completo añade un tiempo que no controlamos. **Recomendación:** mover la
fase de import a una `\core\task\adhoc_task` propia y que el panel haga sondeo del estado de la sesión,
reutilizando `get_course_session_state`. Si se ejecuta en línea, hay que subir el tope del cliente y
asumir peticiones largas.

Advertencia de infraestructura: `$CFG->backuptempdir` (aquí `/var/www/moodledata/temp/backup`)
**debe estar en almacenamiento compartido** si hay varios nodos, porque la tarea adhoc puede
ejecutarse en un nodo distinto del que creó el directorio (`config-dist.php:548-551`).

### 6.5 Limpieza de temporales

Cada operación crea `$CFG->backuptempdir/<backupid>`. Hay que borrarlo explícitamente con
`fulldelete($basepath)` respetando `$CFG->keeptempdirectoriesonbackup`, como hace
`course/externallib.php:1611-1613`. Si no, queda a merced de `backup_cleanup_task`, que solo recoge
directorios de más de una semana.

### 6.6 Doble importación

Las actividades **no se deduplican**. Si el usuario reintenta tras un fallo parcial, tendrá duplicados.
Hay que impedir el reintento sobre el mismo destino, o advertirlo de forma inequívoca.

### 6.7 El curso de staging

Queda un curso generado que ya no se usa. Decidir si se borra al terminar
(`delete_course($id, false)`), si se deja oculto para auditoría, o si se mueve a una categoría
reservada. Si se borra, hacerlo **solo** tras una importación con éxito.

---

## 7. Autocompletado de categoría y curso

### 7.1 Lo que ya existe en el plugin

`core/form-autocomplete` **ya se usa**, en `amd/src/local/courseai/actions/course-create.js:82-90`,
pero con **`ajax = false`**: solo mejora un `<select>` ya poblado desde
`get_course_settings`. No hay datasource remoto.

El propio código deja una advertencia de alguien que ya se equivocó ahí (`:77-81`): el tercer
parámetro de `enhance()` es **el nombre de un módulo AMD**, no una etiqueta; pasar texto hace que
RequireJS intente cargar un módulo con ese nombre.

La lista de categorías **ya viene filtrada por capacidad**:
`core_course_category::make_categories_list('moodle/course:create')`
(`classes/external/get_course_settings.php:79`).

### 7.2 El contrato del datasource

`lib/form/autocomplete.php:45-46` y `lib/amd/src/form-autocomplete.js:1106-1123`: el módulo indicado
en `ajax` debe exportar **`transport(selector, query, success, failure)`** y
**`processResults(selector, results)`**.

### 7.3 Precedentes del núcleo

- **`core/form-course-selector`** (`lib/amd/src/form-course-selector.js`) — el autocompletado de cursos
  canónico. Lee atributos `data-*` del elemento (`data-requiredcapabilities`, `data-exclude`,
  `data-limittoenrolled`) y llama a `core_course_search_courses`.
- **`core_search/form-search-user-selector`** (`search/amd/src/form-search-user-selector.js:39-50`) —
  **el precedente más cercano a lo que necesitamos**: dentro de `transport` lee el valor de *otro
  campo del formulario* (`$('#id_searchwithin').val()`) y lo manda como parámetro. Es exactamente el
  patrón «curso filtrado por la categoría elegida».
- `enrol_manual/form-potential-user-selector`, `tool_lp/frameworks_datasource` y
  `tool_analytics/potential-contexts` siguen el mismo esquema de `data-*` + llamada AJAX.

### 7.4 El obstáculo: no hay web service del núcleo que sirva

Verificado: **ninguna función externa del núcleo filtra cursos por categoría *y* por capacidad.**

| Candidato | ¿Categoría? | ¿Capacidad? |
|---|---|---|
| `core_course_search_courses` | **No** — los criterios admitidos son solo `search`, `modulelist`, `blocklist`, `tagid` (`course/externallib.php:2700-2706`); la propia documentación dice *«search is always category-independent»* | **Sí** — parámetro `requiredcapabilities` |
| `core_course_get_courses_by_field` | **Sí** (`field='category'`) | **No** — solo filtra visibilidad; además devuelve todos los cursos sin paginar y con datos pesados |
| `core_course_category::get_courses()` | Sí, con `recursive`, `limit`, `idonly` | No tiene parámetro de capacidad |

**Conclusión: hace falta un endpoint propio del plugin**, por ejemplo
`local_coursegen_search_target_courses(categoryid, query)`, que combine
`core_course_category::get_courses(['recursive' => true, 'idonly' => true])` con un filtro por
`has_capability('moodle/restore:restoretargetimport', context_course::instance($id))`, con paginación
y un tope de resultados.

---

## 8. Plan por pasos

1. **Decidir la estrategia** (§3) y el tratamiento de secciones (§6.1). Son las dos decisiones que
   condicionan el resto.
2. **Endpoint de búsqueda de cursos destino** — `local_coursegen_search_target_courses`, con su
   declaración en `db/services.php` y pruebas de capacidad y de filtrado por categoría.
3. **Datasource AMD** — `amd/src/local/courseai/target-course-datasource.js` con `transport` y
   `processResults`, leyendo la categoría del selector hermano (patrón de
   `core_search/form-search-user-selector`).
4. **Interfaz del panel** — selector de modo en `templates/courseai_page.mustache`, campos de curso
   destino, cadenas de idioma en los siete paquetes y registro en `i18n.js`.
5. **Ampliar el web service** — `create_course::execute_parameters()` con `targetmode` y
   `targetcourseid`; mantener los parámetros actuales opcionales para no romper las llamadas de una
   sola posición que hacen los tests.
6. **Servicio de importación** — clase nueva `classes/local/service/course_import_service.php` que
   genere en staging con el servicio actual y luego ejecute backup + restore con
   `TARGET_EXISTING_ADDING`, siguiendo `admin/cli/import.php:92-103`.
7. **Tarea adhoc** — si se adopta la ejecución diferida (§6.4), con sondeo desde el panel.
8. **Limpieza** — borrado del curso de staging y de los temporales.
9. **Pruebas** — importación sobre curso con contenido, fallo a mitad, destino sin permisos, doble
   importación, y **una prueba que fije que el destino nunca pierde contenido**.
10. **Compilación AMD** — `grunt`, ya que `amd/build/` está versionado.

---

## 9. Archivos a tocar

| Archivo | Cambio |
|---|---|
| `templates/courseai_page.mustache` | selector de modo + campos de destino, dentro de `#courseReviewPanel` (`:647-693`) |
| `amd/src/local/courseai/actions/course-create.js` | guardas (`:43-45`), poblado (`:54-75`), `enhance` (`:82-90`), `overrides` (`:109-116`), payload (`:180-185`) |
| `amd/src/local/courseai/actions.js` | firmas de los dos cierres (`:308-313`) |
| `amd/src/repository/course.js` | lista blanca de argumentos de `createCourse` (`:75-94`) |
| `amd/src/local/courseai/target-course-datasource.js` | **nuevo** |
| `amd/src/local/courseai/i18n.js` | nuevas claves (`:167-178`) |
| `classes/external/create_course.php` | `execute_parameters()` (`:51-58`), `execute()` (`:73-128`), `execute_returns()` (`:135-151`) |
| `classes/external/search_target_courses.php` | **nuevo** |
| `classes/local/service/course_import_service.php` | **nuevo** |
| `classes/local/service/create_course_service.php` | aislar el borrado para que no pueda alcanzar un curso ajeno |
| `db/services.php` | declaración del endpoint nuevo |
| `lang/*/local_coursegen.php` | cadenas, en los siete paquetes |
| `tests/` | cobertura nueva, incluida la del borrado que hoy **no tiene ninguna** |
| `amd/build/**` | recompilación |
| `CHANGES.md` | entrada de la versión |

---

## 10. Decisiones pendientes

1. **¿Importar directo o copiar primero?** (§3). Recomendación: directo, con copia de seguridad previa
   del destino y la copia como opción explícita.
2. **¿Fusionar por número de sección o añadir al final?** (§6.1). Afecta a lo que el docente ve y no
   tiene vuelta atrás una vez importado.
3. **¿Renombrar el curso original?** (§4). Cambia un identificador visible para docentes e
   integraciones. Es decisión de producto.
4. **¿Ejecución en línea o tarea adhoc?** (§6.4). En línea es más simple pero arriesga el tope de
   180 s del cliente.
5. **¿Qué se hace con el curso de staging?** (§6.7).
6. **¿Se permite importar a un curso con estudiantes y actividad ya iniciada?** No hay nada técnico
   que lo impida, pero mezcla contenido generado con trabajo en curso.
7. **¿Quién puede usar la función?** Hoy el asistente exige `moodle/course:create` en contexto sistema
   (`aicoursecreation.php:31-33`). Un docente que solo puede editar su curso **no llega al asistente**.
   Si la importación se dirige a ese perfil, hay que revisar ese portón.

---

## 11. Referencias

- `admin/cli/import.php:92-103` — secuencia programática de referencia.
- `backup/tests/backup_restore_base_testcase.php:104-121` — la misma secuencia, en pruebas.
- `course/externallib.php:1735-1800` — versión con ajustes y precomprobación.
- `backup/util/helper/copy_helper.class.php` — copia de cursos.
- `backup/util/dbops/restore_dbops.class.php:1826-1856` — `calculate_course_names()`.
- `lib/moodlelib.php:3712-3730` — patrón de sufijo de `delete_user()`.
- `search/amd/src/form-search-user-selector.js:39-50` — autocompletado dependiente de otro campo.
