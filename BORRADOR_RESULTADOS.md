# Capítulo de resultados (v6 — corpus final)

**Clasificador de solicitudes ciudadanas en español con generación automática de tickets en CDMX**
UNIR — Maestría en Inteligencia Artificial · 18 de septiembre de 2026
Corpus final: 1,884 solicitudes reales · Modelo adoptado: BETO (F1 macro 0.919) · Pendiente: Kappa de Cohen entre anotadores

---

## 1. Construcción del corpus (E1)

El corpus se construyó en cuatro iteraciones que documentan por sí mismas la metodología del proyecto:

| Versión | Fuente incorporada | Ejemplos útiles | Naturaleza |
|---|---|---|---|
| v1 | 3 Excel originales | 188 | Plantillas sintéticas (8,100 filas → 383 plantillas reales) |
| v2 | + tweets raw (CFE, Segiagua, SOBSE) | 314 | Mixto; 1,506 mensajes institucionales filtrados |
| v3 | + "Corpus final real.csv" | 1,446 | Tweets reales curados por el equipo |
| **v4** | **+ "Corpus final v2.csv" (Locatel, Puebla)** | **1,884** | **Corpus final: reportes ciudadanos reales** |

Distribución final: baches_y_pavimento 516 (27.4%), alumbrado_publico 417 (22.1%), fuga_de_agua 382 (20.3%), seguridad 298 (15.8%), recoleccion_basura 271 (14.4%). El desbalance severo de las primeras versiones (basura con 6 ejemplos) quedó resuelto: ninguna clase baja de 14% y la razón entre la mayor y la menor es 1.9:1. Partición por grupos de plantilla sin fuga de información: **train 1,320 / val 282 / test 282**. Otros 991 textos fuera de alcance alimentan el detector auxiliar.

Se verificó que "Corpus final v2.csv" contiene íntegramente al anterior (1,275 de 1,275 textos), por lo que el pipeline toma solo el archivo más reciente y no duplica registros. El etiquetado combina 410 etiquetas manuales del equipo con reglas automáticas (estrictas y estándar), y cada registro conserva el método con que fue etiquetado para su revisión.

El pipeline completo (`08` → `01`) es reproducible con semilla fija: deduplicación exacta y por plantilla (dígitos colapsados, máximo 3 ejemplares por plantilla), filtrado léxico de mensajes institucionales, corrección de codificación y de etiquetas inconsistentes, y partición por grupos que impide repartir variantes casi idénticas entre conjuntos.

La auditoría de la fuente SUAC/0311 (ficha en `fuentes/`) determinó que el dataset público no publica el texto libre de las solicitudes; se incorporó como catálogo oficial de categorías y evidencia de pertinencia — las cinco categorías del prototipo cubren ~53% del volumen real de solicitudes de 2020, y el propio Sistema 0311 opera un clasificador automático en producción.

## 2. Comparación de modelos y decisión (E2, E3 / HU-05 a HU-07)

Ambos modelos se entrenaron y evaluaron sobre las mismas particiones. Baseline: TF-IDF (1–2 gramas, `class_weight` balanceado) + regresión logística con calibración sigmoide. BETO: fine-tuning de `dccuchile/bert-base-spanish-wwm-cased` en Colab (GPU) con pérdida ponderada por clase y calibración por temperatura. Cruce formal fila por fila sobre el conjunto de prueba (n=282):

| Métrica | TF-IDF + LogReg | **BETO** |
|---|---|---|
| Accuracy | 0.872 | **0.915** |
| **F1 macro** | 0.872 ✓ | **0.919** ✓ |
| Cobertura automática (umbral 0.60) | 84.4% | 99.3% |
| F1 macro en emitidos | 0.922 | 0.921 |
| Errores totales | 36 | **24** |

F1 por clase de BETO: recoleccion_basura 0.938, fuga_de_agua 0.931, seguridad 0.925, alumbrado 0.917, baches 0.883 — **las cinco categorías por encima de 0.88**, incluida la que era minoritaria. El baseline, con el corpus final, también supera el criterio (0.872) y mantiene todas sus clases sobre 0.82.

**Decisión (criterio 4.6): se adopta BETO como modelo del prototipo.** Mejora el F1 macro en +0.047, reduce los errores absolutos de 36 a 24 (un tercio menos) y su ventaja es homogénea entre clases. El baseline se conserva como referencia reproducible y alternativa de despliegue sin GPU, con un desempeño que hoy también satisface el criterio de éxito.

**Evolución de la comparación a lo largo del proyecto** (hallazgo metodológico central):

| Corpus (train) | F1 baseline | F1 BETO | Ganador |
|---|---|---|---|
| v1 — 130 plantillas sintéticas | 0.585 | 0.556 | Baseline |
| v3 — 1,012 reales | 0.778 | 0.912 | BETO |
| **v4 — 1,320 reales (final)** | **0.872** | **0.919** | **BETO** |

La lectura para la memoria: con datos escasos y sintéticos el Transformer no justificaba su costo; al alcanzar masa crítica de texto ciudadano real, la representación contextual se impuso. Esto resuelve con evidencia la hipótesis experimental de la sección 3.3.4 y confirma que el cuello de botella nunca fue el algoritmo sino el corpus — nótese que el baseline ganó 0.287 puntos de F1 macro sin cambiar una sola línea de su configuración, solo con mejores datos.

## 3. Calibración y abstención (E5 / HU-09, HU-10)

Con 282 casos de validación, el ECE del baseline se estabilizó en 0.086 (era 0.224 con 28 casos). BETO está mejor calibrado en su acierto, pero es más confiado: la confianza media en sus errores es 0.833 frente a 0.927 en sus aciertos, de modo que el umbral de 0.60 — heredado del corpus pequeño — deja pasar prácticamente todo (99.3% de cobertura, 23 errores emitidos). El umbral debe recalibrarse con el corpus final. Curva cobertura-riesgo de BETO sobre el test:

| Umbral | Cobertura automática | Errores emitidos | Acierto en lo emitido |
|---|---|---|---|
| 0.60 | 99.3% | 23 | 0.918 |
| 0.80 | 93.6% | 16 | 0.939 |
| **0.90** | **86.5%** | **9** | **0.963** |
| 0.94 | 67.4% | 2 | 0.989 |

**Punto de operación recomendado: 0.90** — mantiene 86.5% de canalización automática reduciendo los errores emitidos de 23 a 9, con 96.3% de acierto en lo que se emite sin revisión. La decisión final del umbral es operativa, no técnica: depende de cuánta revisión humana esté dispuesta a absorber la institución, y este análisis le entrega la curva para decidirlo con datos.

El detector auxiliar de fuera de alcance (entrenado con los 991 textos excluidos) continúa operando como alerta: no altera la categoría del ticket, pero fuerza revisión humana cuando detecta un texto ajeno a las cinco categorías.

## 4. Prototipo: tickets, API e interfaz (E4 / HU-08)

El flujo completo —texto libre → clasificación calibrada → alerta de fuera de alcance → regla de abstención → ticket estructurado conforme a la Tabla 3— está disponible en tres formas: script de demostración (`05_demo_tickets.py`), **servicio REST con FastAPI** (`07_api.py`: endpoints `/clasificar` y `/salud`, documentación Swagger en `/docs`) e **interfaz web** de captura y resultado conforme a los wireframes de la Figura 2. Tiempo técnico de generación del ticket: **1.3 ms de mediana (p95 1.4 ms)** con el baseline en una máquina sin GPU; esta cifra mide el prototipo y no debe confundirse con tiempos de atención institucional. Para servir BETO, la inferencia en CPU es viable en el orden de decenas de milisegundos por solicitud.

## 5. Análisis de errores (E6 / HU-12)

Detalle completo en `resultados/analisis_errores.txt`. Los 24 errores de BETO se concentran en fronteras semánticas genuinas del lenguaje ciudadano, no en fallos arbitrarios: socavones producidos por fugas de agua (fuga vs. bache), semáforos apagados reportados como falta de luz (alumbrado vs. seguridad vial), escombro de obra acumulado en banquetas (basura vs. pavimento) y mensajes que encadenan varias quejas en un mismo texto. Son exactamente los casos límite que la guía de anotación documenta, lo que valida su diseño y señala el trabajo pendiente: refinar las definiciones de frontera antes que aumentar la complejidad del modelo.

## 6. Conclusiones

1. **El criterio de éxito se cumple con holgura:** F1 macro de 0.919 sobre un conjunto de prueba independiente de 282 solicitudes ciudadanas reales, con las cinco categorías por encima de 0.88. El baseline alcanza 0.872, de modo que el proyecto dispone de dos modelos válidos con perfiles de costo distintos.
2. **La decisión de modelo se tomó con el procedimiento previsto y cambió con la evidencia:** el criterio 4.6 favoreció al baseline con datos escasos y a BETO con el corpus real. La comparación cumplió su propósito Lean de no pagar complejidad sin justificación medida.
3. **El MVP completo es funcional y reproducible:** clasificación, calibración, abstención, generación de tickets, API e interfaz, con el pipeline de datos re-ejecutable de extremo a extremo desde las fuentes crudas.
4. **Limitaciones a declarar:** el corpus proviene de X (Twitter) y de un conjunto acotado de dependencias de CDMX y Puebla, no de todos los canales de atención; parte del etiquetado es automático por reglas; y el umbral de abstención requiere una decisión operativa institucional que excede el alcance del prototipo.
5. **Pendiente metodológico único:** el acuerdo entre anotadores. Completar `corpus/muestra_control_anotacion.csv` (dos anotadores, siguiendo `GUIA_ANOTACION.md`) y ejecutar `04_kappa_anotacion.py` para reportar el Kappa de Cohen. Con ese dato, el capítulo queda completo para integrarse a la memoria.

---

*Reproducibilidad: `08` → `01` → `02` → `02b` → (Colab `03_beto_colab.ipynb`) → `03` → `05` → `06`; semilla fija 42; todas las cifras provienen de archivos en `resultados/`.*
