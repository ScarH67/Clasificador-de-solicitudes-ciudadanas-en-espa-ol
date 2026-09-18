# Guía de anotación — Clasificador de solicitudes ciudadanas CDMX

*Entregable de la HU-02 (Épica E1, Corpus y etiquetado). Versión 1.0 — 29/08/2026.*

## Propósito y reglas generales

Esta guía define cómo asignar UNA sola categoría a cada solicitud ciudadana.
Los dos anotadores trabajan por separado sobre `corpus/muestra_control_anotacion.csv`,
cada uno en su columna, sin consultarse. Al terminar se ejecuta
`python3 04_kappa_anotacion.py` (criterio de aceptación: Kappa de Cohen >= 0.70).

Reglas generales:

1. Se anota la **necesidad principal** que expresa el texto, no la dependencia a
   la que va dirigido ni la mención (@SacmexCDMX no obliga a "fuga_de_agua").
2. Si el texto expresa dos problemas, se anota el que motiva la solicitud (el
   primero o el más desarrollado). Si es imposible decidir, `fuera_de_alcance` NO
   es la salida: se elige la mejor opción y se marca la duda en una nota.
3. La ortografía informal, abreviaturas o emojis no cambian la categoría.
4. `fuera_de_alcance` se usa solo cuando la necesidad principal no corresponde a
   ninguna de las cinco categorías (transporte, trámites, ruido, árboles, etc.).

## Definiciones y ejemplos

**baches_y_pavimento** — daños o mantenimiento del arroyo vehicular o peatonal:
baches, hundimientos, socavones, pavimentación, empedrado, banquetas rotas.
Ejemplos: "bache enorme en Av. Insurgentes", "solicito pavimentación de mi calle",
"la banqueta está destrozada". *Caso límite:* "mal estado de la vía pública" sin
más detalle -> baches_y_pavimento; un puente peatonal dañado -> fuera_de_alcance
(infraestructura, no pavimento).

**alumbrado_publico** — luminarias, lámparas o postes de luz de la vía pública
apagados, dañados o insuficientes. Ejemplos: "llevamos semanas sin luz en la
calle", "luminaria parpadeando". *Caso límite:* falta de luz DENTRO de una
vivienda (CFE) -> fuera_de_alcance; "está muy oscuro y asaltan" -> decidir por la
necesidad principal: si pide reparar luminarias es alumbrado_publico, si pide
patrullaje es seguridad.

**fuga_de_agua** — agua potable y drenaje: fugas, falta de agua, baja presión,
tandeo, coladeras/alcantarillas dañadas, desazolve, encharcamientos e
inundaciones por drenaje. Ejemplos: "fuga en la esquina desde hace 3 días",
"no llega agua a la colonia", "coladera sin tapa". *Caso límite:* inundación por
lluvia sin mención de drenaje -> fuga_de_agua igualmente (red hidráulica);
calidad del agua (sale sucia) -> fuga_de_agua.

**recoleccion_basura** — recolección y limpia: el camión no pasa, tiraderos
clandestinos, cascajo/escombro/ramas abandonados, calles sucias. Ejemplos: "el
camión no pasa desde el lunes", "tiradero en el baldío". *Caso límite:* retiro
de un árbol caído -> fuera_de_alcance (poda), pero "ramas acumuladas sin
recoger" -> recoleccion_basura.

**seguridad** — prevención y vigilancia: robos, asaltos, solicitud de
patrullaje, venta de drogas/alcohol, violencia, cámaras de vigilancia y botones
de auxilio (C5), alarmas vecinales. Ejemplos: "aumentaron los asaltos, pedimos
rondines", "la cámara del poste 4540 no funciona". *Caso límite:* un choque o
percance vial -> fuera_de_alcance (tránsito); un semáforo descompuesto ->
fuera_de_alcance (movilidad), aunque cause riesgo.

**fuera_de_alcance** — todo lo demás: transporte público, semáforos y
balizamiento, poda de árboles, ruido y ambiente, comercio ambulante, trámites e
información, quejas contra funcionarios, protección civil, alerta sísmica.

## Registro de decisiones

Las dudas y los criterios nuevos que surjan durante la anotación se apuntan al
final de este archivo (sección "Bitácora"), se discuten al calcular el Kappa y,
si cambian una definición, la versión de la guía sube a 1.1.

## Bitácora

- (pendiente)
