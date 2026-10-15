# Estrategia: importar el curso generado con IA en un curso existente

> **Ticket:** #9539 · **Estado:** **aprobado para implementar** (ticket de implementación recibido)
> **Análisis técnico de respaldo:** [`import-curso-existente.md`](./import-curso-existente.md)

---

## 1. Decisiones cerradas

| Cuestión | Decisión |
|---|---|
| Estrategia | Copiar primero; importar sobre la copia (ticket #9539) |
| Rol de la copia | **Pasa a ser el curso** de cara al docente: conserva el nombre corto y el largo del original |
| Contenido de la copia | **Se reemplaza por completo** con lo generado por la IA |
| Matriculaciones y datos de usuario | **No se copian** |
| Curso de staging | **Se borra** al terminar |
| Nombre de la copia | **El del original** (corto y largo) |
| Renombrado del original | **Sí**: sufijo único con marca de tiempo, patrón de `delete_user()` (`lib/moodlelib.php:3712-3730`) |
| Permisos | Cubiertos por el rol «Profesor Creador de curso IA» (§7) |
| Ejecución | Copia + importación en una **tarea adhoc**; la generación del staging sigue síncrona |
| Activación | **Ajuste de administrador**, desactivado por defecto; apagado, el flujo no cambia |
| Ruta | **Larga** (copia + staging + importación con reemplazo). La ruta corta de la §5 queda descartada por el ticket de implementación |

**Consecuencia que queda asumida** (ver §5 del análisis de respaldo): la copia tiene un `id` nuevo.
Por **nombre** pasa a ser «el curso»; por **identificador**, enlaces guardados, informes e
integraciones siguen apuntando al original renombrado. El original conserva `id`, matriculaciones y
calificaciones.

---

## 2. Flujo

```
1. Generar el curso con IA              → curso staging  (síncrono, flujo actual)
   ── a partir de aquí, tarea adhoc ──
2. Renombrar el original                → «<shortname>.<marca>» / «<fullname> (<marca>)»
3. Copiar el original, sin usuarios     → copia con el nombre corto y largo originales
4. Importar staging sobre la copia      → reemplazando todo su contenido
5. Borrar el staging
```

Si falla 3 o 4: se borra la copia (si llegó a crearse), se **restauran los nombres** del original y la
sesión queda en error con un mensaje claro. El staging **no se borra** en ese caso: es el curso
generado y pagado, y la sesión lo referencia para que el usuario pueda abrirlo — no es un huérfano.

---

## 3. Por qué no se usa `copy_helper`

Moodle tiene `copy_helper::create_copy()`, pero no sirve aquí, por tres motivos verificados:

1. **Es asíncrona sin alternativa.** `backup_controller.class.php:167` fuerza `EXECUTION_DELAYED`
   para `MODE_COPY`; `create_copy()` encola una tarea y devuelve. Al confirmar, la copia no existe
   todavía y no hay dónde importar.
2. **No controla sus fallos.** `asynchronous_copy_task` no envuelve `execute_plan()` en
   `try/catch`; una excepción deja un curso oculto a medias llamado «Course copying in progress», y
   cron lo reintenta porque la tarea no sobrescribe `retry_until_success()`.
3. **No deja fijar el nombre al crearla**: nace con nombres provisionales que la tarea sustituye al
   final.

**Solución:** hacer la copia con las mismas primitivas, en modo inmediato (`MODE_SAMESITE`, que
además omite la duplicación de ficheros igual que `MODE_COPY`) y con manejo de errores propio.
*(No existe `moodle/course:copy` ni `moodle/backup:copycourse`; copiar se autoriza con
`backup:backupcourse` + `restore:restorecourse` + `course:create`.)*

---

## 4. Secuencia técnica

**Paso 1 — copia, sin usuarios:**

```php
$bc = new backup_controller(backup::TYPE_1COURSE, $destinoid, backup::FORMAT_MOODLE,
    backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $USER->id);
$bc->get_plan()->get_setting('users')->set_value(0);
$backupid = $bc->get_backupid();
$bc->execute_plan();
$bc->destroy();

[$fullname, $shortname] = restore_dbops::calculate_course_names(0,
    $original->fullname . ' (borrador IA)', $original->shortname . '_ia');
$copiaid = restore_dbops::create_new_course($fullname, $shortname, $original->category);

$rc = new restore_controller($backupid, $copiaid, backup::INTERACTIVE_NO,
    backup::MODE_SAMESITE, $USER->id, backup::TARGET_NEW_COURSE);
$rc->execute_precheck();
$rc->execute_plan();
$rc->destroy();
```

**Paso 3 — importar reemplazando** (misma forma que `admin/cli/import.php:92-103`, cambiando el
destino a `DELETING`):

```php
$bc = new backup_controller(backup::TYPE_1COURSE, $stagingid, backup::FORMAT_MOODLE,
    backup::INTERACTIVE_NO, backup::MODE_IMPORT, $USER->id);
$backupid = $bc->get_backupid();
$bc->execute_plan();
$bc->destroy();

$rc = new restore_controller($backupid, $copiaid, backup::INTERACTIVE_NO,
    backup::MODE_SAMESITE, $USER->id, backup::TARGET_EXISTING_DELETING);
$rc->execute_precheck();
$rc->execute_plan();   // llama a remove_course_contents() y luego puebla
$rc->destroy();
```

Con `TARGET_EXISTING_DELETING`, el motor ejecuta `restore_dbops::delete_course_content()` antes de
poblar (`restore_controller.class.php:393-400`); no hace falta vaciar la copia a mano. Todo dentro de
un `try/catch` propio que, ante cualquier excepción, haga `delete_course($copiaid, false)`.

---

## 5. Qué aporta la copia — observación registrada

Conviene ser preciso sobre lo que queda de la copia tras el paso 3, porque **reemplazar todo** lo
reduce mucho. `remove_course_contents()` (`lib/moodlelib.php:4765-5040`) borra de la copia:

- todas las secciones, **incluida la 0** (`course_sections` completo);
- todas las actividades, bloques y filtros;
- las instancias de matriculación (`enrol_course_delete`) y los roles asignados;
- grupos y agrupamientos, calificaciones y escalas, etiquetas, eventos, notas y comentarios.

Y como en este sitio `restore_replace_overwrite_conf = 0`, la importación **no** sobrescribe la fila
del curso. Resultado: de la copia sobreviven **la fila del curso** (nombre, formato, visibilidad,
fechas, resumen, categoría…) **y sus opciones de formato**. Todo lo demás viene del staging.

Es decir: dos ciclos de backup/restore y un borrado total para **clonar la configuración** de un
curso. El mismo resultado se obtiene con una ruta más corta:

```
1. create_course() con la configuración clonada del original, nombre «<original>_ia»
2. Generar el curso con IA directamente en él (flujo actual, sin cambios)
```

Sin staging, sin segunda importación, sin `remove_course_contents()`, sin directorios temporales y
sin el problema del tope de 180 s. **El original tampoco se toca** en esta ruta, así que la garantía
de seguridad que motivó el ticket se cumple igual.

Lo que la ruta larga aporta y la corta no: **nada que sobreviva al reemplazo**. Si en el futuro se
quisiera conservar contenido del original (fusionar en vez de reemplazar), la ruta larga sería la
correcta; con «reemplazar todo», no.

**Decisión:** el ticket de implementación fija la ruta larga (copia + tarea adhoc). La ruta corta
queda registrada aquí como alternativa de menor coste por si el alcance cambia.

---

## 6. Nombres

**Original**: se renombra con el patrón de `delete_user()` (`lib/moodlelib.php:3712-3730`), que es la
referencia que da el ticket — prefijo truncado para dejar sitio, marca de tiempo de 10 dígitos y bucle
de incremento ante colisión:

```php
$suffix = time();
do {
    $newshort = sprintf('%s.%10d', core_text::substr($original->shortname, 0, 100 - 1 - 10), $suffix);
    $suffix++;
} while ($DB->record_exists('course', ['shortname' => $newshort]));
```

y el nombre largo `"<fullname> (<marca>)"`, truncado a 254.

**Copia**: recibe el nombre corto y el largo originales, que quedan libres tras el renombrado. Se crea
con `restore_dbops::create_new_course($fullname, $shortname, $category)` y se restaura con
`TARGET_NEW_COURSE`.

Los dos pasos —renombrar y crear la copia— van en **una transacción**: no hay índice único en
`course.shortname`, y entre liberar el nombre y asignárselo a la copia otra petición podría tomarlo.

---

## 7. Permisos — resuelto

El rol **«Profesor Creador de curso IA»** (`editingteachercoursegenai`) cubre las ocho capacidades
que exige la operación. Verificado sobre el rol real:

| Capacidad | Para qué |
|---|---|
| `moodle/backup:backupcourse` | copia de seguridad del destino |
| `moodle/backup:backuptargetimport` | copia de seguridad del staging |
| `moodle/restore:restorecourse` | restaurar la copia como curso nuevo |
| `moodle/restore:restoretargetimport` | importar sobre la copia |
| `moodle/course:create` | crear la copia en la categoría |
| `moodle/course:changeshortname` | fijar el nombre corto de la copia |
| `moodle/course:changefullname` | fijar el nombre largo de la copia |
| `moodle/course:update` | ajustes del curso |

Si la función se ofreciera a otro rol, comprobar las ocho. La ruta corta de la §5 necesita solo
`moodle/course:create` y las del flujo actual.

---

## 8. Casos límite

**Curso destino con contenido.** Deja de ser un problema: el contenido se reemplaza, no se fusiona.
No hay colisión de números de sección ni actividades duplicadas.

**Reintento.** La importación (`TARGET_EXISTING_DELETING`) es idempotente sobre la copia. El
renombrado **no**: un reintento tras un fallo que ya renombró el original debe partir del estado
restaurado por el rollback, nunca renombrar dos veces. La tarea adhoc debe sobrescribir
`retry_until_success()` devolviendo `false` para que cron **no** la reintente a ciegas; el reintento
es una acción explícita del usuario.

**Errores durante la importación.** El motor de backup/restore no tiene transacciones ni rollback
—cero ocurrencias de `start_delegated_transaction` en `backup/`—. Un fallo a mitad deja la copia
vaciada y parcialmente poblada. Es exactamente por lo que la copia existe: se borra entera y el
original no se ha tocado. Hay que registrar `copiaid` en la sesión del plugin desde el momento en que
se crea, para poder borrarla aunque falle el propio proceso.

**Permisos sobre el destino.** El listado de cursos destino se filtra **curso a curso** por
`moodle/backup:backupcourse` (hay que poder copiarlo), no por categoría.

**Tiempo de ejecución.** Dos backups, dos restauraciones y la generación superan el tope de 180 s del
cliente (`course-create.js:153`). La ruta larga **exige** una `adhoc_task` con sondeo de estado; la
ruta corta de la §5 cabe en el flujo actual.

**Infraestructura.** Con varios nodos, `$CFG->backuptempdir` debe estar en almacenamiento compartido.

**Limpieza.** Borrar el staging con `delete_course($stagingid, false)` solo tras éxito, y los
directorios temporales con `fulldelete()` respetando `$CFG->keeptempdirectoriesonbackup`.

---

## 9. Alcance de la implementación (ticket #9539)

1. `settings.php`: checkbox, desactivado por defecto, con cadenas en `lang/en` y `lang/es`.
2. Panel de confirmación: «Crear como curso nuevo» / «Importar en un curso existente», solo si el
   ajuste está activo.
3. Autocompletado de categoría y, después, de curso filtrado por la categoría — servicio externo
   propio con validación de permisos.
4. Copia con la API nativa, renombrado del original, importación sobre la copia **en tarea adhoc**.
5. Rollback y mensaje claro; sin cursos huérfanos.
6. Pruebas PHPUnit y Behat.
