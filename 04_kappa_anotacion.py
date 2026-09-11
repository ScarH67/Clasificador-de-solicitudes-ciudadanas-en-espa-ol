# -*- coding: utf-8 -*-
"""
04_kappa_anotacion.py — Sprint 1 (E1 / HU-02, Anexo C)

Calcula el acuerdo entre los dos anotadores sobre la muestra de control
(Kappa de Cohen) y compara sus etiquetas con el pre-etiquetado por reglas.

Instrucciones previas:
1. Cada anotador llena SU columna en corpus/muestra_control_anotacion.csv
   (etiqueta_anotador_1 / etiqueta_anotador_2) SIN ver la del otro, usando
   exactamente estas etiquetas:
   baches_y_pavimento | alumbrado_publico | fuga_de_agua |
   recoleccion_basura | seguridad | fuera_de_alcance
2. Guardar el CSV y ejecutar:  python3 04_kappa_anotacion.py

Interpretación (Landis & Koch, 1977): <0.20 pobre, 0.21-0.40 débil,
0.41-0.60 moderado, 0.61-0.80 sustancial, 0.81-1.00 casi perfecto.
Criterio del proyecto: Kappa >= 0.70 para aceptar la guía de anotación.
"""
import os
import pandas as pd
from sklearn.metrics import cohen_kappa_score, confusion_matrix

BASE = os.path.dirname(os.path.abspath(__file__))
VALIDAS = {"baches_y_pavimento", "alumbrado_publico", "fuga_de_agua",
           "recoleccion_basura", "seguridad", "fuera_de_alcance"}

m = pd.read_csv(os.path.join(BASE, "corpus", "muestra_control_anotacion.csv"))
a1 = m["etiqueta_anotador_1"].astype(str).str.strip().str.lower()
a2 = m["etiqueta_anotador_2"].astype(str).str.strip().str.lower()

falta = (~a1.isin(VALIDAS)) | (~a2.isin(VALIDAS))
if falta.any():
    print(f"AVISO: {falta.sum()} filas sin etiqueta válida de ambos anotadores;"
          " se excluyen del cálculo.")
    m, a1, a2 = m[~falta], a1[~falta], a2[~falta]
if len(m) < 30:
    raise SystemExit("Muy pocas filas anotadas para un Kappa confiable (<30).")

kappa = cohen_kappa_score(a1, a2)
acuerdo = (a1 == a2).mean()
print(f"Casos evaluados:            {len(m)}")
print(f"Acuerdo observado:          {acuerdo:.1%}")
print(f"Kappa de Cohen:             {kappa:.3f}   (criterio: >= 0.70)")

# Comparación con el pre-etiquetado por reglas (donde ambos coinciden)
corpus = pd.read_csv(os.path.join(BASE, "corpus", "corpus_completo.csv"))
m2 = m.merge(corpus[["id_registro", "categoria"]], on="id_registro", how="left")
consenso = m2[a1.values == a2.values].copy()
consenso["humana"] = a1[a1 == a2].values
coincide = (consenso["humana"] == consenso["categoria"]).mean()
print(f"Reglas vs. consenso humano: {coincide:.1%} de coincidencia")
disc = consenso[consenso["humana"] != consenso["categoria"]]
if len(disc):
    print(f"\n{len(disc)} discrepancias reglas vs. humanos (revisar y corregir en el corpus):")
    for _, r in disc.iterrows():
        print(f"  [{r['id_registro']}] reglas={r['categoria']} humanos={r['humana']}: {r['texto'][:80]}")
disc.to_csv(os.path.join(BASE, "corpus", "discrepancias_anotacion.csv"), index=False)
print("\nGuardado: corpus/discrepancias_anotacion.csv")
print("Siguiente paso: corregir esas etiquetas en el corpus si procede,")
print("re-ejecutar 01_preparar_corpus.py y 02_baseline_tfidf.py, y continuar con BETO en Colab.")
