# Refactor total del JavaScript — `local_coursegen`

> **Objetivo.** Reconstruir el front del flujo de planificación de cursos (`amd/src/local/courseai/*`
> y `amd/src/courseai.js`) sobre una arquitectura modular, sin DOM manual, apoyada en las APIs de
> Moodle. Este documento cubre la **arquitectura del código**. Todo lo visual y de interactividad
> vive en [`ui-refactor.md`](./ui-refactor.md).

---

## Checklist

> Cada punto enlaza a la sección donde se detalla.

- [ ] [1. Reglas duras (presupuesto de líneas, DRY, anti-anidamiento)](#1-reglas-duras)
- [ ] [2. Auditoría del estado actual](#2-auditoría-del-estado-actual)
- [ ] [3. Arquitectura objetivo (reactive + componentes + Mustache)](#3-arquitectura-objetivo)
- [ ] [4. Adopción de APIs de Moodle](#4-adopción-de-apis-de-moodle)
- [ ] [5. Descomposición modular por archivo (≤250 líneas)](#5-descomposición-modular-por-archivo)
- [ ] [6. Eliminación de duplicación](#6-eliminación-de-duplicación)
- [ ] [7. Anti-anidamiento de estructuras de control](#7-anti-anidamiento)
- [ ] [8. Capa de datos: store reactivo único](#8-capa-de-datos-store-reactivo-único)
- [ ] [9. Fases de migración](#9-fases-de-migración)
- [ ] [10. Definición de "hecho" y verificación](#10-definición-de-hecho)

---

## 1. Reglas duras

Estas reglas son **no negociables** y aplican a TODO archivo `.js` nuevo o tocado:

1. **≤ 250 líneas por archivo.** Sin excepciones. Un archivo que llegue a 250 se parte por
   responsabilidad antes de seguir creciendo. (Objetivo práctico: la mayoría < 150.)
2. **Una responsabilidad por módulo.** El nombre del archivo describe lo único que hace.
3. **DRY estricto.** Cero lógica copiada. Todo patrón que aparezca 2+ veces se extrae a un helper
   compartido (ver §6). Cero helpers duplicados entre archivos (`escapeHtml`, `formatTemplate`).
4. **Sin anidamiento de estructuras de control.** Nada de `for` dentro de `for`, ni `if` dentro de
   `if` dentro de `if`. Se usan *guard clauses*, *early return*, tablas de despacho (`Map`/objeto),
   y extracción del cuerpo del bucle a una función nombrada (ver §7).
5. **Sin DOM manual para construir vistas.** Nada de `document.createElement` en cadena para armar
   secciones/actividades/log. Se renderiza con **Mustache + `core/templates`** (ver §4).
6. **APIs de Moodle siempre que exista una.** `core/reactive` para estado, `core/templates` para
   render, `core/str`/`get_strings` para i18n, `core/ajax` para WS, `core/notification` y
   `core/modal*` para diálogos, `core/pubsub` para eventos desacoplados.
7. **Funciones cortas.** Ninguna función supera ~40 líneas. Las gigantes actuales
   (`openSSEStream` 608, `init` ~600, `createDetailedSectionRow` 215) se descomponen.
8. **Tipado de contrato por JSDoc** en todo export público (`@param`/`@return`), igual que hoy.
9. **Build con grunt** (`node_modules/.bin/grunt amd --root=local/coursegen`); cero errores eslint.

---

## 2. Auditoría del estado actual

Tamaños reales (líneas de fuente, `amd/src/`):

| Archivo | Líneas | Estado | Problema principal |
|---|---:|---|---|
| `local/courseai/ui-detailed.js` | **1587** | 🔴 | Construye TODO el preview con DOM manual; `createDetailedSectionRow` (215) y `createDetailedActivityRow` (186) gigantes. |
| `local/courseai/stream.js` | **1247** | 🔴 | `openSSEStream` es **una función de 608 líneas** con un `switch` enorme por tipo de evento. |
| `local/courseai/actions.js` | **756** | 🔴 | Mezcla envío de feedback, creación de curso, settings y resumen. |
| `courseai.js` | **675** | 🔴 | `init()` es un *closure* de ~600 líneas con TODO el cableado anidado dentro. |
| `local/courseai/ui-planning.js` | **599** | 🔴 | Render manual + `setCompactChatState` (switch de 175 líneas, llamado desde 8 sitios). |
| `local/courseai/context_section.js` | **575** | 🔴 | Formulario de contexto; re-define `escapeHtml`. |
| `local/courseai/ui-proposals.js` | 370 | 🟠 | Reciente (§5); DOM manual, debe migrar a componente. |
| `local/courseai/ui-steps.js` | 365 | 🟠 | Pasos/progreso; DOM manual. |
| `local/courseai/sidebar.js` | 217 | 🟠 | Tiene un bug de firma (params descartados). |
| `local/courseai/i18n.js` | 210 | 🟢 | Lista de claves + `localizeMessage`; ok, revisar tamaño. |
| `local/courseai/utils.js` | 161 | 🟢 | Helpers; centralizar aquí los compartidos. |
| `local/courseai/state.js` | 76 | 🟠 | Estado mutable global por referencia → reemplazar por store reactivo. |

**Hallazgos transversales (de la auditoría):**
- **0 uso de Mustache** en `courseai` (todo DOM manual). `core/templates` solo se usa en `activityai`.
- El patrón **`pendingAction → sendPlanningFeedback → openSSEStream('planning')`** está repetido
  **11 veces** en 3 archivos.
- `escapeHtml` definido en `utils.js` **y** `context_section.js`. `formatTemplate` en `utils.js`
  **y** `activityai/mutations.js`.
- `createInlineAdjustmentPanel` (ui-detailed) y `createAddPanel` casi idénticos (solo cambia el placeholder).
- `state.js` expone un objeto mutable compartido por referencia → fuente de bugs de sincronización.
- Ya hay precedente de la arquitectura correcta: **`amd/src/local/activityai`** usa `core/reactive`
  (`reactive.js`, `mutations.js`, `events.js`, `components/*`) con `core/templates`. Es el modelo.

---

## 3. Arquitectura objetivo

Reemplazar el modelo actual (estado global mutable + parcheo de DOM a mano) por el patrón
**reactive de Moodle**, idéntico en espíritu al de `activityai`:

```
            ┌──────────────────────────────────────────────┐
            │  CoursePlanReactive  (core/reactive)          │
            │  state = { plan, log, phase, proposals, ... } │
            │  mutations = { applyEvent, applyProposal, … } │
            └───────────────┬──────────────────────────────┘
                            │  (state change → watchers)
       ┌────────────────────┼─────────────────────┬───────────────┐
       ▼                    ▼                     ▼               ▼
  PreviewComponent     LogComponent         ChatComponent    StepsComponent
  (vista central)      (vista lateral)      (input usuario)  (progreso)
   renderiza con        renderiza con        ...              ...
   core/templates       core/templates
```

- **Una sola fuente de verdad**: el `state` del reactive. Nadie muta el DOM directamente; se
  despachan **mutations**, y los **componentes** reaccionan vía `getWatchers()` / `stateReady()`.
- **Stream → mutations**: la capa SSE no toca el DOM; traduce cada evento del servidor a una
  mutación (`applyStreamEvent`). El render es consecuencia del cambio de estado.
- **Render = Mustache**: cada componente pinta con `Templates.render(template, context)` y
  `Templates.replaceNodeContents` / `appendNodeContents`. Cero `createElement` manual.
- **Componentes pequeños**: cada `components/*.js` ≤ 150 líneas, con `getWatchers()` declarativo.

> El blueprint exacto (constructor `Reactive`, bracketing `setReadOnly` en mutaciones, contrato
> `getWatchers()`+`stateReady()`, inyección de Mustache con `Templates.render()`) está en
> `amd/src/local/activityai/reactive.js`, `mutations.js` y `components/modal_controller.js`.
> Replicarlo, no reinventarlo.

---

## 4. Adopción de APIs de Moodle

| Necesidad | Hoy | Objetivo (API Moodle) |
|---|---|---|
| Estado y reactividad | objeto mutable en `state.js` | **`core/reactive`** (Reactive + mutations + components) |
| Render de vistas | `document.createElement` (DOM manual) | **`core/templates`** + plantillas Mustache en `templates/` |
| i18n | `localizeMessage` + lista | mantener, pero `core/str` `getString(s)` + `core/prefetch` |
| Llamadas WS | `core/ajax` (ya en repos) | mantener; centralizar en `repository/` |
| Diálogos destructivos | `core/modal_delete_cancel` (ya) | mantener |
| Errores / avisos | mezcla manual | **`core/notification`** (addNotification / alert) |
| Eventos entre módulos | callbacks/closures anidados | **`core/pubsub`** o eventos del reactive |

**Plantillas Mustache nuevas** (en `templates/`, una responsabilidad cada una; ver detalle visual
en `ui-refactor.md`): `preview_section.mustache`, `preview_activity.mustache`,
`preview_image.mustache`, `log_entry.mustache`, `proposal_option.mustache`. Se renderizan desde los
componentes; el contexto sale del state del reactive.

---

## 5. Descomposición modular por archivo

Objetivo de árbol (todo ≤250 líneas, la mayoría mucho menos):

```
amd/src/courseai.js                         → solo bootstrap: instancia el reactive y monta componentes (~80)
amd/src/local/courseai/
  reactive.js                               → define CoursePlanReactive (instancia + registro) (~60)
  mutations.js                              → mutaciones puras del plan/log/fase (~200, o partir en 2)
  state-shape.js                            → estado inicial + tipos JSDoc del state (~60)
  stream/
    connection.js                           → ciclo de vida EventSource (open/close/retry) (~120)
    router.js                               → tabla tipo-de-evento → handler (~40)
    handlers-structure.js                   → section / activity → mutación (~120)
    handlers-detail.js                      → detailed_plan_field / detailed_plan_activity (~120)
    handlers-review.js                      → review_needed (plan + propuestas) (~100)
    handlers-lifecycle.js                   → status / completed / failed / error (~120)
  components/
    preview.js                              → vista central (curso) — watchers + render (~150)
    log.js                                  → vista lateral (registro) — watchers + render (~120)
    chat.js                                 → input del usuario + envío (~120)
    steps.js                                → progreso/fases (~120)
    proposals.js                            → selector de propuestas (~150)
  actions/
    plan-action.js                          → ÚNICO helper: intent → sendPlanningFeedback → re-stream (~50)
    course-create.js                        → crear curso + settings (~150)
  ui/
    panel.js                                → factory de panel inline de texto (reemplaza los 2 actuales) (~60)
  i18n.js                                   → claves + localizeMessage (sin cambios mayores) (~210→partir si crece)
  utils.js                                  → ÚNICOS escapeHtml/formatTemplate/iconUrl compartidos (~120)
```

Reglas de partición por ofensor:
- **`stream.js` (1247)** → `stream/connection.js` + `stream/router.js` + 4 `handlers-*.js`. La función
  `openSSEStream` de 608 líneas desaparece: queda `connection.open()` (corto) y un `router` que
  despacha por `Map`. Cada handler traduce a una mutación; **no toca el DOM**.
- **`ui-detailed.js` (1587)** → desaparece como tal. Su render se reparte en `components/preview.js`
  + plantillas Mustache (`preview_section`, `preview_activity`, `preview_image`). Los handlers
  per-ítem usan `actions/plan-action.js`. El drag&drop va a `components/preview.js` (o un
  `preview-dnd.js` si supera 250 entre ambos).
- **`actions.js` (756)** → `actions/plan-action.js` (el sender único) + `actions/course-create.js`
  + lo de resumen al `components/steps.js`.
- **`courseai.js` (675)** → `init()` deja de ser un closure gigante: solo crea el reactive y registra
  componentes (cada componente se autoconfigura por watchers).
- **`ui-planning.js` (599)** → su `setCompactChatState` (switch de 175) se reemplaza por estado en el
  reactive (`phase`/`chatEnabled`) que el `components/chat.js` observa; el render manual se va a
  plantillas.

---

## 6. Eliminación de duplicación

| Duplicación | Dónde | Solución |
|---|---|---|
| `pendingAction → send → reopen` (×11) | `ui-detailed.js`, `ui-proposals.js`, `actions.js` | **`actions/plan-action.js`**: `runPlanAction(intent)` único; todos lo llaman. |
| `createInlineAdjustmentPanel` ≈ `createAddPanel` | `ui-detailed.js` | **`ui/panel.js`**: `createTextPanel({onSubmit, placeholder, submitLabel})`. |
| `escapeHtml` ×2 | `utils.js`, `context_section.js` | solo en `utils.js`; importar. |
| `formatTemplate` ×2 | `utils.js`, `activityai/mutations.js` | solo en `utils.js` (compartido); importar. |
| Badges de conteo de imágenes | `ui-detailed.js` (varios) | helper en `components/preview.js` o `utils.js`. |
| Lookups por id de sección/actividad | varios | derivar del state del reactive (no recalcular en DOM). |
| Heurísticas de progreso por texto | `stream.js` | leer campos estructurados del evento; centralizar en `handlers-lifecycle.js`. |

> Regla operativa: antes de escribir una función, buscar si ya existe (grep). Si se va a copiar
> algo, se extrae a un módulo compartido en el mismo commit.

---

## 7. Anti-anidamiento

Técnicas obligatorias (reemplazan todo `for`/`if` anidado actual):

- **Guard clauses / early return**: validar y salir arriba; nunca envolver el cuerpo en `if`.
- **Tabla de despacho**: el `switch` por tipo de evento del stream → `const HANDLERS = { section: …, activity: … }` y `HANDLERS[type]?.(data)`.
- **Una sola pasada por colección**: `array.map/filter/find/reduce` en vez de `for` anidados;
  el cuerpo complejo se extrae a una función nombrada (`sections.forEach(renderSection)`).
- **Aplanar árbol plan→secciones→actividades**: helpers que devuelven listas planas
  (`activitiesOf(section)`) en vez de bucles anidados de render.
- **Sin ternarios que oculten lógica**: si hay rama, `if` explícito.
- **Composición de mutaciones**: una mutación llama helpers puros, no anida lógica de varios pasos.

---

## 8. Capa de datos: store reactivo único

`state` propuesto del `CoursePlanReactive` (forma; tipos por JSDoc):

```
{
  phase: 'context' | 'planning' | 'review' | 'generating' | 'done' | 'error',
  plan: PlanSection[],            // árbol con id/position/deleted (ya es el contrato del servicio)
  proposals: ProposedAction[],   // propuestas vigentes (§5)
  fallenProposals: …[],
  clarification: LocalizedMessage | null,
  log: LogEntry[],               // registro cronológico (ver ui-refactor.md): {actor, action, target, message, ts}
  pendingHighlights: …,          // marcas visuales en curso (rojo/info/success) — ver ui-refactor.md
  session: { recordid, streamingurl },
  chatEnabled: bool,
}
```

- **Mutaciones** (puras, con bracketing `setReadOnly` como en `activityai`): `applyStreamEvent`,
  `applySection`, `applyActivity`, `applyDetailedField`, `applyReview`, `applyProposalResult`,
  `appendLog`, `markHighlight`, `clearHighlight`, `setPhase`, `setChatEnabled`.
- **Cada acción del usuario y cada cambio de la IA escribe una entrada en `log`** (requisito de la
  vista lateral; detalle en `ui-refactor.md`). El componente Log solo observa `state.log`.
- El estado mutable global de `state.js` se elimina; lo que sobreviva (refs DOM raíz, session) pasa
  al state del reactive o a la config del bootstrap.

---

## 9. Fases de migración

Orden seguro (cada fase compila y deja la app funcionando):

1. **Cimientos sin riesgo**: `utils.js` único (dedup `escapeHtml`/`formatTemplate`), `ui/panel.js`,
   `actions/plan-action.js`. Reapuntar los 11 call-sites al sender único. (Sin cambio visual.)
2. **Store reactivo**: crear `reactive.js`, `state-shape.js`, `mutations.js` (vacío→incremental).
   Espejo del state actual; aún sin mover el render.
3. **Stream → mutaciones**: partir `stream.js` en `connection`+`router`+`handlers-*`; cada handler
   despacha una mutación en vez de tocar el DOM. (El DOM viejo sigue, alimentado por watchers temporales.)
4. **Componente Preview** (vista central) sobre Mustache; apagar el render de `ui-detailed.js`.
5. **Componente Log** (vista lateral) sobre `state.log`.
6. **Componentes Chat / Steps / Proposals**; retirar `ui-planning.js`, `ui-steps.js`, `ui-proposals.js`.
7. **Bootstrap**: adelgazar `courseai.js` a solo montaje.
8. **Borrado**: eliminar `ui-detailed.js`, `state.js` y muertos; verificar 0 referencias.

Cada fase: build grunt limpio + prueba manual del flujo (con cachés purgadas).

---

## 10. Definición de "hecho"

- [ ] Ningún `.js` de `courseai` supera 250 líneas (`find amd/src -name '*.js' | xargs wc -l`).
- [ ] Cero `document.createElement` para construir secciones/actividades/log (todo Mustache).
- [ ] El sender de acciones existe **una** vez; 0 duplicados de `escapeHtml`/`formatTemplate`/panel.
- [ ] Cero `for`/`if` anidados (revisión de diffs).
- [ ] `core/reactive` es la única fuente de estado; `state.js` eliminado.
- [ ] Build grunt sin errores eslint; flujo completo (plan → feedback → propuestas → aprobar) verificado.
- [ ] La parte visual cumple [`ui-refactor.md`](./ui-refactor.md).
