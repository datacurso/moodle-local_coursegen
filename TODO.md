# TODO — Adaptación del plugin `local_coursegen` al nuevo contrato del servicio

> **Contexto.** El servicio de generación de cursos rehízo su contrato en tres ejes:
> 1. **Modelo de acciones unificado por identidad** — un único contrato `ActionIntent`
>    sobre `POST /course/feedback`, con elementos referidos por **UUID** (no por índice),
>    `position` explícita y borrado lógico (`deleted`).
> 2. **Internacionalización por clave** — todo mensaje al cliente viaja como
>    `{ string_id, string, string_args }`; el cliente localiza por `string_id` y usa
>    `string` solo como fallback.
> 3. **Flujo de confirmación de feedback libre** — el texto libre del usuario NUNCA
>    ejecuta directo: produce **propuestas** que el usuario elige de a UNA.
>
> **Fuentes (en el repo del servicio).** Este documento se basa en:
> `TODO-V1.md` (roadmap implementado), `ACTION_CONFIRMATION.md` (flujo de propuestas,
> diseño congelado) y `USER_FEEDBACK.md` (escenarios S1–S11).
>
> **Alcance.** Describe QUÉ debe cambiar el plugin y DÓNDE (archivos). No entra en la
> tecnología interna del servicio: solo el contrato de integración visible desde el cliente.

---

## Checklist

> Cada punto enlaza a la sección donde se detalla. El estado de avance está en esa sección.

- [x] [1. Contrato de feedback unificado (`ActionIntent`)](#1-contrato-de-feedback-unificado-actionintent)
- [x] [2. Identidades por UUID + `position` + borrado lógico](#2-identidades-por-uuid--position--borrado-lógico)
- [x] [3. Shape de eventos e i18n por `string_id`](#3-shape-de-eventos-e-i18n-por-string_id)
- [x] [4. Catálogo de language strings (espejo 1:1)](#4-catálogo-de-language-strings-espejo-11)
- [x] [5. Flujo de propuestas (confirmación de feedback libre)](#5-flujo-de-propuestas-confirmación-de-feedback-libre)
- [ ] [6. Confirmación local de acciones destructivas directas](#6-confirmación-local-de-acciones-destructivas-directas)
- [ ] [7. Acciones de plan que faltan en la UI](#7-acciones-de-plan-que-faltan-en-la-ui)
- [ ] [8. `completed` / `failed` / errores HTTP localizados](#8-completed--failed--errores-http-localizados)
- [ ] [9. Endpoints removidos o cambiados — reconciliar](#9-endpoints-removidos-o-cambiados--reconciliar)
- [ ] [10. Verificar eventos estructurales / de progreso](#10-verificar-eventos-estructurales--de-progreso)
- [x] [11. Curación de imágenes](#11-curación-de-imágenes)

---

## Resumen del gap (estado actual → requerido)

| Tema | Plugin hoy | Contrato nuevo |
|---|---|---|
| Identidad de elementos | índices 0-based (`section_index`, `activity_index`) | **UUID** (`id`) + `position` + `deleted` |
| Ajuste por ítem | `POST /course/regenerate-item` (`target_type`, índice, `deleted`) | `POST /course/feedback` con `ActionIntent` (acción + `target_ids` UUID) |
| Aprobar / ajustar global | `approval_status: 'accept' \| 'adjust'` + `instruction` | acciones `accept` / `feedback` dentro de `ActionIntent` |
| Texto de eventos | `data.text` (status/token), `data.message` (failed, string) | `data.message = { string_id, string, string_args }` en todos |
| Feedback libre | "adjust" ejecuta directo una regeneración | produce **propuestas**; el usuario elige UNA (`execute_proposal`) |
| Revisión (`review_needed`) | solo `current_plan` | `current_plan` + `message` + `proposals` + `fallen_proposals` + `clarification` |
| i18n de mensajes del backend | no se traducen (se muestra el texto crudo) | espejar **101 claves** en `lang/` y localizar por `string_id` |
| Destructivo directo | borra y avisa al backend | **confirmación local** antes de enviar la acción directa |
| Resume de sesión | `GET /course/state/{id}` | **ese endpoint ya no existe** — reconciliar |

---

## 1. Contrato de feedback unificado (`ActionIntent`)

**Qué cambia.** Desaparecen los dos caminos actuales (`approval_status` global y
`/course/regenerate-item` por ítem). Todo ajuste o aprobación se envía por
`POST /course/feedback` con este cuerpo:

```
{ thread_id, pending_action: ActionIntent }
```

`ActionIntent`:
```
{
  action: <una de las 14 acciones>,
  target_ids: string[] | null,        // UUIDs de secciones o actividades
  parent_section_id: string | null,   // UUID de la sección padre (acciones de actividad)
  position: int | null,               // posición de inserción (0 = inicio, k, null = final)
  instruction: string | null          // palabras del usuario, ÍNTEGRAS
}
```

**Las 14 acciones** (`action`):
`accept`, `add_section`, `delete_section`, `reorder_sections`, `replan_section`,
`add_activity`, `delete_activity`, `reorder_activities`, `replan_activity`,
`full_regeneration`, `adjust_all_details`, `feedback`, `execute_proposal`, `discard_proposals`.

**Reglas del contrato (las debe respetar el cliente al construir el intent):**
- `add_section` / `add_activity`: llevan `position` (e `instruction` con el pedido); `add_activity` requiere `parent_section_id`.
- `delete_*` / `replan_*`: llevan `target_ids`. `replan_*` lleva `instruction`.
- `reorder_sections`: `target_ids` en el orden deseado.
- `reorder_activities`: `target_ids` en orden + `parent_section_id`; los targets deben pertenecer al padre.
- `feedback`: solo `instruction` (texto libre, sin targets) — dispara el flujo de propuestas (§5).
- `execute_proposal`: `target_ids` = UN solo `proposal_id`.
- `discard_proposals`: `target_ids` con ids específicos, o vacío = todas.
- `accept`: cierra la planificación.

**Per-ítem NO es un swap de llamada.** Hoy los botones por ítem llaman a
`regenerateDetailedItem` de forma SÍNCRONA y reconstruyen el DOM con la respuesta
(`resp.result.section_data`). El contrato nuevo NO devuelve los datos: hay que **enviar
`pending_action` y RE-ABRIR el stream** (el mismo patrón que el feedback global), y dejar
que los eventos re-streameados re-rendericen. Por eso el per-ítem depende de §2 (que el
stream re-renderice por `id`).

**Estado:** hecho. El camino global (aprobar / texto libre) y el per-ítem
(`replan_section`/`delete_section`/`replan_activity`/`delete_activity`/`replan_image`/`discard_image`,
cada uno con `target_ids` UUID → `sendPlanningFeedback` → re-abrir stream en `planning`,
sin rebuild síncrono) están en `ui-detailed.js`/`actions.js`/`repository/course.js`. El WS
`regenerate-item` fue eliminado end-to-end (external class, `db/services.php`,
`ai_course_api_service.php`) con bump de `version.php`.

**Dónde (plugin):**
- `amd/src/repository/course.js` — reemplazar `sendPlanningFeedback()` y
  `regenerateDetailedItem()` por una sola función que arme y envíe `pending_action`.
- `amd/src/local/courseai/actions.js` — `sendFeedbackAction()` debe construir el
  `ActionIntent` correcto según el gesto (aprobar, ajustar por ítem, texto libre).
- `amd/src/local/courseai/ui-detailed.js` — los botones por ítem (IA / borrar de
  sección y actividad) pasan de `regenerateDetailedItem({target_type, section_index, deleted})`
  a acciones `replan_section` / `delete_section` / `replan_activity` / `delete_activity`
  con `target_ids` UUID.
- `classes/external/course_planning_feedback.php` — el Web Service debe aceptar y
  reenviar `pending_action` (en vez de `approval_status` + `instruction`).
- `classes/external/regenerate_detailed_item.php` y su WS en `db/services.php` —
  **eliminar** (su endpoint ya no existe); su funcionalidad se absorbe en feedback.
- `classes/local/service/ai_course_api_service.php` — quitar
  `regenerate_detailed_item()`; `send_planning_feedback()` reenvía `pending_action`.

---

## 2. Identidades por UUID + `position` + borrado lógico

**Qué cambia.** El servicio ya no entiende índices. Cada sección y actividad tiene:
- `id`: UUID estable (la referencia que viaja en `target_ids` / `parent_section_id`).
- `position`: orden entre los elementos ACTIVOS (0-based, contiguo).
- `deleted`: borrado lógico (los borrados NO se quitan del árbol; quedan marcados).

El plan que llega del servicio (`current_plan`, eventos de sección/actividad) trae estos
campos. El cliente debe **rastrear el `id`** de cada elemento y usarlo para construir
acciones — no puede seguir usando el índice de render.

`PlanSection`: `{ id, position, deleted, name, description, activities[] }`
`PlanActivity`: `{ id, position, deleted, activity_type, title, description, detailed_plan }`

**Dónde (plugin):**
- `amd/src/local/courseai/state.js` — el modelo (`latestInitialSections`, los mapas
  `detailedActivityEls`/`detailedSectionMeta` con clave `"sectionIdx-activityIdx"`,
  `selectedDetailedImages` con clave `"s-a-img"`) debe pasar a **clavearse por UUID**.
- `amd/src/local/courseai/ui-detailed.js` — guardar el `id` en cada fila renderizada
  (dataset) y leerlo al disparar acciones; respetar `position` y `deleted` al pintar
  (no re-render ciego: un elemento `deleted` no debe ofrecerse como target).
- `amd/src/local/courseai/stream.js` — al recibir secciones/actividades, indexar por
  `id`, no por `section_index`.

**Estado:** hecho. Los mapas de `state.js` se clavean por UUID (`detailedActivityEls` por
`activity_id`, `detailedSectionMeta` por `section_id`, `selectedDetailedImages` por id de
imagen). `ui-detailed.js` guarda el `id` en `dataset` y lo lee al disparar acciones;
`normalizeInitialSections` filtra elementos `deleted` (no se pintan ni se ofrecen como
target) y `createImagesDetail`/`recalculateEntryImageCount` filtran imágenes descartadas.
`stream.js` indexa por `id` en `section`/`activity`/`detailed_plan_*`/`review_needed`.

---

## 3. Shape de eventos e i18n por `string_id`

**Qué cambia.** Todos los eventos con texto humano pasan de `{ type, text }` a:

```
{ type, message: { string_id, string, string_args? } }
```

- `string_id`: clave de idioma (coincide 1:1 con `lang/`).
- `string`: texto por defecto en inglés, ya resuelto — **fallback** si no hay traducción.
- `string_args`: objeto opcional con los valores dinámicos (ausente si no hay).

El cliente debe **localizar por `string_id`** (vía `get_string(string_id, 'local_coursegen', string_args)`)
y caer a `message.string` solo si la clave no existe.

**Eventos afectados** (todos los que hoy leen `data.text` o `data.message`):
- `status` → `data.message` (antes `data.text`).
- `error` → **tipo nuevo** (antes algunos errores venían como `status`); `data.message`.
- `failed` / `completed` → ahora incluyen `data.message` localizado además del `result`.
- `review_needed` → su `message` (y `clarification`) son objetos localizados (§5).

**Dónde (plugin):**
- `amd/src/local/courseai/stream.js` — reescribir el acceso a `data.text`/`data.message`
  por la lectura del objeto localizado + `get_string`. Manejar el tipo `error`.
- `amd/src/local/courseai/i18n.js` — añadir un helper que reciba `{ string_id, string, string_args }`
  y devuelva el texto traducido (con fallback a `string`).
- Eliminar las heurísticas que hoy parsean `status.text` para deducir progreso de
  actividad (`stream.js`): el progreso debe leerse de campos estructurados, no del texto.

**Estado:** hecho — `localizeMessage` en `i18n.js`; `status`/`error`/`failed` localizados
en `stream.js`; las heurísticas de progreso corren contra el `string` inglés estable.

---

## 4. Catálogo de language strings (espejo 1:1)

**Qué cambia.** El servicio define **101 claves**. El plugin debe declararlas en
`lang/en/local_coursegen.php` (y traducirlas en `es/`, `de/`, `fr/`, `id/`, `pt/`, `ru/`),
usando la sintaxis Moodle `{$a->nombre}` para los argumentos. Las claves de `string_args`
del servicio son el contrato: deben coincidir con los `{$a->...}` del lang file.

> Ejemplo: `string_id = "planning_activity"`, `string_args = { type, title }` →
> `$string['planning_activity'] = "Planificando {$a->type} «{$a->title}»…";`

**Dónde (plugin):** `lang/*/local_coursegen.php`, y el helper de §3.

El catálogo completo (clave → args) está en el **Apéndice A**.

**Estado:** hecho en `lang/en` (101 claves); los otros idiomas caen a `en` por defecto.

---

## 5. Flujo de propuestas (confirmación de feedback libre)

> Diseño congelado: `ACTION_CONFIRMATION.md` del servicio. Es lo MÁS nuevo para el plugin
> (hoy no existe nada de esto).

**Qué cambia.** Cuando el usuario manda **texto libre** (acción `feedback`), el servicio
NO ejecuta: interpreta y devuelve **propuestas**. El payload de `review_needed` gana:

```
{
  type: "review_needed",
  current_plan: PlanSection[],
  message: LocalizedMessage,              // prompt de revisión (review_plan_detailed)
  proposals: ProposedAction[],            // opciones ejecutables, elección ÚNICA
  fallen_proposals: { summary, reason }[],// cayeron; cada una con su motivo
  clarification: LocalizedMessage | null  // si no se pudo aterrizar el feedback
}
```

`ProposedAction`: `{ proposal_id, intent: ActionIntent, summary: LocalizedMessage, destructive: bool }`
`fallen_proposals[]`: `{ summary: LocalizedMessage, reason: LocalizedMessage }`

**UI requerida (nueva):**
- Mostrar `proposals` como **selector de elección ÚNICA** (una sola opción marcable),
  cada una con su `summary` (localizado) y un realce visual si `destructive` es `true`.
- Una opción permanente **"Otra cosa"** con campo de texto: lo que el usuario escriba
  entra como acción `feedback` NUEVA (invalida las propuestas pendientes y se re-interpreta).
- Botón **"Ejecutar la seleccionada"** → envía `execute_proposal` con el `proposal_id` elegido.
- Mostrar `fallen_proposals` como **informativas, no seleccionables**, con su `reason`.
- Mostrar `clarification` (cuando viene) como la pregunta a responder; la respuesta del
  usuario vuelve a entrar como acción `feedback`.
- (Opcional) un gesto de **descartar** → `discard_proposals`.

**Ciclo:** tras ejecutar una propuesta, el servicio re-valida las pendientes y la próxima
pausa de revisión puede re-ofrecer las que siguen siendo posibles. El cliente debe tratar
cada `review_needed` como la fuente de verdad de qué propuestas mostrar.

**Dónde (plugin):**
- `amd/src/local/courseai/stream.js` — manejar los nuevos campos de `review_needed`.
- `amd/src/local/courseai/ui-planning.js` y/o un módulo nuevo `ui-proposals.js` — render
  del selector de propuestas, "Otra cosa", caídas y aclaración.
- `templates/courseai_page.mustache` — bloque de UI para las propuestas.

**Estado:** hecho. Módulo nuevo `ui-proposals.js` (`createProposalsUi` → `renderProposals(data)`/`clear()`):
selector de elección única (radios) con `summary` localizado y realce `destructive`, opción
permanente "Otra cosa" con textarea (→ acción `feedback`), botón "Aplicar selección"
(→ `execute_proposal` con `target_ids:[proposal_id]`), botón "Descartar" (→ `discard_proposals`
con `target_ids:[]`), `clarification` como caja destacada, y `fallen_proposals` como lista
informativa no seleccionable. Envía con `sendPlanningFeedback` + re-abre stream en `planning`
(mismo patrón per-ítem). `stream.js` llama `renderProposals(data)` en `review_needed` y `clear()`
al reabrir el stream. Strings nuevas en `i18n.js`/`lang/en` y estilos en `aicoursecreation.css`.
- `amd/src/local/courseai/actions.js` — enviar `execute_proposal` / `discard_proposals` / `feedback`.

---

## 6. Confirmación local de acciones destructivas directas

**Qué cambia.** Según el diseño, lo destructivo **directo** (borrar sección/actividad por
botón, `full_regeneration`) **no** pasa por propuestas: el cliente muestra su propio
diálogo "¿estás seguro?" y, si se confirma, envía la acción directa, que se ejecuta de
inmediato. (El flujo de propuestas del §5 aplica SOLO al texto libre.)

**Dónde (plugin):**
- `amd/src/local/courseai/ui-detailed.js` — ya usa `DeleteCancelModal` para borrar; mantener
  ese patrón pero enviando la acción directa (`delete_section`/`delete_activity`) con UUID.
- Añadir confirmación local equivalente para `full_regeneration` cuando se exponga (§7).

---

## 7. Acciones de plan que faltan en la UI

**Qué cambia.** El contrato soporta acciones que hoy la UI no ofrece. Decidir cuáles
exponer como botones directos (con UUID) y cuáles quedan solo vía texto libre:

- `reorder_sections` / `reorder_activities` — reordenar (drag&drop o controles).
- `add_section` / `add_activity` — alta con `position` + `instruction`.
- `full_regeneration` — regenerar toda la estructura (destructiva → confirmación local).
- `adjust_all_details` — regenerar el detalle de todo manteniendo la estructura.

**Dónde (plugin):** `ui-detailed.js`, `ui-planning.js`, `templates/courseai_page.mustache`.

---

## 8. `completed` / `failed` / errores HTTP localizados

**Qué cambia.**
- `completed`: `{ type, message: LocalizedMessage, result: [...] }` — antes solo `result`.
- `failed`: `{ type, message: LocalizedMessage, result: [...] }` — antes `message` era string.
- Errores HTTP (404/422): el `detail` de la respuesta ahora es un objeto
  `LocalizedMessage` (`{ string_id, string, string_args }`), no un string.

**Dónde (plugin):**
- `amd/src/local/courseai/stream.js` — leer `completed.message` / `failed.message` como
  objeto localizado.
- Capa que maneja errores HTTP del cliente (en `ai_course_api_service.php` / repositorios JS)
  — interpretar el `detail` localizado (claves `session_not_found`, `thread_not_found`,
  `result_not_ready`, `intent_*`, `proposal_*`).

**Estado:** parcial — `failed` localizado y el evento `error` nuevo ya están en `stream.js`;
falta `completed.message` y los errores HTTP.

---

## 9. Endpoints removidos o cambiados — reconciliar

**Qué cambia.** Comparando lo que el plugin llama hoy con el contrato nuevo:

- `POST /course/regenerate-item` — **ya no existe**. Migrar a `/course/feedback` (§1).
- `GET /course/state/{sessionid}` — **no existe** en el contrato nuevo. El resume de
  sesión del plugu (`hydrateDetailedPlanFromSnapshot`, `get_course_session_state`)
  depende de él → **verificar con el servicio** cómo se resume (¿re-stream?, ¿result?).
- `POST /course/init`, `POST /course/sillabus/upload`, `GET /course/result/{thread_id}`,
  `GET /course/stream/{thread_id}` — siguen; revisar que los campos del cuerpo de `init`
  (hoy el plugin manda `image_policy`) coincidan con lo que el servicio espera.
- Flujo de actividad (`/activity/*`): aplica el MISMO cambio de i18n y de `message`
  (claves `review_plan_activity`, `activity_completed`, `activity_failed`, etc.).

**Dónde (plugin):** `classes/local/service/ai_course_api_service.php`, `db/services.php`,
`classes/external/*`, `amd/src/repository/*.js`, `amd/src/courseai.js` (resume).

---

## 10. Verificar eventos estructurales / de progreso

**Qué cambia.** El plugin consume hoy varios eventos que hay que **confirmar** contra el
contrato nuevo (pueden haber cambiado de nombre o forma): `section`, `activity`,
`detailed_plan_start`, `detailed_plan_field`, `detailed_plan_activity`, `course_identity`,
`activity_progress_*`, `image_progress_*`. Antes de tocar nada, validar cuáles emite el
servicio nuevo y con qué campos (incluida la identidad por `id` en lugar de índice).

**Dónde (plugin):** `amd/src/local/courseai/stream.js`, `ui-detailed.js`.

**Estado:** verificado que el servicio nuevo emite `section`/`activity` con `id`+`position`
(NO `section_index`); falta confirmar `detailed_plan_*` / `*_progress` y adaptar el render.

---

## 11. Curación de imágenes

**Qué cambia.** Las imágenes son sugerencias de la IA que el usuario cura. Llegan en el
plan, dentro de cada actividad: `detailed_plan.image_suggestions[]`, cada una con
`{ id, position, deleted, prompt, part }`, acotadas por el `image_policy` (que el plugin ya
manda en `init`). La curación es por acciones sobre `/course/feedback`:
- **descartar** una imagen → `discard_image` con `target_ids:[imageId]` (el servicio omite las descartadas al generar).
- **regenerar** una imagen con instrucción → `replan_image` con `target_ids:[imageId]` + `instruction`.

Ya NO se manda `selected_image_ids` ni `with_images` al aprobar: la curación vive solo en
esas acciones (descartar = borrado lógico; no hay "re-seleccionar").

**Dónde (plugin):**
- `amd/src/local/courseai/ui-detailed.js` — el botón IA por imagen → `replan_image`; el
  control de descarte → `discard_image`; identificar cada imagen por su `id` (UUID) leído de
  `image_suggestions`. Migrar el checkbox de selección actual (`state.selectedDetailedImages`)
  al gesto de descarte.
- `amd/src/local/courseai/stream.js` — leer las `image_suggestions` (con `id`/`deleted`/`prompt`)
  de `detailed_plan` al renderizar.

**Estado:** hecho. Botón IA → `replan_image` e ícono de descarte → `discard_image`, ambos
por `id` UUID; `stream.js` lee `image_suggestions` por `detailed_plan`; las descartadas
(`deleted`) ya no se pintan ni cuentan. Se **eliminó el checkbox de selección** legado (per-imagen
y "seleccionar todo", `courseai_images_select_all`, `setImageSelectionEnabled` y el CSS muerto):
la curación es solo descartar/regenerar, y `selectedDetailedImages` pasa a ser el registro de
imágenes activas usado solo para los conteos. El botón descartar usa una string propia
`courseai_btn_discard` ("Discard").

> **Restore fuera de alcance (bloqueado en el servicio).** El servicio mantiene las imágenes
> descartadas en el árbol (`deleted:true`) y sus comentarios mencionan "el cliente podría
> ofrecer restore", pero **no existe ninguna acción `restore_*`** en el contrato (`ResolvedFeedbackAction`)
> ni nodo que des-marque `deleted`. Implementar restore requiere primero una acción nueva en
> el servicio; queda como mejora futura.

---

## Apéndice A — Catálogo completo de `string_id` (espejo en `lang/`)

> `args` = claves de `string_args` → declarar como `{$a->...}` en el lang file.

**Propuestas (resúmenes):**
- `proposal_add_section` — args: instruction, position
- `proposal_delete_section` — args: names
- `proposal_reorder_sections` — args: names
- `proposal_replan_section` — args: instruction, names
- `proposal_add_activity` — args: instruction, position
- `proposal_delete_activity` — args: names
- `proposal_reorder_activities` — args: names
- `proposal_replan_activity` — args: instruction, names
- `proposal_full_regeneration` — args: instruction
- `proposal_adjust_all_details` — args: instruction

**Status del pipeline (planificación):**
- `detecting_activity_types`, `generating_initial_structure`, `generating_detailed_plans`,
  `creating_section`, `replanning_sections`, `sections_reordered`, `creating_activity`,
  `replanning_activities`, `activities_reordered`, `analyzing_feedback`,
  `generating_images`, `images_generated` — sin args
- `planning_activity` — args: title, type
- `sections_deleted` — args: count
- `activities_deleted` — args: count

**Status (grafo de actividad):**
- `detecting_activity_type`, `analyzing_activity_plan` — sin args

**Errores de generación:**
- `error_processing_activity` — args: error, title
- `error_planning_activity` — args: error, title, type

**Revisión / aclaración:**
- `review_plan_detailed`, `review_plan_activity`, `clarification_fallback` — sin args
- `clarification` — args: question

**Ciclo de vida:**
- `course_completed`, `course_failed`, `activity_completed`, `activity_failed` — sin args

**Errores HTTP:**
- `session_not_found`, `thread_not_found`, `result_not_ready` — sin args

**Errores de validación (422):**
- `intent_requires_targets` — args: action
- `intent_single_target` — args: action
- `intent_unknown_proposal_targets` — args: targets
- `intent_unknown_targets` — args: kind, targets
- `intent_requires_parent` — args: action
- `intent_targets_outside_parent` — args: targets
- `proposal_not_found` — args: proposal_id
- `proposal_not_executable` — args: reason

**Generadores de contenido (intros):**
- `generating_assignment`, `designing_book`, `generating_choice`, `designing_database`,
  `generating_feedback`, `designing_folder`, `designing_forum`, `designing_glossary`,
  `designing_label`, `designing_lesson`, `designing_page`, `designing_quiz`,
  `designing_resource`, `designing_url`, `planning_wiki`, `generating_workshop` — args: title

**Generadores (progreso / listo):**
- `book_config_ready` — args: total
- `generating_chapter` — args: step, title, total
- `feedback_blueprint_ready` — args: total
- `generating_feedback_question` — args: step, total, type
- `assembling_feedback` — sin args
- `folder_ready` — args: name
- `forum_ready` — args: name
- `forum_ready_with_discussions` — args: count, name
- `label_ready` — args: name
- `page_ready` — args: name
- `quiz_config_ready` — args: total
- `generating_quiz_question` — args: question, step, total, type
- `assembling_quiz` — sin args
- `transforming_document` — sin args
- `drafting_wiki_page` — args: step, title, total
- `wiki_ready` — args: count, name
- `writing_workshop_instructions`, `assembling_workshop_assessment` — sin args

**Generadores (errores):**
- `failed_assignment_params`, `failed_book_params`, `failed_database_params`,
  `failed_feedback_blueprint`, `failed_folder_params`, `failed_forum_params`,
  `failed_glossary_params`, `failed_label_params`, `failed_lesson_params`,
  `failed_page_params`, `failed_quiz_params`, `failed_resource_params`,
  `failed_url_params`, `failed_wiki_structure`, `failed_workshop_params` — sin args
- `failed_choice_params` — args: error
- `error_generating_chapter` — args: error, step
- `error_generating_feedback_question` — args: step
- `error_generating_quiz_question` — args: step

