# Plan de implementación y conclusión del proyecto

**Clasificador de solicitudes ciudadanas en español con generación automática de tickets en CDMX**
UNIR — Maestría en Inteligencia Artificial · Fecha del plan: 29 de agosto de 2026
Ventana de desarrollo: hasta el 30 de septiembre de 2026 (Sprints 1–3 del documento)

---

## 1. Diagnóstico de los archivos disponibles

Se revisaron los cuatro archivos de la carpeta `seminario2`. El documento del proyecto ("Proyecto v3 desde WhatsApp (corregido).docx") define con claridad el alcance: clasificación multiclase en cinco categorías (baches y pavimento, alumbrado público, fuga de agua, recolección de basura y seguridad), comparación TF-IDF + regresión logística vs. BETO, calibración con abstención y generación de un ticket estructurado, con F1 macro ≥ 0.80 como criterio de éxito.

El análisis de los tres Excel arroja hallazgos que condicionan el plan:

1. **Duplicación masiva.** De 8,100 filas totales, solo 868 textos son únicos, y al colapsar variantes de plantilla (mismo texto con distinto número de poste, calle o fecha) quedan **383 plantillas reales**. "Quejas CDMX 2021-2022" son 75 plantillas repetidas 4,000 veces; casi 500 registros de "Quejas Twitter" son 16 plantillas de C5 con distinto identificador.
2. **Las etiquetas no son las categorías del proyecto.** Los datos traen la *entidad destinataria* (@SacmexCDMX, @MetroCDMX, @C5_CDMX…), no las cinco categorías de servicio. Se requiere un mapeo entidad + palabras clave, validado luego por anotadores (Anexo C del documento).
3. **Cobertura parcial del alcance.** Buena parte de los textos (Metro, SEMOVI, PAOT, trámites) queda fuera de las cinco categorías: tras deduplicar y mapear, quedan **188 ejemplos útiles**, con fuerte desbalance (seguridad 44 %, fuga de agua 40 %, baches 7 %, alumbrado 5 %, basura 3 %).
4. **Problemas de codificación.** "Quejas CDMX 2021-2022" tiene mojibake (Ã­, Ã±) que ya se corrige automáticamente en el pipeline.

**Consecuencia:** la meta de ~2,000 registros no se alcanza con los archivos actuales. La prioridad número uno del plan es ampliar el corpus (o documentar la limitación conforme a la sección 4.7 del documento, que ya lo prevé: "el tamaño de 2,000 reportes es una meta de construcción y no una garantía").

## 2. Estrategia de entorno: qué se ejecuta dónde

**En la máquina local** (verificado: Python 3.10, pandas, scikit-learn 1.7 y matplotlib disponibles; ~4 GB de RAM, sin GPU): preparación del corpus, baseline TF-IDF + regresión logística, calibración, abstención, generación de tickets y toda la evaluación. Todo esto ya corre localmente con los scripts entregados.

**En Google Colab** (GPU T4 gratuita): únicamente el fine-tuning de BETO, que en CPU con 4 GB sería impracticable. El notebook `03_beto_colab.ipynb` está listo: se sube el corpus, se entrena (~10–20 min con GPU), y se descarga `predicciones_test_beto.csv` para comparar contra el baseline en local.

Esta división respeta el principio Lean del documento: el MVP completo (clasificar + ticket) vive en local con el baseline; BETO es la hipótesis experimental que se valida aparte.

## 3. Qué queda ya implementado con este plan

| Archivo | Propósito | Dónde corre |
|---|---|---|
| `01_preparar_corpus.py` | Unifica las 3 fuentes, corrige codificación, deduplica (exactos + plantillas, máx. 3 por plantilla), mapea entidad+keywords → 5 categorías, particiona train/val/test **por grupos de plantilla** para evitar fuga de información | Local |
| `02_baseline_tfidf.py` | Baseline TF-IDF + LogReg con `class_weight=balanced`, calibración sigmoide, abstención (umbral 0.60), métricas completas, matriz de confusión y ticket JSON de ejemplo | Local |
| `03_beto_colab.ipynb` | Fine-tuning de BETO con pérdida ponderada por clase, calibración por temperatura, mismas métricas y exportación de predicciones | Colab (GPU) |
| `03_comparar_modelos.py` | Tabla comparativa baseline vs. BETO y aplicación del criterio de decisión de la sección 4.6 | Local |
| `corpus/` y `resultados/` | Corpus particionado, reporte de construcción, métricas del baseline, predicciones, modelo serializado y ticket de ejemplo | Generados |

**Resultados preliminares del baseline** (corpus actual de 188 ejemplos): accuracy 0.83, **F1 macro 0.585** (por debajo del criterio de 0.80), ECE 0.224, cobertura automática 67 % y F1 macro de 1.00 sobre los casos emitidos sin revisión. La lectura es exactamente la prevista en la sección 4.6 del documento: antes de tocar los modelos hay que mejorar el corpus — las clases minoritarias (basura: 6 ejemplos; alumbrado: 10) no tienen datos suficientes para aprenderse ni evaluarse.

## 4. Plan por fases (alineado al calendario de Sprints)

### Fase 1 — Cerrar el corpus (ahora – 8 sep, cierre del Sprint 1)

La actividad crítica. Tres líneas de acción, en orden de preferencia:

1. **Ampliar fuentes reales:** descargar del Portal de Datos Abiertos CDMX los reportes del SUAC (traen texto libre y categoría temática real) y filtrar las cinco categorías. Con esto la meta de ~2,000 es alcanzable y las clases minoritarias dejan de serlo. Verificar el diccionario de datos antes de incorporar (ficha por fuente, sección 3.5).
2. **Validar el pre-etiquetado:** el mapeo por reglas de `01_preparar_corpus.py` es un *pre*-etiquetado. Dos personas del equipo revisan una muestra de control (≥60 casos, sobremuestreando minoritarias) siguiendo la guía de anotación y se calcula Kappa de Cohen (HU-02, Anexo C). El script deja `metodo_etiqueta` en cada registro para priorizar la revisión de los casos `prior_entidad`.
3. **Si no se logra ampliar:** documentar la limitación (sección 4.7), reportar resultados con intervalos de cautela y considerar fusionar o marcar como "datos insuficientes" las categorías con <30 ejemplos, sin sobremuestrear para ocultar la ausencia estructural de datos (el documento lo prohíbe explícitamente).

Entregable de fase: `corpus/` regenerado + acta breve de anotación con Kappa.

### Fase 2 — Reentrenar baseline y ejecutar BETO (9 – 22 sep, Sprint 2)

Con el corpus cerrado: volver a correr `01` y `02` en local (minutos), subir los CSV a Colab y ejecutar `03_beto_colab.ipynb` (HU-06). Ajustar hiperparámetros solo con validación, nunca con prueba. Descargar las predicciones y correr `03_comparar_modelos.py` (HU-07). Revisar calibración: si el ECE sigue alto, probar calibración isotónica (baseline) o ajustar la temperatura (BETO) y re-elegir el umbral de abstención sobre validación analizando el intercambio cobertura/riesgo (HU-09, HU-10).

Entregable de fase: `comparacion_final.txt` con la decisión de modelo justificada.

### Fase 3 — Ticket, integración y evaluación final (23 – 30 sep, Sprint 3)

El generador de tickets ya funciona con el baseline (`ticket_ejemplo.json`, estructura de la Tabla 3: folio, texto, categoría, confianza, estado de revisión, fecha). Restan: conectar el modelo ganador de la Fase 2 al generador; medir el tiempo técnico de generación (recepción → ticket, métrica de 4.5); ejecutar el análisis de errores sobre `predicciones_test.csv` (¿en qué clases y con qué confianza se equivoca?); y, si el tiempo alcanza (Could Have), una interfaz mínima de captura (p. ej. Streamlit o una página local) que reproduzca los wireframes de la Figura 2 — el MVP no depende de ella.

Entregable de fase: prototipo integrado + métricas finales.

### Fase 4 — Conclusión y memoria (última semana de sep)

Redactar los resultados en el documento: tabla comparativa de modelos, matriz de confusión, curvas/ECE, cobertura de abstención, Kappa de anotación y análisis de errores. Responder explícitamente los criterios de éxito (¿F1 macro ≥ 0.80? ¿BETO justifica su costo?) y aplicar el criterio de decisión 4.6 tal como está escrito — incluida la posibilidad, perfectamente válida, de concluir que el baseline es el modelo adecuado. Cerrar con limitaciones (tamaño y origen sintético de parte del corpus, cinco categorías, ausencia de validación institucional) y trabajo futuro.

## 5. Riesgos y mitigaciones

1. **No conseguir datos reales adicionales** → la Fase 1 lo detecta en la primera semana; el plan B (documentar limitación y acotar categorías) está previsto por el propio documento.
2. **BETO no supera al baseline con un corpus pequeño** → resultado científicamente válido; el criterio 4.6 ya define qué hacer y la memoria lo reporta como hallazgo, no como fracaso.
3. **Sprint 3 corto (6 días hábiles)** → el MVP (clasificar + ticket) ya funciona desde hoy; solo se re-entrena con el corpus final, así que el riesgo de llegar sin incremento funcional es bajo.
4. **Calibración inestable con validación pequeña** → re-elegir método (sigmoide vs. isotónica vs. temperatura) sobre la validación ampliada de la Fase 1 antes de fijar el umbral.

## 6. Cómo ejecutar (resumen)

```bash
# En tu máquina, dentro de la carpeta seminario2:
python3 01_preparar_corpus.py      # genera corpus/
python3 02_baseline_tfidf.py       # genera resultados/ (baseline + ticket)

# En Google Colab (GPU): abrir 03_beto_colab.ipynb, subir corpus/train.csv,
# val.csv y test.csv, ejecutar todo, descargar predicciones_test_beto.csv
# y guardarlo en resultados/. Después, de vuelta en tu máquina:
python3 03_comparar_modelos.py     # comparación y decisión final
```
