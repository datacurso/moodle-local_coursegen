# TODO v2 — `local_coursegen` (plugin) — trabajo pendiente unificado

> **Qué es este archivo.** Consolida TODO lo pendiente del plugin en un solo lugar:
> lo de [`ui-refactor.md`](./ui-refactor.md) (UI/interacción del planificador) y lo del lado-plugin
> registrado en [`../../../../python/ai/course_ai/TODO-V2.md`](../../../../../python/ai/course_ai/TODO-V2.md)
> (defectos de campo de iteraciones anteriores). Incluye una **referencia técnica** de cómo está
> hecho el formato real de Moodle que el preview debe imitar. Lo YA HECHO se resume al final.
>
> Estado: `[x]` hecho · `[ ]` pendiente · `[~]` parcial.
> Fecha de unificación: 2026-06-22.

---

## 0. Contexto — dos superficies del plugin

1. **Wizard planificador** (`aicoursecreation.php` → AMD `local_coursegen/courseai`): la interfaz de
   tres zonas (log izquierdo · preview central · chat) que estamos puliendo. **Aquí está casi todo
   lo pendiente.**
2. **Botón "Add activity or resource with AI"** inyectado en el **editor real de Moodle**
   (`amd/src/activityai.js` + `templates/add_activity_ai_button.mustache`): ya existe y funciona; se
   inserta en cada `.divider-content` del formato de curso. Útil como referencia de integración.

---

## 1. REFERENCIA — cómo está construido el formato "Custom sections" de Moodle 4.5

> CLAVE: el formato "Custom sections" **es** `course/format/topics` (su `pluginname` en 4.5 se
> renombró a "Custom sections"). El render en modo edición lo produce **`core_courseformat`**
> (`course/format/templates/local/content/...` + `course/format/amd/src/local/...`) + el tema Boost
> (`theme/boost/scss/moodle/course.scss`). El **preview central del wizard debe REPLICAR este look y
> comportamiento** (no inventar tarjetas con bordes/handles permanentes). Rutas para copiar el patrón:

### 1.1 Sección
- Templates: `course/format/templates/local/content/section.mustache`,
  `.../section/content.mustache`, `.../section/header.mustache`, `.../section/controlmenu.mustache`.
- Markup: `<li class="section course-section main" data-for="section" data-id data-number …>` →
  dentro `<div class="section-item">` (esa es la "tarjeta": `border` + `border-radius:1rem`,
  `course.scss:1127`).
- **Chevron colapsar/expandir**: Bootstrap 4 Collapse nativo — `<a data-toggle="collapse"
  data-for="sectiontoggler" aria-expanded href="#coursecontentcollapseid{id}"
  class="btn btn-icon icons-collapse-expand">` con `<span class="expanded-icon">`/`collapsed-icon`
  (pix `t/expandedchevron` / `t/collapsedchevron`). Panel: `<div class="content collapse show">`.
  El JS `core_courseformat/local/content.js:169` sincroniza el estado reactivo (`sectionContentCollapsed`).
- **Título**: `<h3 class="h4 sectionname" data-for="section_title">` con `inplace_editable` (lápiz).
- **Collapse all/Expand all** (solo sección 0): `<a class="section-collapsemenu" data-toggle="toggleall">`.
- **Menú ⋮**: `.section_action_menu` (core/action_menu, `.dropdown-toggle::after{display:none}`).

### 1.2 Actividad (cmitem) — filas con SEPARADOR PUNTEADO (no tarjetas)
- Templates: `.../section/cmitem.mustache`, `.../cm.mustache`, `.../cm/activity.mustache`,
  `.../cm/cmicon.mustache`, `.../cm/cmname.mustache`, `.../cm/controlmenu.mustache`.
- El "separador" NO es un elemento: es `border-top: $border-width solid $border-color` de cada
  `<li.activity>` en modo lectura (`course.scss:1292`); en edición lo reemplaza el `.divider`.
- Grid: `.activity-grid { display:grid; grid-template-columns: min-content 1fr min-content … }`
  (`course.scss:1349`). Clases: `.activity.activity-wrapper`, `.activity-item.focus-control`,
  `.activity-icon`, `.activity-name-area`, `.activity-actions.bulk-hidden`, `.cm_action_menu`.
  data-*: `data-for="cmitem"`, `data-id`, `data-cmid`.

### 1.3 Hover en actividad (borde SOLO en hover)
- `course.scss:1321`: en `.editing`, `.activity-item:hover { outline: 2px solid $primary;
  box-shadow: $box-shadow-sm; }` (outline → no desplaza layout).
- Patrón `.focus-control` + `.v-parent-focus` (`core.scss:2532`): los controles hijos (lápiz, ⋮)
  están `opacity:0;visibility:hidden` y aparecen con `.focus-control:hover/:focus-within`.

### 1.4 Drag & drop SIN handle permanente
- Engine: `lib/amd/src/local/reactive/dragdrop.js`; componentes
  `course/format/amd/src/local/courseeditor/dndcmitem.js`, `dndsection.js`, `dndsectionitem.js`.
- Handle visible **solo en hover** (`core.scss:2841`): `.dragicon{visibility:hidden}` →
  `.draggable:hover .dragicon{visibility:visible;cursor:move}`. El engine añade `.draggable` al
  `<li data-for=cmitem>` / cabecera de sección. Ícono: pix `i/dragdrop`.
- Dropzones al arrastrar (`course.scss:241`): `.activity.dropready.drop-up{border-top}` /
  `.drop-down{border-bottom}`. Mutaciones reactivas: `cmMove`, `sectionMoveAfter` (mutations.js).

### 1.5 Inserción inline "Add activity" ENTRE actividades (el `.divider`)
- Template: `.../local/content/divider.mustache` → `<div class="divider d-flex justify-content-center">
  <hr><div class="divider-content px-3">…botones…</div></div>`.
- `cm.mustache` lo inserta en `{{#editing}}` con `{{> core_course/activitychooserbutton}}` dentro.
- CSS (`course.scss:1559`): el `<hr>` (punteado) y `.divider-content` están `opacity:0;visibility:hidden`
  y solo aparecen en `:hover/:focus-within` del `.divider`; la línea se pone azul con
  `:has(.btn.add-content:hover)`.
- Botón "+": `course/templates/addresourceoractivitybutton.mustache` (PHP
  `course/classes/output/activitychooserbutton.php`) → `<button class="section-modchooser btn add-content"
  data-action="open-chooser" data-sectionnum data-beforemod>`.
- Botón **"Add activity or resource with AI"**: lo aporta ESTE plugin
  (`templates/add_activity_ai_button.mustache` + `amd/src/activityai.js:42`), inyectándolo en cada
  `.divider-content:has([data-action="open-chooser"])`.

### 1.6 Botón "+ Add section"
- Template: `.../local/content/addsection.mustache` → `<a class="w-100 btn add-section p-3"
  data-action="addSection">`. CSS (`course.scss:1241`): `border-radius:1rem; border:2px dashed
  $border-color; color:$primary;` y en hover `border:2px solid $primary; background:$primary-light`.
- "+" mini entre secciones: `.../section/addsectiondivider.mustache` (reusa `.divider`).

---

## 2. Fidelidad Custom Sections + hover (P1–P4 HECHOS · P5 pendiente)

> P1–P4 implementados y verificados e2e (2026-06-22): highlight sutil solo-centro, actividades como
> filas con separador punteado, grip oculto/hover, botón Add section centrado. Falta **P5**.

> Origen: 2ª ronda de campo (capturas). El preview central NO debe verse como tarjetas con bordes y
> puntos de arrastre permanentes; debe imitar §1.

- [x] **P1 — Quitar el remarcado feo (`.cg-affected`).** Hoy, al seleccionar una propuesta, se dibuja
  un **anillo rojo grueso** (`box-shadow: 0 0 0 2px…, 0 0 0 7px…`) en el checklist izquierdo (queda
  como óvalo rojo) y en el centro (rompe el layout). Hacer:
  - Resaltado **sutil** estilo Moodle: en vez de anillo rojo, usar `outline: 2px solid $primary` (o
    fondo tenue + borde-izquierdo fino para el caso destructivo), con transición.
  - **Excluir el checklist izquierdo**: el highlight de "afectados" es SOLO para el centro
    (`.prv-section-row`/`.dp-activity-wrap`), nunca para `.courseai-checklist-item`.
  - Mantener la lógica "solo mientras la opción está seleccionada" (ya en `ui-proposals.js
    onSelectionChange`); cambia solo el CSS `.cg-affected` en `aicoursecreation.css`.
- [x] **P2 — Centro = filas con separador punteado (no tarjetas).** Reemplazar las tarjetas
  `.prv-activity-item`/`.dp-activity-wrap` (border + radius + shadow) por **filas** separadas con
  `border-top: 1px solid $border` (lectura) / `.divider` punteado (edición), como §1.2. Sección con
  cabecera colapsable (chevron, lápiz, ⋮) como §1.1. Archivos: `detailed/section-row.js`,
  `detailed/activity-row.js`, `detailed/activity-dom.js`, `aicoursecreation.css`.
- [x] **P3 — Hover/drag estilo Moodle.** Ocultar el grip `::` (`.dp-drag-handle`) por defecto;
  mostrarlo SOLO en hover (`opacity 0→1`) o permitir arrastrar toda la fila. Borde/realce de la fila
  SOLO en hover (`outline: 2px solid $primary` como §1.3). Mantener el DnD funcional (`wireDragAndDrop`).
- [x] **P4 — Botón "Add section" estilo Moodle.** Bloque ancho punteado redondeado, "+ Add section"
  centrado azul, hover con borde sólido (§1.6). Igual criterio para "+ Add activity".
- [ ] **P5 — "Add activity" inline entre actividades.** Zona de inserción entre cmitems oculta por
  defecto que aparece en hover (línea azul punteada + "+" + botón "✦ Add activity with AI"), como el
  `.divider` de §1.5. Hoy solo existe "+ Add activity" al final de la sección.
  - **VIABILIDAD CONFIRMADA**: el servicio (`add_activity_node`) YA acepta `pending_action.position`
    (0=inicio, k=posición, None=fin). Falta solo el cliente.
  - **Enfoque recomendado** (evita pelear con el reconciliador por UUID): NO insertar zonas libres
    entre hermanos; en su lugar dar a CADA `.dp-activity-wrap` una **franja "insert-before" en su
    borde superior** que aparece en hover, con "+" que abre un panel add-activity y envía
    `add_activity` con `parent_section_id` + `position` = índice actual de esa actividad (entre
    hermanas no borradas) + `instruction`. Así la zona viaja con la fila que el reconciliador ya
    gestiona como unidad. Reusar `createTextPanel`/`runPlanAction` de `section-row.js`. Archivos:
    `detailed/activity-row.js`/`activity-dom.js` + CSS. Zona de inserción entre cmitems oculta por
  defecto que aparece en hover (línea azul punteada + "+" + botón "✦ Add activity with AI"), como el
  `.divider` de §1.5. Hoy solo existe "+ Add activity" al final de la sección.

> Decisión abierta: en el preview, ¿iconos de módulo **neutros** (como dejamos los badges) o con el
> **color real de Moodle por purpose**? El look Moodle usa color por purpose; ya neutralizamos por
> pedido de "menos colores". Confirmar con el usuario antes de reintroducir color en los iconos.

---

## 3. PENDIENTE — resto de `ui-refactor.md`

- [ ] **Quitar el spinner `#planningLoading`** (§1, §5, §10): aún coexiste con los skeletons; el
  estado "cargando" debe ser SOLO skeletons + barra fina (ya hechos). Verificar que ningún path lo
  muestre como estado principal.
- [ ] **Hover en entrada del log → resalta el elemento en el preview** (§4.3): al pasar el cursor por
  una entrada del log lateral, resaltar la sección/actividad correspondiente en el centro.
- [x] **Previsualización de selección antes de confirmar** (§6.1) — HECHO (2026-06-24): las propuestas
  previsualizan el elemento afectado (highlight `cg-affected`) al seleccionar, y al APLICAR se pone el
  skeleton SOLO en el elemento objetivo (`markProposalTargetPending` en `detailed/pending.js`,
  restaurado de 415e71f). Verificado e2e (1 actividad con shimmer, resto intacto).
- [ ] **Plantillas Mustache propias** (§3, §9, §14): hoy el preview se construye por DOM manual en JS.
  Crear las plantillas (`preview_section.mustache`, `preview_activity.mustache`, etc.) que reusen las
  clases de `core_courseformat` para heredar el tema. (Mejora de mantenibilidad; opcional pero pedido.)
- [ ] **Accesibilidad** (§12): reordenar por teclado (secciones/actividades), roles `radiogroup`/`radio`
  correctos en las propuestas, foco visible.
- [ ] **Responsive** (§13): comportamiento en <1100px (el grid colapsa a 1 columna; revisar chat,
  proposals, skeletons en móvil/tablet).

---

## 3.bis Sesión 2026-06-24 (feat/chat-polish-v2 · PR #15) — peticiones nuevas, HECHAS

> Pedidos que surgieron en esta sesión (no estaban en el TODO original). Todos verificados e2e con el
> Chromium propio de puppeteer, 0 errores JS. Commits en `feat/chat-polish-v2`.

- [x] **Vista central SIEMPRE editable**, independiente del modo edición de Moodle (`detailed/container.js`
  añade `editing` al host; afordances scoped a `.editing &`). El plugin no depende de `body.editing`. (9901a81)
- [x] **Cursor de arrastre consistente** en TODAS las actividades (`cursor: move`, no `pointer`). (e5567bf)
- [x] **Colapso de sección por chevron** arreglado (quitado el doble-toggle de Bootstrap `data-toggle="collapse"` + `stopPropagation`; `toggleSectionCollapse` lo maneja). (6ac57b2)
- [x] **Secciones arrastrables** con resaltado (outline) + cursor `move` al pasar por encima de la sección (fuera de actividades, `:not(:has(.activity:hover))`). (f057b82)
- [x] **El panel izquierdo NUNCA queda en blanco**: indicador "working" continuo durante el RTT de `initSession` (transición de vista antes del await) y durante reconexiones del `EventSource` (solo `readyState=CLOSED` es fatal; `CONNECTING` mantiene vivo). (bbcfeaa)
- [x] **El feedback del usuario aparece** en su turno (era lang sin placeholder `{$a}`; ahora compone `label + ': ' + texto`). (6426169)
- [x] **`replace_activity`** (servicio): "cambia X por una <otro tipo>" reemplaza la actividad por una nueva del tipo pedido (delete+add, mismo slot); el plugin pone skeleton en el objetivo. Servicio PR #91 (893befc) + plugin (fa36eec). Verificado: book→assign.
- [x] **Detalle del plan en MARKDOWN debajo de cada sección del checklist, en TIEMPO REAL** (live) e idéntico al recargar. CORREGIDO 2026-06-24: el checklist se MANTIENE tal cual (nombre + spinner a la izquierda → check); lo único nuevo es que DEBAJO de cada item del checklist sale el texto de esa sección (descripción + actividades) en markdown con **View more/View less**, dividido por sección. (Antes —de59ddb— lo había reemplazado por texto plano sin checklist: MAL, revertido.) Impl: `handleSection` crea el item (spinner/check) + `.courseai-checklist-detail`; `ui/plan-transcript.js` rellena el detalle por sección desde los eventos (section/activity/detailed_plan_activity) y lo clampa; reload reconstruye checklist+detalle desde `payload.plan`. `ui/markdown.js` (`marked`) + CSS `.courseai-checklist-detail`/`.cg-detail-clamped`. Verificado e2e (spinner en vivo + detalle markdown debajo + check + View more/less, live y reload, 0 errores JS). LAYOUT 2026-06-24: quitada la caja envolvente (.cg-thread-checklist sin border/bg/padding) y la línea de timeline; cada sección es un item INDEPENDIENTE a ancho completo (head spinner+nombre, detalle bloque debajo, separador hairline); arreglado el solapamiento nombre/detalle. PULIDO 2026-06-24: nombre de sección más relevante (15.5px bold), menos contenido por defecto (clamp 120px), botón View more/less CENTRADO, toda la zona de desvanecimiento es clicable para expandir, y separación entre secciones más notoria (gap 26px + borde 2px).
- [x] **Proposals**: describen lo que la IA hará (no parafrasean el prompt), regenerar NO es destructivo, resaltan solo el elemento afectado, tarjeta plana, Enter envía, sin ✨. (servicio + plugin)

---

## 4. YA HECHO (resumen para contexto — no re-hacer)

- [x] Layout de 3 zonas + **divisor redimensionable** (splitter) — arreglado el bug de arrastre
  (default px 560, rango [320,720], tracking de puntero, storage v2).
- [x] **Reload sin perder avance**: snapshot vía `GET /course/state`, rehidratación por reconciliador
  (UUID), log reconstruido; el servicio reporta `WAITING_APPROVAL` por interrupt pendiente; checkpoint
  por sección (reanuda sin re-planificar).
- [x] **Skeletons que imitan la forma** (checklist + tarjetas) en reload y primera instrucción, solo
  en la zona de contenido (chrome estático se renderiza). Shimmer suavizado (2.4s ease-in-out).
- [x] **Checklist refleja avance real** (no marca todo hecho; data-attrs para que el stream lo actualice).
- [x] **Flujo de feedback**: mensaje único al final del feed, indicador "Analyzing your request…",
  **propuestas con opciones de confirmación en el panel IZQUIERDO** (no en el centro); textarea
  "Something else" solo cuando esa opción está activa; highlight de afectados al seleccionar (estilo
  a rehacer → P1).
- [x] **Stop/Resume** de la ejecución (congela sin loaders, corta el stream, reanuda con `keepPlan`).
- [x] **Panel izquierdo ancho por defecto** (~560px, redimensionable).
- [x] **Paleta calmada**: badges de tipo/purpose neutralizados a un gris único.
- [x] **Caja de chat** redondeada estilo Claude (focus-ring).
- [x] Bugs de campo: `assign` validation (servicio), `Script error for "Category"` (autocomplete),
  Stop clickable, sección fantasma "Section N:" al recargar.

---

## 5. Notas de implementación

- Archivos del preview a tocar para §2: `amd/src/local/courseai/detailed/section-row.js`,
  `activity-row.js`, `activity-dom.js`, `reconcile.js`; `styles/aicoursecreation.css`, `chatui.css`.
- Reusar clases de `core_courseformat`/Boost donde se pueda (heredar tema), o replicar exactamente las
  reglas citadas en §1 (chevron, separador punteado, hover outline, divider de inserción, add-section
  punteado).
- Verificar SIEMPRE con captura/e2e (puppeteer) que en hover aparecen las afordancias y que sin hover
  la vista queda limpia (sin `::` ni bordes permanentes), y que el highlight de afectados es sutil.

---

## 6. RÉPLICA EXACTA del centro = copiar el markup real de `core_courseformat` (PEDIDO FIRME)

> El usuario quiere que el preview central sea una **réplica EXACTA** de la vista Custom Sections,
> **copiando el markup real** de Moodle y **apoyándose en el CSS de Boost que YA está cargado** en la
> página del wizard (es una página Moodle). NO inventar CSS: emitir las MISMAS clases que
> `core_courseformat` y dejar que el tema las estilice. Esto es un **rebuild del renderer del preview**
> (`detailed/*.js`) + re-cableado del streaming/reconciliador a las nuevas clases. Trabajo dedicado.

### 6.1 Markup objetivo (copiar TAL CUAL, rellenando con datos del plan)
Contenedor (como Moodle): `<ul class="course-content course-section-list ...">` (o el `<ul>` del
formato) que envuelve las secciones.

**Sección** (`section.mustache`):
```html
<li id="section-{n}" class="section course-section main clearfix" data-for="section"
    data-id="{uuid}" data-number="{n}" data-sectionname="{name}">
  <div class="section-item">
    <!-- header.mustache -->
    <div class="d-flex align-items-center position-relative">
      <a role="button" data-toggle="collapse" data-for="sectiontoggler"
         href="#coursecontentcollapseid{uuid}" aria-expanded="true"
         class="btn btn-icon me-3 icons-collapse-expand justify-content-center">
        <span class="expanded-icon icon-no-margin p-2">{pix t/expandedchevron}</span>
        <span class="collapsed-icon icon-no-margin p-2">{pix t/collapsedchevron}</span>
      </a>
      <h3 class="h4 sectionname course-content-item d-flex align-self-stretch align-items-center mb-0"
          data-for="section_title" data-id="{uuid}" data-number="{n}">{name}</h3>
    </div>
    <div id="coursecontentcollapseid{uuid}" class="content course-content-item-content collapse show">
      <!-- resumen opcional + cmlist -->
      <ul class="section img-text">
        <!-- cmitem.mustache por actividad (ver 6.2) -->
      </ul>
      <!-- addsection/divider opcional -->
    </div>
  </div>
</li>
```

**Actividad** (`cmitem.mustache` + `cm.mustache` + `cm/activity.mustache` + `cm/cmname.mustache`):
```html
<li class="activity activity-wrapper {modtype} modtype_{modtype}" data-for="cmitem"
    data-id="{uuid}" data-cmid="{uuid}">
  <!-- divider de inserción (P5) en modo edición -->
  <div class="activity-item focus-control" data-region="activity-card">
    {moveicon}  <!-- handle de arrastre, visible solo en hover (Boost) -->
    <div class="activity-grid">
      <!-- cmname: icono + nombre -->
      <div class="activityname">
        <div class="activityiconcontainer {purpose} courseicon">  <!-- color por purpose lo da Boost -->
          <img class="activityicon" src="{icon pix mod_xxx}" alt="">
        </div>
        <div class="activitytitle ...">
          <span class="instancename">{title}</span>
        </div>
      </div>
      <!-- descripción/plan detallado (streaming) en un slot propio -->
    </div>
  </div>
</li>
```
> El **color del icono por purpose** lo aporta Boost via `.activityiconcontainer.content/.assessment/
> .collaboration/.communication` (las 5 categorías). El **hover outline**, el **separador**, el
> **handle solo-hover** y el **botón Add section/divider** vienen GRATIS del CSS de Boost al usar estas
> clases — por eso no se inventa CSS.

### 6.2 Re-cableado necesario (lo que hace que sea trabajo dedicado, no CSS)
El subsistema actual (`detailed/section-dom.js`, `section-row.js`, `activity-dom.js`, `activity-row.js`,
`reconcile.js`, `view.js`, `badges.js`) usa clases propias (`.prv-section-row`, `.dp-activity-wrap`,
`.prv-activity-item`, `.prv-activity-desc`, skeletons, etc.). Para la réplica hay que:
1. Reescribir los builders para emitir el markup de 6.1 (clases Moodle), conservando los hooks que el
   reconciliador necesita: `data-id`/`data-cmid` (= UUID) en `.activity` y `data-id`/`data-number` en
   `.course-section`.
2. Apuntar el relleno de detalle (`markActivityPlanned`, skeletons, `fillSkeletonActivities`) a un slot
   dentro de `.activity-grid` (no a `.prv-activity-desc`).
3. Re-apuntar `badges.js` (contador X/N) y el highlight `.cg-affected` a `.course-section`/`.activity`.
4. Re-apuntar el DnD (`wireDragAndDrop`) y los controles IA/eliminar a `.activity-item`/`.section-item`.
5. Colapso: usar el `data-toggle="collapse"` de Bootstrap (Boost ya trae el JS) en vez del toggle manual.
6. Verificar que Boost estiliza el markup en el contexto del wizard (puede requerir envolver el preview
   en un contenedor con las clases de página de curso que activan las reglas `.course-content ...`).

### 6.3 Estado
- [ ] **Réplica exacta** — PENDIENTE (rebuild dedicado, alto impacto/alto riesgo). Blueprint arriba.
- [x] Paso intermedio aplicado mientras tanto: iconos con **color suave por purpose** + texto neutro
  (opción elegida por el usuario), para que ya se acerque al look Custom Sections.

---

## 7. Pulido de campo (2026-06-22) — MAYORMENTE HECHO (7.1, 7.3, 7.4 hechos; 7.2 pendiente)

### 7.1 Panel izquierdo = chat de agente moderno — HECHO

> Implementado y verificado (reload sesión 157, Chromium propio): hilo único, prompt como primer
> turno de usuario, turnos IA con ✨, checklist como tarjeta de resultado en el hilo, fade+expand en
> mensajes largos (clamp 160px→expand), distinción sutil IA/usuario, sin rótulos INITIAL MESSAGE/
> ACTIVITY/COURSE SECTIONS. Reconstrucción del hilo al reload extendida. Cero errores JS.
>
> **Rediseño agrupado aplicado (branch `feat/chat-polish-v2`, 2026-06-22):** eliminados los N turnos
> planos "AI planned section: X" (uno por sección); el checklist `#courseaiChecklist` actúa ahora
> como UN único turno de asistente agrupado con cabecera `.cg-group-head` (avatar ✨ +
> `courseai_log_ai_planned_structure`). Al reload, `rebuildDecisionLog` ya no emite N turnos de
> sección; hace visible el checklist directamente y deduplica mensajes humanos consecutivos
> (compara con `lastEmitted`). Gap del log subido a 14px, line-height a 1.55, timestamp del
> turno-usuario solo visible en hover.

#### Spec original (referencia)
**Pedido:** el panel lateral debe SENTIRSE como una **UI de chat moderno de agentes** (estilo Lovable,
v0.dev, Bolt, Cursor agent, Claude/ChatGPT), no como tres bloques etiquetados sueltos.
- **Quitar la división "INITIAL MESSAGE"**: el prompt inicial del usuario NO debe ir como un bloque
  rotulado aparte arriba; debe ser el **primer turno del chat** (un mensaje del usuario más, dentro
  del mismo hilo cronológico).
- Conservar el contenido de **ACTIVITY** (el log de acciones IA/usuario) y **COURSE SECTIONS** (el
  checklist), pero integrados en un **único hilo conversacional** que fluye de arriba hacia abajo.
- **Patrones a extraer de esas herramientas (aplicar):**
  - **Hilo único continuo**, sin headers de sección tipo formulario; a lo sumo separadores muy
    sutiles o agrupación por turnos.
  - **Turnos diferenciados**: mensaje del usuario con un estilo (burbuja/alineación/inicial), y las
    **acciones del agente** como pasos inline con ícono sutil ("✨ Planeó la sección…", "🔧 aplicó…")
    — como los "steps"/"tool calls" compactos de Lovable/Cursor.
  - **Indicador de "pensando/trabajando"** inline mientras el agente actúa (ya existe el spinner del
    feedback; unificarlo al estilo de "agent is working…").
  - **Input fijo abajo** (ya está), conversación scrolleable arriba, autoscroll al último turno.
  - **Timestamps discretos**, densidad cómoda, paleta calmada con UN acento, mucho espacio en blanco,
    bordes mínimos. Nada de cajas rotuladas en mayúsculas tipo "INITIAL MESSAGE"/"ACTIVITY"/"COURSE
    SECTIONS" como secciones rígidas.
  - El **checklist de secciones** puede integrarse como un "bloque de resultado" del turno del agente
    (una tarjeta/lista compacta dentro del hilo), no como una sección fija aparte.
- Archivos probables: `templates/courseai_page.mustache` (quitar el bloque INITIAL MESSAGE y rótulos),
  `styles/chatui.css` + `aicoursecreation.css` (estilo de hilo/turnos), `courseai/bootstrap/ui-helpers.js`
  (emitLog) y `ui/log.js` (render de entradas como turnos de chat), `checklist-helpers.js`.
- **Acción previa recomendada:** investigar (WebSearch/WebFetch) capturas/patrones actuales de Lovable
  y v0 para extraer el layout de turnos y aplicarlo con fidelidad.

**ACLARACIÓN del pedido (2026-06-22) — comportamiento exacto:**
- Es un **chat en TODO el sentido**: un **histórico cronológico completo** de TODO lo que pasó con la
  IA. Empieza en el **primer prompt** del usuario y va acumulando **cada acción del usuario** y **cada
  cosa que la IA va generando** — desde la **planificación inicial** y luego tras **cada solicitud de
  ajuste** (replan, add/delete, feedback…). El usuario debe poder **ver el histórico de todo** lo que
  pidió y lo que la IA hizo, en orden.
- **Mensajes largos = fade + expandir** (como en la captura de referencia, p. ej. ChatGPT/Claude con
  bloques largos): cuando un mensaje (de la IA o del usuario) es muy largo, mostrarlo **truncado con
  un degradado de desvanecimiento** al final y un **control (chevron / "ver más")** para **expandir**
  el contenido completo; volver a colapsar. Aplica **tanto a mensajes de la IA como del usuario**.
  - Implementación sugerida: contenedor con `max-height` + `overflow:hidden` + máscara/gradiente
    inferior (`mask-image`/pseudo-elemento), y botón centrado abajo con chevron que togglea
    `expanded` (quita el max-height y la máscara). Detectar overflow real (scrollHeight > maxHeight)
    para mostrar el control solo cuando hace falta.
- **Diferencia sutil IA vs usuario**: NO burbujas opuestas exageradas; una **distinción discreta**
  (p. ej. el mensaje del usuario con un fondo/borde-izquierdo tenue o alineación leve, y el de la IA
  plano con su ícono ✨) — suficiente para distinguir quién habló, sin romper el hilo único.
- Persistencia: el histórico debe **sobrevivir al reload** (ya se reconstruye el log desde el
  snapshot; extenderlo para que el hilo completo —prompts + acciones IA por ronda— se rearme).

### 7.2 "Add activity" como en Custom Sections (hover entre actividades, no botón al final)
**Pedido:** el botón "+ Add activity" con borde punteado al FINAL de cada sección **no debe salir así**.
Debe comportarse como en la vista de curso real (Custom Sections): **al pasar el cursor sobre una
actividad** aparece la opción de **añadir una DEBAJO** (y por tanto también ENTRE dos actividades
existentes, no solo al final). Esto es el P5 ya documentado (§2 P5 + §6.2) — ahora confirmado y con
matiz extra:
- **Eliminar** el actual `.dp-add-activity-wrap` (botón punteado al final de la sección).
- Implementar la **zona de inserción on-hover** (el `.divider` de Moodle, §1.5): entre/junto a cada
  actividad, oculta por defecto, aparece en hover con un "+" para insertar en esa posición.
- El servicio YA acepta `position` en `add_activity` (ver §2 P5) → cliente envía `position` = índice de
  la actividad sobre/bajo la que se inserta.
- Enfoque de bajo riesgo (no pelear con el reconciliador): franja "insert-after" en el borde inferior
  de cada `.dp-activity-wrap`/`.activity`, visible solo en hover.

### 7.3 Mensajes del chat: sin « », acción+instrucción en UN turno con tipo — HECHO (server store IMPLEMENTADO; cabos sueltos abajo)

> **HECHO — almacén server-side de hilo (servicio PR #91 + plugin PR #15).** El servicio persiste
> cada mensaje renderable en `thread_messages` (tipado + ordenado) y lo manda en `snapshot.thread`;
> el plugin lo reproduce al recargar con `thread-replay.js` (`replayThread`). La estructura del plan
> se muestra como **transcript markdown por sección, en tiempo real** (live) e **idéntica al recargar**
> (`ui/plan-transcript.js` + `ui/markdown.js`, render con `marked`). Diseño completo en el TODO-V2 del
> servicio (sección "Thread-store").
>
> **Cabos sueltos — RESUELTOS (2026-06-24; detalle en servicio `TODO-V2.md`):**
> - [x] **Título del curso** (PR #15 + servicio PR #91): el servicio lo persiste al thread
>   (`AI_COURSE_CONFIGURATION`) y el plugin está alineado a `course_configuration` (`handlers.js`
>   registra `course_configuration: handleCourseConfiguration`, `thread-replay.js`
>   `ai_course_configuration`, `i18n.js`, lang `courseai_log_ai_course_configuration`="Course: {$a}"
>   + `ai_course_configuration`="Course: {$a->fullname}"). Se arregló además que el evento EN VIVO
>   estaba roto (plugin escuchaba `course_identity` vs `course_configuration` del servicio) y que el
>   lang viejo era 'Course' sin `{$a}`. Verificado e2e: "Course: <título>" en vivo y al recargar.
> - [x] **Errores**: el fallo FATAL ya se persiste (`AI_FAILED`) y se reproduce (`ai_failed: danger`);
>   no hay emisor de errores no-fatales en el servicio (slot `ai_error` sin uso).
> - [x] **Reload === live, "ni un detalle más ni uno menos"** (PR #15 + servicio PR #91, 2026-06-24).
>   El reload-diff (Puppeteer vs Moodle real) reveló 3 mismatches; los 3 corregidos →
>   `IDENTICAL: true`, 0 errores JS. (1) Texto de hito de review en reload usaba el catálogo
>   genérico del servicio → `thread-replay.js` ahora mapea (`MILESTONE_PLUGIN_TEXT`) a la misma
>   key prefetched que usa el handler en vivo (`courseai_log_ai_review_ready`/`_proposals_ready`/
>   `_completed`); re-añadido `texts` a `makeThreadReplay` (+ call en `courseai.js`). (2) Rounds de
>   review duplicados al recargar → idempotencia en el servicio (ver `TODO-V2.md` servicio).
>   (3) **Reorden detallado**: el vivo mostraba `You moved X to position N` pero el reload mostraba
>   el genérico `You reordered the activities` → `dnd.js` ahora envía `moved_id` en la acción;
>   `course_planning_feedback.php` declara `moved_id` en el schema externo (sin esto Moodle
>   rechazaba el call entero → el reorden no persistía); lang `log_moved_activity` + registro en
>   `i18n.js STRING_KEYS`; el servicio persiste el mismo string_id con `string_args` {title, position}.
> - [x] **Reorden tras RELOAD mostraba el genérico** (`checklist-helpers.js`, 2026-06-24). Tras
>   recargar, `sendReorderActivities` caía a "You reordered the activities in: <sección>" en vez de
>   "You moved X to position N". Causa: `buildSectionsFromDetailedPlan` reconstruía
>   `state.latestInitialSections` SIN el `id` de cada actividad, así que el lookup por id del
>   arrastrado fallaba. El path vivo funcionaba (`handleActivity` setea el id); el resumido lo perdía.
>   Fix: arrastrar `activity.id` en el rebuild. **Lección de verificación**: el `reload-diff` solo
>   reordenaba ANTES de recargar y solo comparaba live===reload (no que el reorden siguiera detallado),
>   así que el caso reorden-DESPUÉS-de-recargar nunca se cubrió. Ahora verificado e2e.
> - [x] **Reorden no-op no se loguea** (`dnd.js`, 2026-06-24). Soltar una actividad en su MISMA
>   posición mostraba "You moved X to position N" (p.ej. mover el quiz que ya estaba en la pos 2 →
>   "moved to position 2") sin que nada se moviera. `wireDragAndDrop` ahora toma un snapshot del
>   orden en `dragstart` (`orderAtStart`) y en `dragend` compara con el orden actual; si es idéntico
>   se omite `onReorder` (ni log ni call al servicio). Verificado: drag no-op = 0 turnos, 0 POSTs;
>   reorden real sigue `IDENTICAL: true`.
> - [x] **Bloque de regeneración en el panel izquierdo (replan_activity)** (2026-06-24, plugin-only).
>   Al pedir replanificar una ACTIVIDAD, ahora aparece un bloque NUEVO debajo de la instrucción —
>   igual al de la planificación inicial (item con spinner→check + detalle markdown clampado con
>   View more/less), streameando en vivo— con el head = TÍTULO DE LA ACTIVIDAD + su icono (el mismo
>   monologo.svg del centro, más pequeño). El checklist de arriba queda CONGELADO como la foto
>   inicial. Diseño aditivo con compuerta `state.regenScope` (se setea SOLO en replan vía
>   `runPlanAction`; reorden/inicial/accept lo dejan en `null`, así que su flujo verificado queda
>   intacto). Nuevo módulo `ui/regen-block.js`; hooks con compuerta en `handlers-content`
>   (`handleDetailedPlanActivity`) y `handlers-lifecycle` (`handleReviewNeeded` finaliza el bloque y
>   NO reconstruye el top; limpieza en `handleFailed`). Reload: `thread-replay` reconstruye el bloque
>   desde `user_action(target_ids)` + el `ai_planned_structure` siguiente (mismo renderer), y compone
>   el turno de instrucción ("{instruction} — {TypeLabel}: {title}") idéntico al vivo. Helpers nuevos:
>   `formatActivityDetailMd`, `clampDetail` exportado. CSS `.cg-activity-icon`. Verificado e2e (test
>   `replan-block.mjs`): bloque + secuencia `IDENTICAL` live===reload, icono presente, 0 errores JS;
>   reorden/inicial siguen `IDENTICAL` (test `reload-diff.mjs`).
> - [ ] **Bloque de regeneración para replan_SECTION** (pendiente). Falta cablear el caso de sección:
>   enrutar los eventos `section`/`activity`/`detalle` del replan de sección al bloque (head = nombre
>   de sección, como inicial, detalle = descripción + actividades), evitar que `handleSection`
>   contamine el checklist de arriba, y componer su turno de instrucción idéntico al vivo en reload.
>   Hoy un replan_section cae al comportamiento viejo (reconstruye el top + turno genérico al reload).
> - [x] **Hito de review distingue planificación inicial de ajustes posteriores** (2026-06-24).
>   "I finished planning your course…" solo tiene sentido tras la planificación INICIAL; tras un
>   ajuste (reorden, etc.) debe decir "I applied your changes. Take a look and tell me if you want
>   anything else." Nuevo lang `courseai_log_ai_review_updated` (+ STRING_KEYS). Vivo
>   (`handlers-lifecycle.js`): captura `firstReview = !state.planEverReviewed` ANTES de setear el
>   flag. Reload (`thread-replay.js`): `replayThread` cuenta los hitos de review y pasa
>   `firstReview = (enésimo === 1)` — NO se puede usar `planEverReviewed` en reload porque el
>   `ai_proposals_card` previo ya lo activa. Solo el 1er `ai_review_ready` usa el texto inicial.
>   Verificado e2e: review inicial vs 2 reordenes → "I finished planning" / "I applied your changes"
>   ×2, `IDENTICAL: true`, 0 errores JS.
**Pedido:** (a) quitar los guillemets « » de los mensajes; (b) el regenerar debe decir el TIPO de
actividad; (c) la instrucción del usuario + la acción NO deben ser dos turnos sino UNO; (d) PRINCIPIO:
en el chat se debe mostrar TODO lo que streamea el servidor y TODO lo que el usuario manda desde
cualquier parte, en detalle.
- [x] (a) « » eliminados de todos los lang (`«{$a}»` → `: {$a}`; sweep en en/fr/ru).
- [x] (b)+(c) Regenerar actividad = UN turno de usuario con el tipo: `"{instrucción} — {TipoLabel}: {nombre}"`
  (p.ej. "quiero que tenga 2 capitulos solamente — Page: ¿Qué es el Machine Learning?"). Se eliminó el
  doble log (instrucción + línea genérica). Regenerar sección también unificado a un turno con la
  instrucción. Verificado e2e (reload 157): +1 turno, incluye tipo, sin « », cero errores JS.
- [x] (d) PRINCIPIO — barrido completo HECHO (2026-06-22, branch `feat/chat-emit-sweep`). El hilo
  izquierdo es ahora un histórico coherente: cada hito del servidor es un TURNO permanente y cada
  evento transitorio alimenta UN solo indicador "trabajando…".
  - **Indicador "working" generalizado** (`ui/feedback-progress.js`): `showWorkingIndicator(texts,msg)`/
    `hideWorkingIndicator()` reutilizan la única entrada `#cgFeedbackThinking`, actualizando el texto
    in-place. `handleStatus` lo alimenta con el status localizado; se limpia al llegar `section`/
    contenido y en eventos terminales (review_needed/completed/failed/error), en `done`/`onerror` del
    EventSource (`connection.js`) y en el catch del feedback `accept`. `showFeedbackThinking`/
    `hideFeedbackThinking` quedan como alias compatibles.
  - **Turnos por hito SSE**: `course_identity` → "Course: <título>" (dedup vía `state.courseTitleLogged`,
    a prueba del reset en accept); `review_needed` → "review the plan" o "prepared suggestions";
    `completed` → "Course generated"; `error`/`failed` → turno danger (error dedup consecutivo).
  - **Turnos por acción de usuario**: añadidos los faltantes — aprobar (accept), reordenar secciones,
    reordenar actividades (nombrando la sección padre). Ya existían: feedback, propuestas apply/dismiss,
    regenerar/eliminar/añadir sección y actividad (con tipo), imágenes discard/regenerate, stop/resume,
    prompt inicial. Formato unificado: acción + tipo/nombre, sin « » (fallbacks JS también limpiados).
  - **Lang**: nuevas strings en `lang/en` + registradas en `i18n.js` KEYS.
  - **e2e** (reload sesión 157, Chromium propio): cero errores JS, sin « », regenerar actividad = +1
    turno con tipo ("Page: …"), regenerar sección = +1 turno con "Section: …", feedback muestra el
    indicador "Analyzing your request…". Captura `/tmp/cg-ui/chat-sweep.png`.

### 7.4 Réplica de la estética de **V0 de Vercel** (modo CLARO) — HECHO (modo claro con TOKENS DEL PLUGIN, no colores de V0; refinado 2026-06-24)

> **Referencia EXACTA: el panel derecho de chat de V0 de Vercel** (capturas aportadas por el usuario,
> modo claro — **el plugin SOLO tendrá modo claro**). El usuario quiere que el panel IZQUIERDO se vea
> "magistral" como V0: tan bien organizado y limpio. Decisión confirmada: **paleta CLARA adaptada a la
> anatomía/tamaños/organización de V0** (no oscuro), cohesionada con el centro claro de Moodle.
> Rama de trabajo: `feat/chat-polish-v2`.

#### 7.4.1 Tokens de diseño (modo claro, extraídos de las capturas V0)
- Fondo del panel: **blanco** `#ffffff`.
- Texto primario: `#18181b` (casi negro). Texto **muteado** (pasos, "Thought for", timestamps,
  secundario): `#8a8a8a`–`#a1a1aa`.
- Tarjeta del **mensaje de usuario**: fondo `#f4f4f5` (zinc-100), **sin borde** (o hairline `#ececee`
  muy tenue), radio **16px**, padding ~`16px 20px`.
- Línea/bordes sutiles (timeline, separadores, composer): `#e4e4e7` (zinc-200), 1px.
- Acento (botón primario, foco): el `$primary` de Boost (azul) — UN solo acento.
- Tipografía: heredar la de la página; lo crítico son **tamaños y espaciado**: cuerpo ~15px,
  line-height **1.6**, gap entre turnos cómodo (~20–24px), gap entre párrafos ~10–12px.

#### 7.4.2 Anatomía del hilo (mapear a nuestros elementos)
- **Turno de USUARIO** (`.cg-log-entry--turn-user`): tarjeta `#f4f4f5` radio 16px, ancho casi completo
  (NO burbuja chica a la derecha). Si es largo → **fade al color de la tarjeta** + enlace centrado
  **"Show full message"** (renombrar el actual "Show more" del turno de usuario) → expande. (Ya existe
  `wireFadeExpand`; ajustar etiqueta y el degradado para que termine en `#f4f4f5`.)
- **Turno del ASISTENTE** (`.cg-log-entry--turn-ai`): **plano, sin tarjeta**, texto primario, markdown,
  line-height 1.6, párrafos espaciados. El ✨ actual pasa a ser un avatar/ícono sutil al inicio del
  bloque (no en cada línea).
- **"Thought for Xs" / "Working"**: línea con ícono muteado + texto gris (reusar
  `#cgFeedbackThinking`/`showWorkingIndicator`; restyle al look V0: ícono pequeño + texto `#8a8a8a`,
  sin barra de color).
- **STEP GROUP (pasos del agente)** = el checklist agrupado (`#courseaiChecklist` / `.cg-group-head`
  + `.courseai-checklist-list`). Restyle a V0:
  - **Línea de timeline vertical** a la izquierda (1px `#e4e4e7`) que conecta los pasos.
  - Cada paso (`.courseai-checklist-item`): ícono pequeño muteado + label gris `#8a8a8a` ~14–15px.
    Estados: cargando = spinner sutil; hecho = ícono check muteado (NADA de verde fuerte).
  - **Cabecera colapsable** con **chevron a la izquierda** + título + conteo opcional ("• N
    secciones"), estilo "⌄ Explore • 4 Files". Click colapsa/expande la lista (Bootstrap collapse o
    toggle propio). Por defecto expandido.
- **Footer de turno** (opcional, baja prioridad): "Worked for Xs" + timestamp + "⋯" muteados bajo un
  turno de IA. Implementar solo si no arriesga el resto.

#### 7.4.3 Composer estilo V0 (= `#compactChatCard`, `chatui.css`)
- Contenedor redondeado **radio 16px**, borde `#e4e4e7` 1px, fondo blanco, sombra mínima; en foco,
  anillo de acento sutil.
- Textarea sin borde propio (el borde lo da el contenedor), placeholder muteado.
- **Fila inferior**: a la izquierda "+" (adjuntar/acciones, ghost circular) + selector de modelo tipo
  pill ("◉ Modelo ⌄" — mapear a lang/imágenes/directrices que ya existen, agrupados de forma limpia);
  a la derecha **botón de envío circular** con flecha ▲ (relleno acento cuando hay texto; el actual
  `#btnCompactRegenerate`/enviar). Reorganizar la toolbar actual (que tiene muchos botones sueltos)
  para que respire como V0.

#### 7.4.4 Overlay de DECISIÓN que TAPA el chat (pedido explícito) — el comportamiento clave
> En V0, cuando el agente necesita que el usuario decida, **oculta el chat** y muestra un **card de
> preguntas** centrado (opciones tipo radio + "Skip"/"Next" + "1 of N") para que el usuario se enfoque.
> El usuario quiere ESO para (a) la **aceptación del plan** y (b) las **propuestas/preguntas** de la IA.
- Cuando llega `review_needed` (plan listo para revisar):
  - Mostrar en el **panel IZQUIERDO** una **card de decisión que ocupa SOLO el lugar del input** (el
    campo de escritura), al fondo; el **hilo de mensajes sigue visible y scrolleable arriba**
    (aclaración del usuario: "tapar el chat" = solo el campo de escritura, NO los mensajes). Presenta
    la decisión: **"Accept"** (primario) / **"Adjust"** (secundario). (Mover/relocar
    `#planActions`+`#btnApprove` —hoy en el CENTRO, `templates/courseai_page.mustache:463`,
    `planning/review-actions.js`— a este overlay izquierdo; el centro ya no muestra acciones.)
  - Si hay **propuestas/clarificación** (`data.proposals`, hoy `#cgFeedProposals` vía `ui-proposals.js`
    en el feed izq): renderizarlas DENTRO del overlay como el **card de preguntas V0** (opciones radio,
    "Something else" solo al activarse, botones apply/dismiss o Skip/Next). El overlay tapa el chat.
  - **"Accept"** → flujo actual de aceptar (genera el curso); ocultar overlay.
  - **"Adjust"** → ocultar overlay y **recién ahí mostrar el input** (`#compactChatCard` →
    `setCompactChatState('enabled')`) para que el usuario escriba el ajuste. Al enviar, vuelve el hilo.
- Estética del overlay/card: **copiar tamaños y estilo del card de preguntas de V0** (radio 12–16px,
  borde `#e4e4e7`, padding ~20px, opciones con círculo + label, footer con conteo + botones).
- Implementación sugerida: un contenedor `.cg-decision-overlay` posicionado **absolute/sticky** sobre
  `.courseai-context-chat` (que ya es flex column), con `inset:0` y fondo blanco; togglear visibilidad
  por estado. Mientras está visible: `#courseaiChatScroll` y `#compactChatCard` ocultos (o el overlay
  encima con fondo opaco). En `accept`/`adjust` se desmonta.

#### 7.4.5 Archivos a tocar
- CSS: `styles/aicoursecreation.css` (bloques `.cg-log-entry*` 2933–3068, `.cg-thread-checklist`/
  `.cg-group-head` 2627–2656, `.plan-actions`/`.btn-plan-approve` 825–866, `.cg-feed-proposals`
  2501–2548) y `styles/chatui.css` (`.compact-chat-card`/toolbar 283–428).
- Markup: `templates/courseai_page.mustache` (relocar `#planActions` al panel izq / crear contenedor
  de overlay dentro de `.courseai-context-chat`; reorganizar la toolbar del composer).
- JS: `ui/log.js` (etiqueta "Show full message" + fade al `#f4f4f5`), `ui/feedback-progress.js`
  (restyle indicador), `planning/review-actions.js` (mostrar overlay izq en vez de acciones centro),
  `ui-proposals.js` (render dentro del overlay), `planning/compact-chat.js` (mostrar input solo en
  "Adjust"), `checklist-helpers.js` (cabecera colapsable + timeline en el step-group), `actions/feedback.js`
  (wire de accept/adjust con el overlay). Lang: etiquetas nuevas en `lang/en` + `i18n.js` KEYS.
- **Verificación obligatoria**: `npx grunt amd --root=local/coursegen`, reload sesión 157 con
  **Chromium propio de puppeteer** (`/tmp/cg-ui/.chromium-cache`, NUNCA chrome del sistema), cero
  errores JS, capturas del panel izq comparadas contra las capturas V0. NO push/merge hasta validar.

#### 7.4.6 Estado
- [x] 7.4.1 Tokens modo claro — HECHO, pero con los **tokens del PLUGIN** (`--primary: hsl(8,72%,42%)` rojo), NO los colores negros de V0 (corrección 2026-06-24, commit 62f76f0; el usuario rechazó la paleta V0).
- [x] 7.4.2 Anatomía del hilo (turnos usuario/IA, step-group con timeline + colapsable) — HECHO (feat/chat-polish-v2 b1fc67d + 6b1ab2f).
- [x] 7.4.3 Composer estilo V0 (botón circular ▲, controles secundarios ghost a la izquierda) — HECHO (f2e4bd6); botón de envío compacto 32px squircle.
- [x] 7.4.4 Overlay de decisión (accept/adjust + propuestas) que tapa el chat — HECHO (712f36a; ui/decision-overlay.js singleton, #cgDecisionOverlay dentro de #courseaiContextChat).
  - Verificado con Chromium propio de puppeteer (sesión 157): 0 errores JS, hilo 7 turnos, tarjeta usuario #f4f4f5/16px, timeline + colapsable OK, composer circular 36px/50%, overlay Accept/Adjust presente. Capturas: /tmp/cg-ui/v0-thread.png, v0-composer.png, v0-overlay.png.
- [x] 7.4.4-refinado (2026-06-24): el overlay tapa SOLO el composer (el hilo sigue visible); barra "still accept" (`#cgAcceptBar`) separada sobre el composer tras "Adjust" para poder aceptar siempre; una sola decisión/menú a la vez; Enter envía el feedback libre; eliminado el glifo ✨. Verificado e2e.

---

## Render left thread from server-side message store (single source of truth) — IMPLEMENTADO (PR #15 + servicio PR #91, 2026-06-24)

> **IMPLEMENTADO.** El thread store server-side está construido: el servicio persiste cada mensaje
> renderable y lo manda en `snapshot.thread`; el plugin lo reproduce con `replayThread` (P-1..P-3
> hechos, P-5 verificado). La estructura del plan se muestra como transcript markdown POR SECCIÓN en
> tiempo real (live) e idéntica al recargar (`ui/plan-transcript.js`, `ui/markdown.js`). Cabos
> sueltos en §7.3 (título/errores no persistidos; nombre `ai_course_identity` en el plugin).
> (Diseño original conservado abajo como referencia.) Companion to the SERVICE design in
> `course_ai/TODO-V2.md` → section "Server-side message thread store (single source of truth)".
> The SERVICE becomes the authority for the left-chat thread: it persists every renderable
> message (user actions, AI milestones, selected statuses) as an ordered, typed log. On reload the
> plugin makes ONE call and renders the returned `thread` array in order, keyed by `type`. The
> disparate, lossy reconstruction is DELETED.

### SCOPE CLARIFICATION (2026-06-23) — left = full plain-text history, center = latest only

The left panel must show the **complete planning transcript in plain text**, not just section
names: every AI output in full (sections + activities + descriptions + each activity's full
`detailed_plan`) and every user action, accumulating in chronological order. The CENTER preview
keeps showing only the **latest** reconciled version (unchanged: `hydrate-plan.js` + reconciler).
So the difference is history (left) vs latest state (center). Each AI-output `thread` message
carries the FULL content of that step (structured `payload` + a plain-text block in
`content.string`); the plugin renders it via a **plain-text block renderer** (reusing the existing
`wireFadeExpand` clamp for long blocks). The grouped "checklist of names" becomes/append-augments
these full-text blocks. The `type → renderer` map below gains a "plain-text content block" target
for AI-output types.

### Why (what we lose today)

On reload the plugin calls `local_coursegen_get_course_session_state`
(`amd/src/repository/courseai.js:89-98` → `classes/external/get_course_session_state.php`, which
proxies the service `GET /course/state/{thread_id}` and returns it verbatim as `snapshotjson`).
`bootstrap/resume-snapshot.js::rebuildDecisionLog` (resume-snapshot.js:84-137) then rebuilds the
thread from: the initial prompt, a dedup of `snapshot.messages` filtered to `type === 'human'`,
and ONE status-derived AI milestone (`WAITING_APPROVAL`/`PLANNING_ADJUST` → review/proposals;
`COMPLETED` → completed). Everything else is LOST: `ai_course_identity`, `ai_error`/`ai_failed`,
all user-action labels (accept, dismiss, stop, resume, add/delete/reorder/replan section/activity,
image discard/regenerate — every `emitLog` call across `amd/src/` that is fired live only). No
localStorage carries thread state in the popup. The full inventory of every `emitLog` site, its
trigger, and its canonical `type` is in the SERVICE doc's TYPE enum and was produced from:
`actions/generate.js:79`, `actions/feedback.js:133,144`, `stream/handlers-lifecycle.js:122,161,220,265`,
`stream/handlers-content.js:182`, `ui-proposals.js:273,282,307`, `actions/execution-control.js:118,148`,
`detailed/dnd.js:142,178`, `detailed/images.js:116,151`, `detailed/section-dom.js:147,179`,
`detailed/section-row.js:98,209`, `detailed/activity-dom.js:136,171`.

### Service contract consumed (see SERVICE doc for full JSON)

`get_course_session_state` returns `snapshotjson`, which now includes a `thread` array (the
SERVICE adds it to `GET /course/state`). Each element:

```jsonc
{ "seq": 0,
  "type": "user_prompt | user_action | ai_course_identity | ai_planned_structure |
           ai_review_ready | ai_proposals_ready | ai_proposals_card | ai_completed |
           ai_failed | ai_error | status",
  "role": "user | assistant | system",
  "content": { "string_id": "log_user_approved|null", "string": "fallback EN", "string_args": { } },
  "payload": { "subtype": "accept|adjust|…", "round": 1, "sections": [], "proposals": [], "…": "…" },
  "created_at": "ISO-8601" }
```

`status`-typed rows are TRANSIENT and are already EXCLUDED from `thread` by the service (the
working indicator on reload is driven by the session `status`, not by replaying a stored status).
The plugin localizes each message by `content.string_id` + `content.string_args` against the
Moodle lang file (the same KEYS already in `local/courseai/i18n.js` / `lang/en`), falling back to
`content.string` for free-form text (`string_id === null`).

### `type` → existing renderer map

A single `renderThreadMessage(msg)` dispatcher routes each `type` to the renderer that ALREADY
exists, so we reuse the current DOM/visual language:

| `type` (+ subtype) | Existing renderer | Notes |
|---|---|---|
| `user_prompt` | `ui/log.js` `add({actor:'user',kind:'user'})` | turn 1; full text |
| `user_action` (adjust/accept/proposal_*/dismiss/stop/resume/add/delete/reorder/replan/image) | `ui/log.js` `add({actor:'user', kind:…})` | kind chosen from subtype (success for accept/add, danger for delete/discard, neutral for dismiss/stop/resume, info for proposal_applied, user for adjust/reorder/replan); localize via `string_id` |
| `ai_course_identity` | `ui/log.js` `add({actor:'ai',kind:'ai'})` | "Course: {fullname}" from `payload.fullname` |
| `ai_planned_structure` | `stream/checklist.js` + `bootstrap/checklist-helpers.js` | grouped checklist card for `payload.round` from `payload.sections` (replaces the live `renderInitialChecklist`/round path on reload) |
| `ai_review_ready` / `ai_proposals_ready` | `ui/log.js` `add({actor:'ai',kind:'ai'})` | the milestone log turn |
| `ai_proposals_card` | `ui-proposals.js` `renderProposals(payload)` | the interactive proposals/clarification card (only the latest, if session is at review) |
| `ai_completed` | `ui/log.js` `add({actor:'ai',kind:'success'})` | |
| `ai_failed` / `ai_error` | `ui/log.js` `add({actor:'ai',kind:'danger'})` | |
| `status` | NOT replayed | working indicator comes from live `status`/SSE only |

`role`/`seq` ordering: render strictly in ascending `seq`. The `planEverReviewed` flag (which
splits `#cgLog` vs `#cgLogAfter` in `ui/log.js`) is set when the first review milestone is
replayed, so post-review turns land in `#cgLogAfter` exactly as live.

### Live behavior (unchanged in spirit)

During an ACTIVE session the plugin still renders from SSE in real time via the existing
`stream/handlers-*.js` and `emitLog` calls. The ONLY change: the SERVICE also persists each of
those messages as it emits them, so reload is a pure replay of the same sequence. The plugin's
live `emitLog` becomes a real-time echo of what the server is simultaneously recording — no
behavior change for the user mid-session.

### Refactor / deletions

- **DELETE** the heuristic reconstruction in `bootstrap/resume-snapshot.js`
  (`rebuildDecisionLog`, resume-snapshot.js:84-137) — replaced by `thread`-array replay.
- **DELETE / simplify** `bootstrap/adjustment-history.js` round rebuild from human messages
  (adjustment-history.js:58-128): rounds now come from `ai_planned_structure` + `user_action`
  entries in `thread`, in order.
- **KEEP** the plan/center reconciler hydration (`bootstrap/hydrate-plan.js`,
  `detailed/reconcile*.js`) — the plan tree still comes from `snapshot.detailed_plan_sections`;
  this design only changes the LEFT THREAD, not the center plan.
- **NO localStorage** thread logic to remove (none exists beyond the splitter width in
  `ui/splitter.js`, which stays).

### Back-compat

If `snapshot.thread` is empty or absent (old sessions created before the SERVICE migration), FALL
BACK to the current `rebuildDecisionLog` path. Keep the fallback until no pre-migration live
sessions remain, then remove it.

### Phased checklist (PLUGIN — after SERVICE S-1..S-4 land)

- [x] **P-1**: Added `renderThreadMessage(msg, ctx)` dispatcher + `replayThread(thread, ctx)` in
      `amd/src/courseai/bootstrap/thread-replay.js` (`makeThreadReplay`). Iterates ascending `seq`,
      routes by `type` to the existing renderers; AI-output types (`ai_planned_structure`) render the
      full `content.string` as an AI plain-text block via `ui/log.js add` (white-space:pre-wrap +
      `wireFadeExpand` clamp). Localizes via `string_id`/`string_args`, falls back to `string`.
- [x] **P-2**: In `bootstrap/resume-snapshot.js`, added `rebuildThread()`: when `snapshot.thread` is
      non-empty it replays via `replayThread` INSTEAD of `rebuildDecisionLog`, and
      `restoreAdjustmentHistory` is skipped. Empty/absent thread falls back to `rebuildDecisionLog`.
      Plan reconciliation (`hydrate-plan.js`) untouched.
- [x] **P-3**: Added the service `string_id`s (`ai_planned_structure`, `log_*`, plus
      `course_completed`/`course_failed`/`review_plan_detailed`) to `lang/en/local_coursegen.php` and
      `local/courseai/i18n.js` STRING_KEYS so they prefetch.
- [ ] **P-4**: Delete `rebuildDecisionLog` and the human-message round rebuild once `thread` is
      always present; keep the empty-`thread` fallback only during the deprecation window.
- [x] **P-5 Verify** — HECHO (2026-06-24, Chromium propio de puppeteer). Verificado e2e que el
      thread sobrevive al reload renderizando el transcript markdown por sección idéntico a la vista
      en vivo (mismo HTML: h3/strong/listas, htmlLen 9438), 0 errores JS. Capturas: rt-mid/rt-final/
      rt-reload, md-live/md-reload.

### Open decisions (mirror the SERVICE doc; confirm together)

1. Endpoint shape: consume `thread` inside `snapshotjson` (no Moodle WS change needed — recommended)
   vs add a dedicated WS for thread-only. Recommend the former.
2. Whether `ai_proposals_card` replays the FULL card or just the milestone log line when the
   session is past review (recommend: card only when session is currently at review, else the
   milestone log turn).
3. Confirm the kind-per-subtype mapping in the renderer table matches the current visual language.

---

## Streaming en vivo de add/regen de SECCIÓN al bloque de abajo — HECHO (2026-06-30)

- [x] **Bug**: add/regen de sección streameaba sus eventos al checklist de ARRIBA (lo ensuciaba: la
  sección aparecía ENCIMA de la instrucción, top live=4 vs reload=2) y el detalle solo se veía de
  golpe al final, nunca en tiempo real.
- [x] **Fix (plugin-only para el live)**: `regen-block.js` ahora streamea la sección a su propio
  bloque abajo (estructura/actividad/detalle → bloque, spinner→done en vivo). `handlers-content.js`
  rutea los eventos de add/regen-sección al bloque y congela el top; nombra el turno "You added
  section: X" cuando llega el nombre. `handlers-lifecycle.js` solo finaliza el bloque ya streameado en
  review (sin rebuild del top, sin turno duplicado). Orden del chat: turno → bloque en vivo →
  milestone, idéntico live y reload.
- [x] **Propuestas rutean como los controles inline**: `plan-action.js` acepta `scopeIntent`;
  `ui-proposals.js` pasa el intent real resuelto de la propuesta al aplicar → setea el mismo
  `addScope`/`regenScope` (antes solo lo hacían los controles inline). Verificado e2e en el flujo del
  composer (Adjust→feedback→propuestas→aplicar): tiempo-real (longitudes 774→2061→3386 con spinner),
  top congelado (=2), reload idéntico estructural.
- [x] **Highlight de la sección ancla**: elegir una propuesta de add_section no resaltaba nada
  (sin target id). Ahora deriva la sección "después de la cual" desde `intent.position` y la resalta en
  el centro (`affectedCount=1` verificado).
- [x] **thread-replay**: un row `proposal_applied` rutea la ronda siguiente por su `resolved_action`
  persistido, así reload coincide con live (bloque + top congelado).

---

## Resumen del plan aprobado tras "You approved the plan" — HECHO (2026-06-30)

- [x] Tras aceptar, se muestra el detalle COMPLETO de todo lo aprobado (todas las secciones con
  descripción, actividades y detailed_plan) justo debajo del turno "You approved the plan", como UN
  SOLO elemento condensado con UN solo "Show more" (no un bloque+toggle por sección, que duplicaba).
  `renderApprovedPlanSummary` concatena `formatSectionMd` de cada sección (nombre como `### heading`)
  en un único cuerpo markdown + `clampDetail`. Verificado: summaryCount=1, toggles=1, live==reload.
- [x] En vivo: desde `state.lastReviewedPlan` (cacheado en `handleReviewNeeded` desde `current_plan`);
  fallback `latestInitialSections`. En reload: thread-replay detecta el snapshot `ai_planned_structure`
  con `payload.approved` y renderiza lo mismo (cachea `lastReviewedPlan` para un accept post-reload).
- [x] El servicio persiste el snapshot aprobado (PR de servicio aparte). Verificado e2e: live==reload
  idéntico (3 secciones con detalle), reorden/inicial sin regresión.

---

## Ocultar el composer una vez aprobado el plan — HECHO (2026-06-30)

- [x] Tras aprobar la planificación el curso se crea y ya no se puede editar desde el wizard, así que el
  composer (#compactChatCard) se oculta desde la aprobación, durante toda la generación y tras completar.
  Bandera centralizada `state.planApproved`: el gate al inicio de `setCompactChatState` colapsa cualquier
  modo (disabled/enabled/reset) a `hidden` una vez aprobado. Se setea en `feedback.js` (action='accept',
  antes del primer setCompactChatState) y en reload (`resume-snapshot.js`) para GENERATING/PLANNING_ACCEPT
  y COMPLETED (+ hide directo del card). Verificado: oculto en generación/completado/reload; visible en
  review/adjust (planApproved=false).
- [x] FIX completado en vivo: el gate por JS perdía la carrera con setCompactChatState('enabled') al
  terminar la generación. Solución declarativa robusta: clase `body.cg-plan-approved` + CSS
  `#compactChatCard { display:none !important }` (gana sobre cualquier display inline). Se limpia en
  `setCompactChatState('reset')` (curso nuevo). Verificado: oculto tras aceptar, tras completar EN VIVO y tras reload.

---

## Fase de generación unificada con la planificación — HECHO (2026-06-30)

- [x] La generación ya NO usa el panel custom (pc-card con barra roja, círculos "01", badges
  "Book/Quiz"). Ahora reusa LAS MISMAS tarjetas de planificación (#prvSections, dentro de
  #planReviewCard) como vista de progreso read-only: cada actividad muestra spinner→check verde a la
  derecha a medida que se crea en Moodle. Coherencia total con la planificación.
- [x] `accept` ahora usa `keepPlan=true` para preservar las tarjetas del review (con descripciones
  completas) en vez de teardown+skeleton. `stream.js` (modo generating) muestra `#planReviewCard`,
  oculta el pc-card y agrega `body.cg-generating`. `tracker.js` agrega `id` al modelo;
  `tracker-renderer.renderGenerationTracker` ahora sincroniza el estado por actividad sobre las filas
  reales (por `data-activity-id`) con clases `cg-gen-pending/active/done` (el `cg-gen-*` cae en el
  `<li.activity-wrapper>`). CSS scoped a `body.cg-generating`: indicador de estado (hueco→spinner→check)
  + oculta acciones/dnd (read-only). `showCompletionView` limpia `cg-generating` y muestra el panel de
  éxito. Verificado e2e: vivo, reload durante generación y completion.

---

## Estado por actividad exacto + shimmer en generación — HECHO (2026-06-30)

- [x] BUG: en generación casi todas las actividades salían como "generadas" desde el inicio. Causa: las
  actividades se generan en PARALELO (asyncio.gather) y el plugin usaba una heurística de texto
  secuencial que marcaba "done" en cascada al empezar la siguiente. Fix: progreso ESTRUCTURADO por
  índice — el servicio (`process_activity_node`) emite `activity_progress_init{total}` +
  `activity_progress_start/done{index}` por actividad (índice de cola = índice de `tracker.flat`, ambos
  en orden de posición). El plugin (`handlers-progress` + `setTrackerFlatStatus`) marca `tracker.flat`
  por índice y `renderGenerationTracker` lo refleja en la fila real por `data-activity-id`. Verificado:
  progresión real pending→in_progress→done (t=0 todas A, ~17-21s pasan a D), sin "done" prematuro.
- [x] Indicador claro por estado (scoped `body.cg-generating`): IN PROGRESS = spinner en el icono +
  shimmer animado sobre el contenido (visible, estilo skeleton de planificación); DONE = check verde en
  el icono; PENDING = atenuado. Restaurado `cursor: pointer` en actividades expandibles (se rompió con
  cursor:default); solo se ocultan los botones de edición (read-only).
- [x] Ajuste visual + DnD: la card ahora se ve DESACTIVADA (atenuada + desaturada) mientras pending/
  in_progress y se ACTIVA (color pleno + check) al crearse, en vez del shimmer. Y se DESACTIVA el
  drag-and-drop en toda la generación: `state.isStreaming=true` en el bloque generating de stream.js
  (el gate de composer planApproved→hidden saltaba el branch disabled que lo seteaba). Verificado:
  dragstart prevented=true, sin dp-dragging; estados gris→color+check.
- [x] Pulido cursor/hover/chevron: (1) cursor de SECCIÓN = default en generación (ya no move/drag).
  (2) Las cards muestran cursor POINTER solo si tienen contenido colapsable (cg-activity--has-detail),
  default si no — en planificación Y generación (se quitó el cursor move de actividades). (3) En
  generación se suprime cualquier borde/outline de hover/focus (incl. cg-affected) — no son clickeables
  como para resaltar. (4) El "desactivado" pasó de opacity .5+grayscale a grayscale(1)+opacity .82 para
  no lavar el chevron de contenido colapsado (ahora visible en generación como en planificación).
- [x] Indicador de progreso/éxito rediseñado (la insignia verde rompía la estética): IN PROGRESS =
  card desaturada (grayscale) + SHIMMER skeleton suave (barrido blanco izq→der, 2.4s linear, blanco
  .6, sin color fuerte) — agradable y acorde a la UI. DONE = la card vuelve a color pleno (el color es
  el éxito), SIN insignia/badge. Se quitaron el spinner y el check verde del icono.
- [x] Revertido el shimmer (no gustó) → restaurado el indicador de circulito: spinner en el icono
  (in_progress) + check en el icono (done), con gris→color. Y BUG arreglado: el cursor move (drag) de
  planificación se había roto al cambiarlo a pointer; restaurado move en planificación (generación
  mantiene default/pointer read-only vía body.cg-generating).
- [x] Checks de generación: verde → color sobrio del check de sección (var(--muted-fg), círculo gris
  outline). Aplica al check de actividad (icono) Y al circulito de la cabecera (.prv-header--done
  .prv-icon-wrap, antes var(--success) verde). El verde "rompía la estética / payaso".
- [x] Cabecera de generación: mostraba el check "done" heredado del review mientras aún generaba;
  en el bloque generating de stream.js se resetea a SPINNER (remove prv-header--done, show spinner,
  hide check) hasta completar. Y el check de actividad ahora usa el SVG polyline del check de sección
  (crisp, en círculo gris) en vez de un glifo ✓ de texto (se veía feo).
- [x] Cabecera de generación: el subtítulo de status cambiaba de 1↔2 líneas por evento → temblores en
  toda la pantalla. Fijado a 1 línea con ellipsis (.prv-header-text en columna + .prv-header-sub nowrap
  + text-overflow) → altura constante (46px). Y se eliminó el live note "Showing real-time detailed
  planning progress." (quitado el bloque en handleStatus + del destructure).
- [x] El fix de jitter/live-note aplica a AMBAS fases: además de handleStatus (generación),
  init-view.js mostraba el live note al iniciar la vista detallada de PLANIFICACIÓN → ahora oculto.
  + min-height en .prv-header-sub para reservar la línea (altura de cabecera constante desde el inicio).
  Verificado: generación [46] estable, planificación sin el live note (var. 2px imperceptible).
- [x] Sincronización centro↔cabecera/izquierda en generación: la narración cruda por-actividad
  ("Assembling final Quiz package…", "Generating Chapter…") desfasaba con los checks (generación
  PARALELA → una card en check mientras la línea narra otra) y el spinner de la cabecera no se resolvía.
  Fix: durante generación estructurada se SUPRIME la narración cruda (handleStatus: working indicator +
  prvHeaderSub); la cabecera muestra un mensaje estable (course_creating) y se resuelve a CHECK cuando
  todas las actividades terminan (resolveGenerationHeaderIfDone en handlers-progress). Verificado:
  4/4 checks ⇒ header check, header nunca muestra narración cruda.
