# Borrador del capítulo de resultados (v5 — corpus real)

**Clasificador de solicitudes ciudadanas en español con generación automática de tickets en CDMX**
Estado al 18 de septiembre de 2026 · Corpus v3 (1,446 ejemplos, mayoritariamente tweets reales; 413 etiquetados a mano) · BETO adoptado como modelo del prototipo · Pendiente: Kappa de Cohen entre anotadores

---

## 1. Construcción del corpus (E1)

El corpus evolucionó en tres versiones que en sí mismas documentan la metodología:

| Versión | Fuente dominante | Ejemplos útiles | Naturaleza |
|---|---|---|---|
| v1 | 3 Excel originales | 188 | Plantillas sintéticas (8,100 filas → 383 plantillas reales) |
| v2 | + tweets raw (CFE/Segiagua/SOBSE) | 314 | Mixto; 1,506 mensajes institucionales filtrados |
| **v3** | **+ "Corpus final real.csv"** | **1,446** | **Tweets reales curados por el equipo; 413 etiquetas manuales** |

Distribución v3: alumbrado_publico 417 (28.8%), baches_y_pavimento 399 (27.6%), fuga_de_agua 292 (20.2%), seguridad 267 (18.5%), recoleccion_basura 71 (4.9%). Partición por grupos de plantilla sin fuga de información: train 1,012 / val 217 / test 217. Además, 991 textos fuera de alcance alimentan el detector auxiliar.

El pipeline (`08` → `01`) es reproducible: deduplicación exacta y por plantilla (dígitos colapsados, máximo 3 ejemplares), filtrado de mensajes institucionales por reglas léxicas, corrección de codificación y de etiquetas (p. ej. el typo `recrecoleccion_basura`), y pre-etiquetado que respeta las etiquetas manuales del equipo y marca el método de cada registro para su revisión.

La auditoría del SUAC/0311 (ficha en `fuentes/`) mostró que el dataset público no incluye texto libre; se usó como catálogo oficial y evidencia de pertinencia (las 5 categorías cubren ~53% del volumen real de 2020; el propio 0311 opera un clasificador de IA en producción).

## 2. Comparación de modelos y decisión (E2, E3 / HU-05–HU-07)

Ambos modelos se entrenaron y evaluaron con las mismas particiones del corpus v3; BETO con fine-tuning en Colab (GPU), pérdida ponderada por clase y calibración por temperatura; el baseline con TF-IDF (1–2 gramas), regresión logística balanceada y calibración sigmoide. Cruce formal fila por fila sobre el test (n=217):

| Métrica | TF-IDF + LogReg | **BETO** |
|---|---|---|
| Accuracy | 0.806 | **0.912** |
| **F1 macro** | 0.778 | **0.912** ✓ supera el criterio de 0.80 |
| Cobertura automática (umbral 0.60) | 73.3% | 94.5% |
| F1 macro en emitidos | 0.863 | 0.938 |
| Errores totales / emitidos sin revisión | 42 / 16 | **19 / 15** |

F1 por clase de BETO: alumbrado 0.952, fuga_de_agua 0.923, recoleccion_basura 0.917, seguridad 0.895, baches 0.874 — las cinco clases por encima de 0.87, incluida basura (la minoritaria), donde el baseline se quedó en 0.625.

**Decisión (criterio 4.6): se adopta BETO como modelo del prototipo.** La mejora es consistente en todas las clases (+0.134 de F1 macro global), y con mayor cobertura BETO comete menos errores absolutos emitidos (15) que el baseline (16) cubriendo 21 puntos más de solicitudes. El baseline se conserva como referencia reproducible sin GPU.

**Evolución de la comparación a lo largo del proyecto** (hallazgo metodológico central):

| Corpus (train) | F1 baseline | F1 BETO | Ganador |
|---|---|---|---|
| v1 — 130 plantillas | 0.585 | 0.556 | Baseline |
| v2 — 218 mixto | 0.732 | 0.916* | BETO |
| v3 — 1,012 reales | 0.778 | **0.912** | **BETO** |

*El 0.916 de v2 provino de un test pequeño (n=48); el 0.912 de v3 (n=217) es la cifra robusta. La lección para la memoria: con datos escasos el Transformer no justificaba su costo; el valor de la representación contextual apareció al alcanzar masa crítica de datos reales — exactamente la hipótesis experimental planteada en la sección 3.3.4, resuelta con evidencia y no por suposición.

## 3. Calibración y abstención (E5 / HU-09, HU-10)

Con validación de 217 casos la calibración del baseline mejoró a ECE 0.070 (era 0.224 con 28 casos). Con texto 100% real, la abstención en 0.60 ya no atrapa todos los errores (fenómeno esperado al salir de las plantillas): el baseline emite 16 errores y BETO 15, con coberturas de 73.3% y 94.5% respectivamente. El intercambio cobertura-riesgo debe fijarse como decisión operativa: subir el umbral de BETO reduce errores automáticos a costa de más revisión humana; la curva completa puede trazarse desde `resultados/predicciones_test_beto.csv`. El detector de fuera de alcance (991 ejemplos, accuracy 0.79 macro en su validación de 6 clases) sigue como alerta que fuerza revisión.

## 4. Prototipo: tickets, API e interfaz (E4 / HU-08)

Flujo completo texto → clasificación calibrada → alerta fuera de alcance → abstención → ticket (Tabla 3), disponible como script de demo, **API REST FastAPI** (`/clasificar`, `/salud`, Swagger en `/docs`) e **interfaz web** conforme a los wireframes. Tiempo técnico de generación: ~1.2 ms de mediana (p95 1.4 ms) con el baseline en una máquina sin GPU. Para servir BETO en producción la inferencia en CPU es viable (decenas de ms por solicitud); el modelo entrenado se conserva descargando `beto_cdmx_final.zip` de Colab.

## 5. Análisis de errores (E6 / HU-12)

Detalle en `resultados/analisis_errores.txt`. Los errores de ambos modelos se concentran en fronteras semánticas reales del lenguaje ciudadano: encharcamientos que dañan pavimento (fuga vs. baches), oscuridad e inseguridad (alumbrado vs. seguridad), y escombro/tiradero (basura vs. baches). En el baseline la confianza media en errores (0.57) es claramente menor que en aciertos, señal de calibración útil. Estos casos límite coinciden con los documentados en la guía de anotación, lo que valida su diseño.

## 6. Conclusiones y cierre

1. **El criterio de éxito se cumple:** F1 macro 0.912 ≥ 0.80 sobre un conjunto de prueba independiente de 217 solicitudes reales, con F1 ≥ 0.87 en las cinco categorías.
2. **La decisión de modelo se tomó con el procedimiento previsto** (criterio 4.6) y cambió con la evidencia: baseline con datos escasos, BETO con el corpus real — la comparación cumplió su propósito Lean.
3. El MVP completo (clasificar + calibrar + abstenerse + ticket + API + interfaz) es funcional y reproducible; el pipeline de datos es re-ejecutable de extremo a extremo.
4. Limitaciones: recolección_basura sigue subrepresentada (71); parte del etiquetado es automático por reglas; el corpus proviene de X y de tres dependencias, no de todos los canales.
5. **Único pendiente metodológico: el acuerdo entre anotadores.** Llenar `corpus/muestra_control_anotacion.csv` (dos personas, guía en `GUIA_ANOTACION.md`) y correr `04_kappa_anotacion.py` para reportar Kappa de Cohen. Con ese dato, este capítulo está completo para integrarse a la memoria.

---

*Reproducibilidad: `08` → `01` → `02` → `02b` → (Colab `03_beto_colab.ipynb`) → `03` → `05` → `06`; semilla fija 42; cifras en `resultados/`.*
