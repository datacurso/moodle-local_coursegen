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
- [~] **Previsualización de selección antes de confirmar** (§6.1): las propuestas ya se previsualizan
  (highlight de afectados), pero falta pulir el "marcado pre-aplicar" (ligado a P1).
- [ ] **Plantillas Mustache propias** (§3, §9, §14): hoy el preview se construye por DOM manual en JS.
  Crear las plantillas (`preview_section.mustache`, `preview_activity.mustache`, etc.) que reusen las
  clases de `core_courseformat` para heredar el tema. (Mejora de mantenibilidad; opcional pero pedido.)
- [ ] **Accesibilidad** (§12): reordenar por teclado (secciones/actividades), roles `radiogroup`/`radio`
  correctos en las propuestas, foco visible.
- [ ] **Responsive** (§13): comportamiento en <1100px (el grid colapsa a 1 columna; revisar chat,
  proposals, skeletons en móvil/tablet).

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

## 7. Pulido de campo (2026-06-22) — POR IMPLEMENTAR

### 7.1 Panel izquierdo = chat de agente moderno (orgánico, sin "INITIAL MESSAGE")
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
