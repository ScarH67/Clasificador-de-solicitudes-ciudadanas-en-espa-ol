# -*- coding: utf-8 -*-
"""
03_comparar_modelos.py — Sprint 3 (E6. Evaluación / HU-07, HU-12)

Compara el baseline TF-IDF + regresión logística contra BETO usando las
predicciones sobre el MISMO conjunto de prueba y aplica el criterio de
decisión de la sección 4.6 del documento.

Uso: python3 03_comparar_modelos.py
Requiere: resultados/predicciones_test.csv        (de 02_baseline_tfidf.py)
          resultados/predicciones_test_beto.csv   (del notebook de Colab)
Salida:   resultados/comparacion_final.txt
"""
import os
import pandas as pd
from sklearn.metrics import f1_score, accuracy_score, classification_report

BASE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(BASE, "resultados")

def cargar(nombre):
    p = os.path.join(RES, nombre)
    if not os.path.exists(p):
        raise SystemExit(f"Falta {p}. Ejecuta antes el paso que lo genera.")
    return pd.read_csv(p)

base = cargar("predicciones_test.csv")
beto = cargar("predicciones_test_beto.csv")
m = base.merge(beto, on="id_registro", suffixes=("_base", "_beto"))
assert (m["categoria_base"] == m["categoria_beto"]).all(), "particiones distintas"
y = m["categoria_base"]

def resumen(pred, conf, revision):
    emit = ~revision.astype(bool)
    return {
        "accuracy": accuracy_score(y, pred),
        "f1_macro": f1_score(y, pred, average="macro"),
        "cobertura": emit.mean(),
        "f1_macro_emitidos": (f1_score(y[emit], pred[emit], average="macro")
                              if emit.sum() else float("nan")),
    }

rb = resumen(m["prediccion_base"], m["confianza_base"], m["requiere_revision_base"])
rt = resumen(m["prediccion_beto"], m["confianza_beto"], m["requiere_revision_beto"])

tab = pd.DataFrame([rb, rt], index=["TF-IDF + LogReg", "BETO"]).round(3)
mejora = rt["f1_macro"] - rb["f1_macro"]
lineas = [
    "COMPARACIÓN FINAL BASELINE vs. BETO (mismo conjunto de prueba)",
    "", tab.to_string(), "",
    f"Mejora de BETO en F1 macro: {mejora:+.3f}",
    "",
    "Criterio de decisión (sección 4.6):",
]
if mejora > 0.02:
    lineas.append("→ BETO supera de manera consistente al baseline: se adopta"
                  " BETO como modelo del prototipo.")
else:
    lineas.append("→ La mejora no compensa el costo adicional: se conserva"
                  " TF-IDF + regresión logística como modelo del prototipo.")
lineas += ["", "F1 por clase — baseline:",
           classification_report(y, m["prediccion_base"], digits=3, zero_division=0),
           "F1 por clase — BETO:",
           classification_report(y, m["prediccion_beto"], digits=3, zero_division=0)]
texto = "\n".join(lineas)
with open(os.path.join(RES, "comparacion_final.txt"), "w") as f:
    f.write(texto + "\n")
print(texto)
