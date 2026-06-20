# Refactor de UI e interactividad — `local_coursegen`

> **Objetivo.** Convertir el planificador en una interfaz **viva, inmediata y profesional**: el
> usuario pide algo y ve resultados al instante, sin spinners genéricos. La **vista central** es una
> **previsualización del curso con el aspecto de un curso real de Moodle (formato por temas)** que se
> rellena en streaming y reacciona en tiempo real a cada decisión. La **vista lateral** es un
> **registro/log** cronológico de todo lo que pide el usuario y todo lo que hace la IA.
>
> Este documento cubre **todo lo visual y de interacción**. La arquitectura del código (módulos,
> reactive, ≤250 líneas) vive en [`TODO-v1.md`](./TODO-v1.md).

---

## Checklist

> Estado: `[x]` hecho · `[ ] (parcial)` parcialmente cubierto · `[ ]` pendiente.
> Auditado contra el código el 2026-06-19 (commits hasta `56c393f`).

- [ ] [1. Principios de experiencia](#1-principios-de-experiencia) (parcial: spinner `#planningLoading` aún vive junto a los skeletons)
- [x] [2. Layout: las tres zonas](#2-layout-las-tres-zonas)
  - [x] [2.1 Divisor redimensionable entre log y preview (sin anchos fijos)](#21-divisor-redimensionable-entre-log-y-preview-sin-anchos-fijos)
- [ ] [3. Vista central — preview del curso (estilo Moodle)](#3-vista-central--preview-del-curso) (parcial: render DOM manual, sin plantillas Mustache)
  - [ ] [3.1 Fidelidad visual a Moodle (formato **Custom Sections**, NO por temas)](#31-fidelidad-visual-a-moodle-formato-custom-sections-no-formato-por-temas) (parcial: CSS propio; rehacer estilo Custom Sections — ver pendientes al final)
  - [x] [3.2 Anatomía de una sección (preview)](#32-anatomía-de-una-sección-preview)
  - [x] [3.3 Anatomía de una actividad (preview)](#33-anatomía-de-una-actividad-preview)
  - [x] [3.4 Relleno progresivo (lo central del pedido)](#34-relleno-progresivo-lo-central-del-pedido)
- [x] [4. Vista lateral — registro/log de decisiones](#4-vista-lateral--registrolog-de-decisiones)
  - [x] [4.1 Qué registra (toda acción, sin excepción)](#41-qué-registra-toda-acción-sin-excepción)
  - [x] [4.2 Anatomía de una entrada de log](#42-anatomía-de-una-entrada-de-log)
  - [ ] [4.3 Comportamiento](#43-comportamiento) (parcial: append + autoscroll + aria-live; falta hover-entrada → resalta en preview)
- [ ] [5. Streaming sin spinners (aparición progresiva)](#5-streaming-sin-spinners) (parcial: skeletons + barra fina hechos, pero `#planningLoading` sigue)
  - [ ] [5.1 Reglas](#51-reglas) (parcial)
  - [ ] [5.2 Secuencia visual de una sesión](#52-secuencia-visual-de-una-sesión) (parcial)
- [x] [6. Interactividad en tiempo real (el corazón)](#6-interactividad-en-tiempo-real)
  - [ ] [6.1 Previsualización de selección (antes de confirmar)](#61-previsualización-de-selección-antes-de-confirmar) (parcial: propuestas sí; marcado pre-aplicar no confirmado)
  - [x] [6.2 Auto-foco al cambio](#62-auto-foco-al-cambio)
  - [x] [6.3 De dónde salen los cambios](#63-de-dónde-salen-los-cambios)
- [x] [7. Sistema de color semántico y tokens](#7-sistema-de-color-semántico-y-tokens)
- [x] [8. Sistema de movimiento (transiciones y timings)](#8-sistema-de-movimiento)
- [ ] [9. Catálogo de componentes visuales](#9-catálogo-de-componentes-visuales) (parcial: componentes en JS; sin plantillas Mustache)
- [ ] [10. Estados globales de la vista](#10-estados-globales-de-la-vista) (parcial: estados planning/review/`cg-plan-reviewed` ok; spinner inicial sigue)
- [x] [11. Microinteracciones y pulido profesional](#11-microinteracciones-y-pulido-profesional)
- [ ] [12. Accesibilidad](#12-accesibilidad) (parcial: aria-live + teclado del divisor; falta reorder por teclado y roles radio)
- [ ] [13. Responsive](#13-responsive)
- [ ] [14. Mapa de archivos afectados](#14-mapa-de-archivos-afectados) (parcial: faltan las 5 plantillas Mustache)
- [ ] [15. Persistencia de sesión: recargar sin perder avance](#15-persistencia-de-sesión-recargar-sin-perder-avance) (NUEVO)
- [ ] [16. Detener / reanudar la ejecución](#16-detener--reanudar-la-ejecución) (NUEVO)

---

## 1. Principios de experiencia

1. **Inmediatez.** Entre que el usuario manda una instrucción y que ve algo en pantalla NO debe
   haber latencia perceptible. Lo primero que llega del servidor (nombres de secciones) se pinta al
   instante; el detalle va rellenando esos huecos.
2. **Cero spinners genéricos.** Prohibido el spinner centrado "Analyzing…/Cargando…" como estado
   principal. Se reemplaza por **esqueletos (skeletons) ligados a la estructura real** que se van
   convirtiendo en contenido. La única señal de "trabajando" admitida es sutil (shimmer en el
   skeleton, cursor de escritura, barra de progreso fina superior).
3. **Interfaz viva.** Cada decisión —del usuario o de la IA— produce un **cambio visible y animado**
   en el preview, en tiempo real, con color semántico (rojo/eliminar, info/regenerar, success/añadir).
4. **Foco en el cambio.** Cada elemento que cambia se **enfoca automáticamente** (scroll-into-view +
   resaltado temporal) para que el usuario vea exactamente qué pasó.
5. **Trazabilidad.** Nada ocurre "en silencio": toda acción queda registrada en el log lateral.
6. **Profesional.** Densidad cómoda, jerarquía tipográfica clara, sombras suaves, espaciado
   consistente, transiciones pulidas. Nada tosco. Se respeta `prefers-reduced-motion`.
7. **Fidelidad Moodle.** El preview se ve como un curso real (formato por temas), reutilizando las
   clases visuales de `core_courseformat` para que el usuario reconozca el resultado final.

---

## 2. Layout: las tres zonas

```
┌───────────────────────────────────────────────────────────────────────────┐
│  NAVBAR (logo · título · cerrar)                                            │
├───────────────┬─────────────────────────────────────────────┬──────────────┤
│  LATERAL       │  CENTRAL — PREVIEW DEL CURSO                 │  (panel de    │
│  REGISTRO/LOG  │  (aspecto curso Moodle, por temas)          │   acciones)   │
│                │                                             │               │
│  · pide user   │  ▸ Tema 1: Introducción …                   │  ┌─ propuestas│
│  · IA cambió   │     ▫ 📄 Página: …                          │  │  ◉ opción A │
│  · acción btn  │     ▫ ❓ Quiz: …                            │  │  ○ otra …   │
│  · decisión    │  ▸ Tema 2: …                                │  └─[Aplicar]  │
│  …(scroll)…    │  … (streaming + transiciones) …             │  [Aprobar]    │
├───────────────┴─────────────────────────────────────────────┴──────────────┤
│  CHAT del usuario (input de instrucciones, siempre accesible)               │
└───────────────────────────────────────────────────────────────────────────┘
```

- **Grid CSS** (sustituye el grid actual de 2 columnas de `aicoursecreation.css` ~L127-154). Tres
  regiones: `log` (lateral izq., 300–360px), `preview` (central, `1fr`), y `panel de acciones`
  (propuestas/aprobar) que puede ser **columna propia a la derecha** o **sobreponerse al pie del
  preview** (recomendado: barra de acción fija al pie del preview + panel de propuestas dentro del
  preview, para que la decisión y su efecto estén juntos). El chat es una fila inferior full-width.
- **Comportamiento de las acciones de feedback**: cuando la IA propone opciones (flujo §5), el
  selector aparece **dentro/junto al preview**; al elegir una opción, el preview muestra de
  inmediato la **previsualización del cambio** (zonas en rojo, ver §6). La **decisión tomada** (qué
  eligió el usuario, qué hará) se anota en el **log lateral**.
- **Regla de oro del reparto**: *Central = QUÉ va a quedar (el curso). Lateral = QUÉ se decidió y
  quién lo decidió (la historia). Chat = QUÉ pide el usuario.*

### 2.1 Divisor redimensionable entre log y preview (sin anchos fijos)

La zona **lateral (log)** y la **central (preview)** **NO** tienen ancho fijo: el usuario ajusta el
reparto **arrastrando** una línea divisoria entre ambas.

- **Línea divisoria visible** (splitter) entre las dos zonas, siempre presente, que invita al arrastre
  (línea fina + "agarre" central de puntos/barras al hacer hover).
- **Cursor `col-resize`** (el puntero `<->`) sobre el divisor (y `cursor` activo durante el arrastre).
- **Implementación**: el grid de §2 usa una columna variable para el log y el divisor como columna
  propia, p. ej. `grid-template-columns: var(--log-w, 320px) 8px minmax(0, 1fr)`. Al arrastrar el
  divisor se actualiza la custom property `--log-w` (vía `requestAnimationFrame`, sin reflow por
  frame), con `clamp()` entre un **mín. y máx.** (ej. `clamp(240px, --log-w, 560px)`) para que el
  layout nunca se rompa.
- **Persistencia**: recordar el ancho elegido por usuario mediante preferencia
  de Moodle vía `core_user/repository` set_user_preference. Al recargar, se restaura.
- **Doble-click en el divisor** → restablece al ancho por defecto.
- **Pointer events** (no solo mouse): funciona con `pointerdown/move/up` + `setPointerCapture` para
  soportar touch/lápiz; se desactiva la selección de texto durante el arrastre (`user-select:none`).
- **Accesible**: el divisor es `role="separator"` con `aria-orientation="vertical"`,
  `tabindex="0"` y `aria-valuenow/min/max`; con foco, **←/→** ajustan el ancho en pasos (y `Home`/`End`
  a mín/máx). Hover/focus engrosa y realza el divisor.
- El **chat** inferior permanece full-width por debajo de ambas zonas (el divisor solo reparte la fila
  superior). El mismo mecanismo puede reutilizarse para el divisor preview↔panel de acciones si éste
  se mantiene como columna propia.

---

## 3. Vista central — preview del curso

### 3.1 Fidelidad visual a Moodle (formato **Custom Sections**, NO formato por temas)
> CORRECCIÓN (pedido en campo): el objetivo NO es el formato por temas clásico. El preview debe
> verse como el **formato Custom Sections** de Moodle 4.5 (secciones colapsables con chevron, lápiz
> de edición, "Collapse all/Expand all" y menú de 3 puntos; actividades como **filas separadas por
> líneas punteadas** —no tarjetas con borde permanente—; afordancias que aparecen SOLO en hover).
> Referencia visual: capturas del curso real adjuntas (sección colapsable + filas de actividad con
> separador punteado + botones inline de "Add activity or resource with AI" al pasar el cursor entre
> actividades). Ver detalle exhaustivo en "## Pendientes UI — fidelidad Custom Sections + hover" al
> final de este documento.

El preview debe parecerse a la vista de curso de Moodle 4.5. Reutilizar las clases visuales de
`core_courseformat` para heredar el look del tema activo (Boost), envolviendo el markup propio:

- Contenedor de curso: `.course-content` → la lista de secciones del formato Custom Sections.
- Sección/tema: `li.section` / `.course-section` con `.section-title` / `.sectionname` y
  `.section_availability` opcional; resumen en `.summarytext`.
- Lista de actividades: `ul.section` con `li.activity` `.activity-item`.
- Actividad: `.activityinstance` › `.activityname` (enlace/título) + `.activityicon`/`.activity-icon`
  (icono del módulo). Tinte por **purpose** del módulo (mapa de 5 categorías:
  `administration|assessment|collaboration|communication|content`) con la clase de color de fondo
  del icono que ya aplica Boost.
- Iconos de módulo: usar el patrón de URL ya existente en `utils.js` (`getActivityIconUrl`) →
  `pix/<mod>/icon` por tipo de actividad (quiz, page, book, assign, forum, …).

> No se pueden renderizar las plantillas core directamente (requieren contexto PHP), así que se crean
> **plantillas Mustache propias** que **reusan las clases CSS de core** para verse idénticas. Ver
> `preview_section.mustache` / `preview_activity.mustache` en `TODO-v1.md` §4.

### 3.2 Anatomía de una sección (preview)
- **Cabecera**: número/topic + nombre editable visualmente, contador de actividades, y los
  **controles por sección** (IA/ajustar, eliminar, añadir actividad, agarre de arrastre). Los
  controles aparecen sutiles y se realzan en hover (no saturar).
- **Resumen**: 1–2 líneas de descripción de la sección.
- **Lista de actividades** (ver 3.3).
- **Drop zone**: al final, el control "+ Añadir actividad".

### 3.3 Anatomía de una actividad (preview)
- **Icono del módulo** (con tinte por purpose) a la izquierda.
- **Título** (nombre de la actividad) + **badge del tipo** (Quiz, Página, Tarea…).
- **Detalle**: descripción/plan detallado que llega en streaming (capítulos, preguntas, etc.).
- **Imágenes sugeridas** (§11 del flujo): chips/tarjetas con su prompt; botón IA (`replan_image`) y
  descartar (`discard_image`). Las descartadas no se pintan.
- **Controles por ítem**: IA/ajustar (`replan_activity`), eliminar (`delete_activity`), agarre de
  arrastre. Sutiles, realce en hover; tooltips con `core/str`.

### 3.4 Relleno progresivo (lo central del pedido)
Secuencia de aparición en la vista central (sin spinner genérico):

1. **Instrucción enviada** → aparece de inmediato el **esqueleto de las secciones** a medida que el
   servidor emite eventos `section` (nombre + descripción). Cada sección entra con su nombre real ya
   visible y sus actividades como **filas-esqueleto** (shimmer).
2. **Plan inicial** → al llegar eventos `activity`, cada fila-esqueleto se convierte en una actividad
   real (icono + título + badge), aún sin detalle.
3. **Plan detallado** → al llegar `detailed_plan_field`/`detailed_plan_activity`, cada actividad se
   **rellena** con su contenido (descripción, capítulos, preguntas, imágenes), con una transición de
   skeleton→contenido suave. Es el "ir rellenando lo de la planificación detallada".
4. **Revisión** (`review_needed`) → el preview queda completo y editable; aparecen las acciones.

> La estructura del skeleton **no es genérica**: refleja la cantidad real de secciones/actividades
> conforme se conocen. El usuario ve "la forma" del curso desde el segundo 1.

---

## 4. Vista lateral — registro/log de decisiones

Inspiración: un **feed cronológico tipo log** (como un panel de actividad/timeline). No es solo el
índice de secciones de hoy: es el **historial vivo** de la conversación de planificación.

### 4.1 Qué registra (toda acción, sin excepción)
- **Petición del usuario**: cada instrucción de texto libre que manda.
- **Interpretación/decisión de la IA**: "Interpreté tu pedido como…" (las propuestas generadas).
- **Decisión del usuario**: qué propuesta eligió / si descartó / si aprobó.
- **Acción ejecutada** (de cualquier origen — botón, opción, drag&drop):
  `Eliminó la sección «X»`, `Regeneró la actividad «Y»`, `Añadió «Z»`, `Reordenó secciones`,
  `Descartó la imagen de «W»`.
- **Hitos del flujo**: planificación iniciada, plan listo para revisión, curso generado.
- **Errores**: localizados, con su motivo.

### 4.2 Anatomía de una entrada de log
- **Icono/avatar de actor**: 👤 usuario vs ✨ IA vs ⚙️ sistema (color distinto por actor).
- **Línea principal**: verbo + objetivo con el **nombre real** del elemento (no índices).
- **Marca de color semántico** a la izquierda (barra fina): rojo (destructivo), info (regenerar),
  success (alta), neutro (informativo).
- **Timestamp relativo** ("hace 5 s") que se actualiza.
- **Detalle expandible** opcional (instrucción completa, motivo de una propuesta caída).
- **Estado**: pendiente / aplicado / fallido (los pendientes con un sutil pulso).

### 4.3 Comportamiento
- **Append-only, autoscroll** al fondo cuando llega algo nuevo (con "saltar al final" si el usuario
  scrolleó arriba).
- **Sincronía con el preview**: al pasar el cursor (hover) sobre una entrada que referencia un
  elemento, ese elemento se **resalta** en el preview; al hacer click, **scroll-into-view** a él.
- Los **nombres de sección** siguen apareciendo aquí mientras se planifica (como hoy), pero
  integrados como entradas del log (`Planificó la sección «X»`), no como una lista aparte.
- `aria-live="polite"` para que lectores de pantalla anuncien cada entrada nueva.

---

## 5. Streaming sin spinners

### 5.1 Reglas
- **Eliminar** el overlay `#planningLoading` ("Analyzing your feedback…") como estado principal.
- **Skeletons estructurales**: cada sección/actividad conocida se pinta como placeholder con shimmer
  hasta que llega su contenido; entonces hace cross-fade a real.
- **Señal de actividad sutil**: barra de progreso fina (2px) en el borde superior del preview
  mientras hay stream abierto, y/o un cursor de escritura (`▍`) en el texto que se está escribiendo.
  Nunca un spinner que tape el contenido.
- **Feedback libre**: al enviar una instrucción de texto, en vez de "Analyzing…", el **chat** muestra
  un estado inline ("Interpretando…") y el **log** registra la petición de inmediato; las propuestas
  aparecen cuando llegan.

### 5.2 Secuencia visual de una sesión
```
[usuario envía contexto]
  → preview: skeleton de N secciones aparece (nombres reales conforme llegan)   (evento section)
  → preview: cada sección llena sus filas de actividad                          (evento activity)
  → preview: cada actividad se rellena con su detalle                           (detailed_plan_*)
  → preview: completo + barra superior se apaga                                 (review_needed)
  → log:     "Plan inicial listo · 5 secciones, 18 actividades"
```

---

## 6. Interactividad en tiempo real

El núcleo del rediseño. **Tabla canónica acción → feedback visual**:

| Gesto del usuario / IA | Feedback inmediato en el preview | Color | Transición | Foco |
|---|---|---|---|---|
| **Seleccionar** una opción que sugiere la IA | Resaltar la(s) **zona(s) afectada(s)** (sección/actividad objetivo) como *preview del cambio* | **danger (rojo)** si es destructiva; **info** si regenera; **success** si añade | `outline` + `flash` suave que **se mantiene** mientras la opción está seleccionada | scroll-into-view a la zona objetivo |
| **Eliminar** (sección/actividad/imagen) | Resaltado rojo → **fade-out + colapso de altura** → se quita del DOM | **danger** | `flash-danger` (≈250ms) → `collapse-fade` (≈320ms) | scroll al elemento antes de animar |
| **Regenerar / ajustar** un ítem | El contenido viejo hace **cross-fade** al nuevo; borde **info** parpadea | **info (azul)** | `flash-info` durante el reemplazo; skeleton breve si tarda | scroll-into-view al ítem |
| **Añadir** un elemento | Se inserta y se **resalta success** un instante; entra con `slide/scale-in` | **success (verde)** | `enter-success` (≈400ms) + hold ≈900ms → fade del realce | scroll-into-view + foco al nuevo |
| **Reordenar** (drag&drop) | Animación de movimiento de las filas a su nueva posición | neutro/brand | técnica **FLIP** (translate animado) | mantener el arrastrado a la vista |
| **Aprobar / descartar** | El preview pasa a estado final / se limpian propuestas | neutro | fade de las marcas pendientes | — |

### 6.1 Previsualización de selección (antes de confirmar)
Cuando el usuario **marca** una propuesta pero **aún no aplica**:
- El preview entra en modo "preview de cambio": la zona objetivo se marca (rojo si borra, info si
  regenera, success si añade) **sin** ejecutar nada todavía.
- Si cambia de opción, la marca anterior se limpia y se marca la nueva.
- Al **Aplicar**, la marca de preview se convierte en la transición real (eliminar/regenerar/añadir),
  y el servidor re-streamea el resultado.

### 6.2 Auto-foco al cambio
- Helper único `focusChange(el, kind)`:
  1. `el.scrollIntoView({behavior:'smooth', block:'center'})`,
  2. aplica la clase de realce temporal (`is-marked-{danger|info|success}`),
  3. la retira tras el hold (timeout) o al siguiente cambio.
- **Una sola** marca activa por elemento; cambios consecutivos encolan foco (no saltar errático).

### 6.3 De dónde salen los cambios
- Tras `execute_proposal`/`replan_*`/`delete_*`/`add_*`/`reorder_*` el servidor **re-streamea** el
  plan. El reconciliador del preview (componente sobre el reactive) hace **diff** entre el plan
  anterior y el nuevo y dispara la transición correcta por cada elemento que cambió (añadido /
  eliminado / modificado / movido). Así la animación refleja el cambio real, no una suposición.

---

## 7. Sistema de color semántico y tokens

Reusar y ampliar el bloque `:root` actual de `aicoursecreation.css`. Tokens semánticos:

```css
:root {
  /* base ya existente: --bg --fg --card --border --muted-fg --brand --primary --success ... */

  --danger:        hsl(8 72% 48%);     /* destructivo / eliminar / preview de borrado */
  --danger-soft:   hsl(8 72% 48% / .10);
  --info:          hsl(212 90% 50%);   /* regenerar / cambio en curso */
  --info-soft:     hsl(212 90% 50% / .10);
  --success:       hsl(142 70% 40%);   /* añadir / confirmación */
  --success-soft:  hsl(142 70% 40% / .12);
  --warn:          hsl(38 92% 50%);    /* advertencias / propuestas caídas */
  --warn-soft:     hsl(38 92% 50% / .12);
}
```

Uso:
- **danger** → eliminar, y preview de selección destructiva, barra del log de acciones destructivas.
- **info** → regenerar/ajustar, cambio en curso.
- **success** → añadir, alta confirmada.
- **warn** → propuestas caídas (informativas, no seleccionables), avisos.
- **brand/primary** → marca, foco, botones primarios (mantener identidad actual).

> Definir todos los realces con el token *soft* para el fondo y el token sólido para el borde/barra.

---

## 8. Sistema de movimiento

Tokens de animación (en `:root`), respetando `prefers-reduced-motion`:

```css
:root {
  --t-fast:   120ms;
  --t-base:   200ms;
  --t-slow:   320ms;
  --ease:     cubic-bezier(.2, .8, .2, 1);   /* salida suave */
  --hold-mark: 1100ms;                        /* cuánto se mantiene un realce success/info */
}
@media (prefers-reduced-motion: reduce) {
  *,*::before,*::after { animation-duration:.001ms !important; transition-duration:.001ms !important; }
}
```

Keyframes/clases requeridas (en CSS, aplicadas por el helper `focusChange` / el reconciliador):
- `@keyframes flash-danger` / `.is-marked-danger` → borde+fondo danger que pulsa y se mantiene.
- `@keyframes flash-info` / `.is-marked-info` → idem info (regenerar).
- `@keyframes enter-success` / `.is-marked-success` → entrada con `scale(.98→1)`+fade, fondo success.
- `.is-removing` → `opacity 1→0` + `max-height`→0 (colapso) en `--t-slow`.
- `.is-regenerating` → skeleton/blur breve del contenido durante el reemplazo.
- **FLIP** para reordenar: medir posición previa, aplicar `transform` invertido, animar a `0`.
- `.skeleton` / `@keyframes shimmer` → placeholder con gradiente animado.
- Barra superior de progreso fina `.preview-streaming-bar` (indeterminada sutil mientras hay stream).

---

## 9. Catálogo de componentes visuales

Cada uno se rediseña como componente (`TODO-v1.md` §5) + plantilla Mustache:

| Componente | Plantilla | Detalle visual clave |
|---|---|---|
| **Sección (preview)** | `preview_section.mustache` | Cabecera tipo tema Moodle, contador, controles en hover, resumen, drop-zone. |
| **Actividad (preview)** | `preview_activity.mustache` | Icono mod con tinte purpose, título, badge tipo, detalle, controles. |
| **Imagen sugerida** | `preview_image.mustache` | Tarjeta con prompt/placement, botón IA + descartar; estado "descartada" oculto. |
| **Selector de propuestas** | `proposal_option.mustache` | Tarjetas de elección única; realce `destructive`; "Otra cosa" con textarea; Aplicar/Descartar. |
| **Entrada de log** | `log_entry.mustache` | Actor, verbo+target, barra de color, timestamp relativo, expandible, estado. |
| **Barra de acciones** | (en `preview` o `panel`) | Aprobar (primario) + acceso a texto libre; siempre visible en revisión. |
| **Chat del usuario** | (footer) | Input claro, estado inline ("Interpretando…"), sin spinner. |
| **Skeletons** | parte de los anteriores | Filas/bloques con shimmer ligados a la estructura real. |
| **Pasos/fase** | mínimo | Indicador discreto de fase (contexto→plan→revisión→generación), sin spinner. |

Reglas de estilo comunes: radios `var(--radius)`, sombras `--shadow-card`, foco visible
(`:focus-visible` con anillo brand), densidad cómoda, tipografía con jerarquía (título sección >
título actividad > detalle), iconografía consistente (SVG inline o pix de Moodle).

---

## 10. Estados globales de la vista

Cada estado tiene un diseño propio; **ninguno usa el spinner genérico**:

- **Contexto** (antes de planificar): formulario de contexto limpio (lo actual, pulido).
- **Planificando**: preview con skeletons rellenándose + barra superior fina + log activo.
- **Revisión** (`review_needed`): preview completo y editable, acciones visibles, propuestas si las hay.
- **Aplicando un cambio**: solo el/los elementos afectados muestran su transición (info/skeleton);
  el resto del preview permanece interactivo.
- **Generando** (tras aprobar): preview pasa a "creando el curso" con progreso real por actividad
  (no spinner), el log narra cada paso.
- **Hecho**: confirmación success + enlace al curso.
- **Error**: tarjeta de error localizada (no overlay), con acción de reintento; el log registra el error.

---

## 11. Microinteracciones y pulido profesional

- **Hover de controles**: los botones por ítem aparecen tenues y se realzan al hacer hover sobre la
  sección/actividad (no saturar la vista con botones siempre a tope).
- **Foco accesible**: `:focus-visible` con anillo brand en todos los interactivos.
- **Tooltips** con `core/str` en los iconos de acción.
- **Estados de carga locales**: un botón que disparó una acción muestra estado ocupado **en sí mismo**
  (no bloquea toda la vista).
- **Sombras y profundidad** sutiles para separar paneles; borde 1px + sombra suave (tokens actuales).
- **Tipografía**: tamaños y pesos coherentes; truncado con ellipsis donde haga falta; line-height cómodo.
- **Densidad**: padding consistente (escala de 4px), gaps regulares.
- **Sin parpadeos**: las transiciones entran/salen suaves; nada de saltos de layout (reservar espacio
  con el skeleton).
- **Drag&drop pulido**: agarre claro (handle), `cursor: grab/grabbing`, placeholder de destino,
  no arrastrar desde texto seleccionable.

---

## 12. Accesibilidad

- **`aria-live`**: el log es `polite`; los cambios importantes del preview se anuncian.
- **Foco gestionado**: al añadir/regenerar, mover el foco al elemento nuevo/cambiado.
- **Teclado**: toda acción (seleccionar propuesta, aplicar, eliminar, añadir, reordenar) accesible por
  teclado; reordenar con teclas además del drag (mover arriba/abajo).
- **Contraste** AA en texto y en los realces semánticos.
- **`prefers-reduced-motion`**: desactiva animaciones no esenciales (ver §8); los cambios siguen
  siendo visibles (color/borde) aunque sin movimiento.
- **Roles correctos**: `radiogroup`/`radio` en propuestas, `list`/`listitem` en log y secciones.

---

## 13. Responsive

- **Escritorio ancho**: 3 zonas (log · preview · acciones) como en §2.
- **Medio**: el panel de acciones se integra al pie del preview; log lateral se mantiene.
- **Angosto/móvil**: paneles colapsables — el log pasa a un cajón (drawer) accesible por botón; el
  preview ocupa el ancho; el chat queda fijo abajo. Nada se rompe ni hace scroll horizontal.

---

## 14. Mapa de archivos afectados

> Referencias para orientar; el detalle de partición de código está en `TODO-v1.md`.

- **Plantillas (nuevas, Mustache)** en `templates/`: `preview_section.mustache`,
  `preview_activity.mustache`, `preview_image.mustache`, `log_entry.mustache`,
  `proposal_option.mustache`. Reusan clases de `core_courseformat` para el look Moodle.
- **`templates/courseai_page.mustache`** (597): reestructurar el layout a las 3 zonas (§2); quitar el
  overlay `#planningLoading`; contenedores `#coursePreview`, `#planLog`, barra de acciones, chat.
- **`styles/aicoursecreation.css`** (2347): añadir tokens semánticos (§7) y de movimiento (§8),
  keyframes de realce/entrada/salida/shimmer/FLIP, el grid de 3 zonas, los estilos de preview que
  imitan el curso Moodle, el log, las propuestas y los skeletons. Revisar/limpiar reglas muertas tras
  retirar el DOM manual.
- **Componentes JS** (sobre `core/reactive`, ver `TODO-v1.md` §5): `components/preview.js` (render +
  reconciliador/diff + transiciones + `focusChange`), `components/log.js`, `components/proposals.js`,
  `components/chat.js`, `components/steps.js`.
- **`ui/splitter.js`** (nuevo, ≤120 líneas): divisor redimensionable log↔preview (§2.1) — pointer
  events, `clamp` de `--log-w`, persistencia, teclado, doble-click a default. El layout y el cursor
  `col-resize` viven en CSS; este módulo solo gestiona el arrastre.
- **`styles/aicoursecreation.css`**: grid de 3 zonas con la **columna del divisor** y la variable
  `--log-w`, el estilo del splitter (línea + agarre en hover/focus), `cursor: col-resize`, y
  `user-select:none` durante el arrastre.
- **Stream → mutaciones** (`stream/*`): alimenta el estado; el preview reacciona y anima por diff.
- **`utils.js`**: `focusChange`, helpers de iconos/purpose, formateadores; sin duplicados.

---

## 15. Persistencia de sesión: recargar sin perder avance

> NUEVO. El usuario debe poder **recargar la página** (F5, cierre accidental, navegación) y
> reencontrar el plan/curso **en el mismo punto**, sin perder lo avanzado.

**Estado base (servicio):** YA soportado server-side vía el checkpointer de LangGraph — los
servicios releen el snapshot del `thread_id` (`_get_state`) en cada apertura de stream y reanudan
con `Command(resume=...)` si hay un snapshot en curso. **Lo que falta es del lado plugin.**

**Reglas:**
1. Al cargar la página, si hay un `recordid`/`thread_id` de una sesión en curso (persistido en la
   URL o en `user preferences`), el plugin debe **rehidratar la vista** desde el snapshot del
   servicio: estructura del plan, planes detallados, estado de revisión/streaming, y el log de
   decisiones hasta donde quedó.
2. La rehidratación debe respetar el reconciliador por UUID (no re-renderizar desde cero): pinta el
   plan tal como estaba, sin animaciones de "nuevo".
3. Si al recargar el servidor seguía generando, el plugin reabre el stream y continúa mostrando el
   avance (no reinicia).
4. El `recordid` se persiste de forma estable (URL param o `core_user` pref) para sobrevivir la
   recarga.

**Trabajo plugin:** revisar/endurecer `courseai/bootstrap/resume-snapshot.js` para reconstruir el
preview + el log desde el snapshot; persistir/leer el `recordid`; reabrir el stream en estado
correcto (planning vs generating) usando el flag `keepPlan`.

**Trabajo servicio (si hace falta):** garantizar que el snapshot incluye lo necesario para
reconstruir el log/decisiones; ver el TODO del servicio (`TODO-V2.md`, sección de control de
ejecución y persistencia).

## 16. Detener / reanudar la ejecución

> NUEVO. Un botón **Detener** que pausa la ejecución cuando el usuario quiera, con posibilidad de
> **reanudar** cuando quiera. Detener **solo pausa** (no cancela/descarta), corta el consumo de
> tokens de Gemini, y la interfaz queda **congelada como estaba al momento del stop pero sin los
> estados de loading**.

**Reglas (UI):**
1. **Botón Detener** visible durante cualquier fase activa (planificación detallada / generación).
   Al presionarlo: cierra el stream del cliente y pide al servicio detener la ejecución.
2. **Congelar sin loadings:** todo lo ya renderizado se conserva exactamente como estaba; se
   retiran TODOS los estados de carga (skeletons, barra de stream, spinner `#planningLoading`,
   `dp-item-regenerating`, pulsos). Nada de spinners "colgados".
3. **Botón Reanudar:** reabre el stream y continúa desde donde el servicio dejó el checkpoint
   (mismo mecanismo que §15). El usuario reanuda cuando quiera.
4. El detener debe reflejarse en el **conteo de tokens**: no se cobran/cuentan tokens de Gemini
   posteriores al stop (lo ya consumido hasta el corte sí se reporta).

**Estado base (servicio):** NO existe cancelación de una ejecución en curso (solo el `interrupt`
de aprobación, que es otra cosa). Hace falta soporte de servicio para detener la tarea del grafo,
cerrar el stream de Gemini, persistir el checkpoint en el punto de corte y reportar solo los tokens
consumidos. Ver `TODO-V2.md` (control de ejecución).

**Trabajo plugin:** botón Detener/Reanudar; al detener, limpiar todos los estados de loading y
dejar el preview/log congelados; al reanudar, reabrir stream (keepPlan) y seguir.

---

## Notas de implementación

- Las animaciones de cambio se disparan **por diff del plan re-streameado**, no por suposición del
  cliente — así reflejan exactamente lo que hizo el servidor (ver §6.3).
- El "preview de selección" (rojo antes de aplicar) es puramente visual y se limpia si el usuario
  cambia de opción o cancela; no muta el plan hasta `Aplicar`.
- Todo string nuevo (verbos del log, tooltips, estados) va al catálogo `lang/en` + `i18n.js`.

---

## Bugs corregidos

- [x] **`Script error for "Category"`** al crear el curso. Causa: `FormAutocomplete.enhance(selector, tags, ajax, placeholder, ...)` recibía el LABEL `"Category"` en la posición de `ajax` (que es un **nombre de módulo AMD**), así que RequireJS intentaba `require(['Category'])`. Fix en `actions/course-create.js`: alinear los argumentos (`ajax = false`, el label pasa a `placeholder`, + `caseSensitive`/`showSuggestions`). Verificado: ya no hay error JS y el selector de categoría se realza correctamente.
### Defectos de §15/§16 detectados en uso y corregidos

- [x] **El botón Detener no se podía pulsar.** Durante el streaming, `setCompactChatState('disabled')` añadía `.compact-chat-card--disabled` (legacy) con `pointer-events:none` sobre TODA la tarjeta del chat, y el botón Detener vive dentro. Fix: dejar de aplicar esa clase global (cada control ya se deshabilita individualmente) en `planning/compact-chat.js`; el botón queda usable.
- [x] **Al recargar se descomponía el plan** (secciones sin actividades "0/N", sección fantasma "Section 4:"). Causa: `hydrateDetailedPlanFromSnapshot` reproducía eventos por índice (`section_index`/`activity_index`) pero el render engancha por **id** (UUID), y el snapshot traía una sección sin nombre. Fix: el hidratador usa ahora el reconciliador en vivo `reconcilePlan(plan_crudo_con_ids)` (`hydrate-plan.js` + `resume-snapshot.js`), y el servicio filtra secciones borradas/sin nombre (ver `TODO-V2.md` §C). **Verificado: nombres iguales, sin fantasma, actividades+detalle restaurados.**
- [x] **Al recargar se perdía el log de decisiones.** `localStorage` NO sobrevive al reload en el contexto popup de Moodle (verificado). Fix: reconstruir el log desde el snapshot (server-side, que sí persiste) — una entrada "AI planned section «X»" por sección + las instrucciones del usuario (`resume-snapshot.js`). **Verificado: el log se restaura tras recargar.**

---

## Pulido UI estilo Claude web (pedido en campo) — HECHO

> Verificado e2e (puppeteer): #1 panel izq = 0.46 del ancho; #3 badges = 1 color neutro; #4 al seleccionar opción destructiva se resaltan los elementos afectados en el centro (anillo) y se limpian al cambiar/aplicar; #5 textarea solo visible con "Something else"; #2 chat redondeado estilo Claude. Sin errores JS.

1. **Panel izquierdo más ancho por defecto** (~mitad de la pantalla). Era `--cg-left-w: 440px` (clamp max 620px). Subir a ~46vw con clamp mayor para que por defecto ocupe casi la mitad; el splitter sigue permitiendo redimensionar.
2. **Caja de chat estilo Claude.** Rediseñar `.compact-chat-card`: contenedor redondeado único, textarea limpia arriba, barra de herramientas discreta abajo (iconos a la izquierda, botón enviar redondeado a la derecha), sin el look "fatal" actual.
3. **Menos colores (no "payaso").** Neutralizar los ~11 badges de tipo de actividad (`ps-badge--quiz/book/assign/forum/lesson/url/resource/page/data/glossary`) a un estilo gris uniforme, y unificar botones a UN color de acento (rojo solo para destructivo). Calmar la paleta tipo Claude (grises + un acento).
4. **Resaltar elementos afectados al seleccionar una propuesta.** Cuando el usuario marca una opción que afecta secciones/actividades (`proposal.intent.target_ids`), resaltar esos elementos en el centro (`[data-section-id]`/`[data-activity-id]`) SOLO mientras esa opción está seleccionada (clase `.cg-affected`, variante destructiva). Limpiar al cambiar/aplicar/descartar.
5. **Textarea "Something else" solo cuando está activa.** Hoy el textarea queda visible aunque esté seleccionada otra opción. Mostrarlo solo cuando el radio "Something else" está marcado (handler a nivel de grupo).

---

## Pendientes UI — fidelidad Custom Sections + hover (2ª ronda de campo) — POR IMPLEMENTAR

> Pedido en campo con capturas. El preview central NO debe verse como tarjetas con bordes
> permanentes ni con puntos de arrastre visibles siempre; debe imitar el **formato Custom Sections**
> de Moodle 4.5, donde las afordancias aparecen SOLO en hover. Todos estos puntos están PENDIENTES.

### P1. Quitar el remarcado actual (`.cg-affected`) — se ve asqueroso
- Hoy, al seleccionar una propuesta, se dibuja un **anillo rojo grueso** (`box-shadow: 0 0 0 2px … , 0 0 0 7px …`)
  tanto en los ítems del **checklist izquierdo** (queda como un óvalo rojo alrededor del texto, feo)
  como en las **secciones/actividades del centro** (anillo rojo que rompe el layout).
- **Qué hacer:**
  - El resaltado de "esto se verá afectado" debe ser **sutil y limpio**, NO un anillo rojo grueso.
    Propuesta: fondo tenue + borde-izquierdo fino (p.ej. `background: hsl(8 72% 42% / .06)` + `border-left: 3px solid` del color de acento destructivo, o un contorno `outline: 1px` discreto), con transición suave.
  - **NO** aplicar el resaltado a los ítems del **checklist izquierdo** — el highlight de afectados es
    para la **vista central** únicamente (el checklist no debe deformarse). Restringir el selector a
    `.prv-section-row`/`.dp-activity-wrap` del centro (excluir `.courseai-checklist-item`).
  - Mantener la lógica de "solo mientras la opción está seleccionada" (ya implementada en
    `ui-proposals.js onSelectionChange`); solo cambia el ESTILO (`.cg-affected` en
    `aicoursecreation.css`).

### P2. Vista central = formato **Custom Sections** (no tarjetas con borde)
Rehacer el look de la vista central para que imite el formato Custom Sections de Moodle 4.5:
- **Sección**: cabecera con **chevron de colapsar/expandir** (círculo azul claro), nombre en negrita
  con **lápiz de edición** al lado, acciones a la derecha ("Collapse all/Expand all" y menú de 3
  puntos `⋮`). La sección puede **colapsar** ocultando sus actividades.
- **Actividades**: **filas** (no tarjetas con borde permanente) **separadas por líneas punteadas**
  horizontales (`border-top: 1px dashed var(--border)` entre ítems). Cada fila: icono del módulo a la
  izquierda (con su color real de Moodle por tipo/purpose — OJO: esto reintroduce color pero es el
  look Moodle; decidir si en el preview se mantiene neutro o se usa el color real), **título como
  enlace** + lápiz de edición + menú `⋮` a la derecha; debajo, la descripción/plan.
- Quitar las "tarjetas" con `border` + `border-radius` + `box-shadow` que se usan hoy en
  `.prv-activity-item`/`.dp-activity-wrap`; reemplazar por filas con separador punteado.

### P3. Hover en actividad: borde SOLO en hover, y arrastre sin los puntos `::` permanentes
- Hoy cada actividad muestra **siempre** un grip de arrastre visible (los 6 puntos `::`,
  `.dp-drag-handle`) — el usuario lo califica de "señalización horrible".
- **Qué hacer:**
  - **Ocultar el grip `::` por defecto**; el ítem se puede **arrastrar directamente** (toda la fila o
    desde el handle) **sin** mostrar los puntos permanentemente.
  - Al **pasar el cursor por encima de una actividad**, recién ahí mostrar una **afordancia sutil**:
    un borde/realce ligero en la fila (como Moodle) y, si se quiere, el handle de arrastre **aparece
    solo en hover** (`opacity 0 → 1`). Nada de bordes ni puntos permanentes.
  - Mantener el drag-and-drop funcional (ya existe `wireDragAndDrop` por `.dp-activity-wrap`), solo
    cambia la **visibilidad** del handle (CSS: `.dp-drag-handle{opacity:0} .dp-activity-wrap:hover .dp-drag-handle{opacity:1}` o arrastre por toda la fila).

### P4. Botón "Add section" estilo Moodle
- Referencia: en Moodle el "Add section" es un **botón ancho con borde punteado redondeado**, texto
  centrado azul "+ Add section" (y la última sección colapsada se ve como una tarjeta con chevron).
- Hoy el "+ Add section" se ve como un enlace/botón rojo desalineado. **Rehacerlo** como bloque
  punteado redondeado a todo el ancho, con el "+" y el texto centrados, hover sutil. Mismo criterio
  para "+ Add activity" (ya es punteado, alinear al estilo Moodle).

### P5. "Add activity" inline al pasar el cursor ENTRE dos actividades
- Referencia (captura): en Moodle, al pasar el cursor **en el espacio entre dos actividades**, aparece
  una **línea azul punteada** con dos botones centrados: **"+"** (añadir actividad/recurso) y un botón
  azul **"✦ Add activity or resource with AI"** (con tooltip).
- **Qué hacer:** añadir una **drop/insert zone entre actividades** que está oculta por defecto y
  aparece en hover (línea punteada azul + botones centrados). El "+" abriría el flujo de añadir
  actividad en esa posición; el botón IA dispararía `add_activity` con contexto de posición. Hoy solo
  existe "+ Add activity" al final de la sección — falta la inserción **entre** ítems.

### Notas de implementación
- Estos cambios son CSS + algo de DOM/JS en `detailed/section-row.js`, `detailed/activity-row.js`,
  `detailed/activity-dom.js` y `styles/aicoursecreation.css`.
- Reusar clases CSS de `core_courseformat` donde se pueda para heredar el look del tema (Boost), o
  replicar exactamente: chevron, separadores punteados, hover-insert, botón punteado de sección.
- Verificar con captura/e2e que en hover aparecen las afordancias y que sin hover la vista queda
  **limpia** (sin puntos `::` ni bordes permanentes).
