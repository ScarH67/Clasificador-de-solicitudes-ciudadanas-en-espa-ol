# -*- coding: utf-8 -*-
"""
02_baseline_tfidf.py — Sprints 1–3 (E2 Modelo base, E5 Calibración y
abstención, E4 Generación de tickets, E6 Evaluación).

Entrena el baseline TF-IDF + regresión logística sobre corpus/train.csv,
calibra probabilidades con corpus/val.csv, evalúa sobre corpus/test.csv
(F1 macro, F1 por clase, matriz de confusión, ECE, cobertura de abstención)
y demuestra la generación de un ticket estructurado.

Uso:  python3 02_baseline_tfidf.py
Requiere haber ejecutado antes 01_preparar_corpus.py.
Salidas: resultados/baseline_metricas.txt, resultados/matriz_confusion.png,
         resultados/predicciones_test.csv, resultados/modelo_baseline.joblib,
         resultados/ticket_ejemplo.json
"""
import os, json, uuid, datetime
import numpy as np
import pandas as pd
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.calibration import CalibratedClassifierCV
try:
    from sklearn.calibration import FrozenEstimator
except ImportError:   # scikit-learn < 1.6
    FrozenEstimator = None
from sklearn.metrics import (classification_report, confusion_matrix,
                             f1_score, accuracy_score)
import joblib

SEED = 42
UMBRAL_ABSTENCION = 0.60   # confianza calibrada mínima para emitir categoría
BASE = os.path.dirname(os.path.abspath(__file__))
CORPUS = os.path.join(BASE, "corpus")
OUT = os.path.join(BASE, "resultados")
os.makedirs(OUT, exist_ok=True)

tr = pd.read_csv(os.path.join(CORPUS, "train.csv"))
va = pd.read_csv(os.path.join(CORPUS, "val.csv"))
te = pd.read_csv(os.path.join(CORPUS, "test.csv"))

# ---------- 1. Pipeline TF-IDF + regresión logística ----------
pipe = Pipeline([
    ("tfidf", TfidfVectorizer(lowercase=True, strip_accents="unicode",
                              ngram_range=(1, 2), min_df=1, max_df=0.9,
                              sublinear_tf=True)),
    ("clf", LogisticRegression(max_iter=2000, C=4.0,
                               class_weight="balanced",  # desbalance (3.3.6)
                               random_state=SEED)),
])
pipe.fit(tr["texto"], tr["categoria"])

# ---------- 2. Calibración con el conjunto de validación (3.3.8) ----------
# sigmoid (Platt) es adecuado para conjuntos de validación pequeños.
if FrozenEstimator is not None:
    cal = CalibratedClassifierCV(FrozenEstimator(pipe), method="sigmoid")
else:   # scikit-learn < 1.6
    cal = CalibratedClassifierCV(pipe, method="sigmoid", cv="prefit")
cal.fit(va["texto"], va["categoria"])
clases = cal.classes_

# ---------- 3. Evaluación sobre prueba ----------
proba = cal.predict_proba(te["texto"])
pred = clases[np.argmax(proba, axis=1)]
conf = np.max(proba, axis=1)
abstiene = conf < UMBRAL_ABSTENCION

def ece(y_true, y_pred, confs, n_bins=10):
    """Expected Calibration Error (3.3.8 / métrica 4.5)."""
    correcto = (y_true == y_pred).astype(float)
    bins = np.linspace(0, 1, n_bins + 1)
    total, err = len(confs), 0.0
    for lo, hi in zip(bins[:-1], bins[1:]):
        m = (confs > lo) & (confs <= hi)
        if m.sum():
            err += m.sum() / total * abs(correcto[m].mean() - confs[m].mean())
    return err

f1m = f1_score(te["categoria"], pred, average="macro")
acc = accuracy_score(te["categoria"], pred)
rep = classification_report(te["categoria"], pred, digits=3, zero_division=0)
cm = confusion_matrix(te["categoria"], pred, labels=clases)
e = ece(te["categoria"].values, pred, conf)
cobertura = 1 - abstiene.mean()
mask = ~abstiene
f1_auto = (f1_score(te["categoria"][mask], pred[mask], average="macro")
           if mask.sum() else float("nan"))

lineas = [
    "BASELINE TF-IDF + REGRESIÓN LOGÍSTICA — RESULTADOS SOBRE PRUEBA",
    f"Fecha: {datetime.datetime.now():%Y-%m-%d %H:%M}",
    f"Train: {len(tr)}  Val: {len(va)}  Test: {len(te)}",
    "",
    f"Accuracy (referencia):        {acc:.3f}",
    f"F1 macro (métrica principal): {f1m:.3f}   (criterio de éxito: >= 0.80)",
    f"ECE (calibración):            {e:.3f}",
    f"Umbral de abstención:         {UMBRAL_ABSTENCION:.2f}",
    f"Cobertura automática:         {cobertura:.1%} "
    f"({int(mask.sum())}/{len(te)} tickets emitidos sin revisión)",
    f"F1 macro solo casos emitidos: {f1_auto:.3f}",
    "",
    "Reporte por clase:", rep, "",
    "Matriz de confusión (filas = real, columnas = predicho):",
    pd.DataFrame(cm, index=clases, columns=clases).to_string(),
]
texto_rep = "\n".join(lineas)
with open(os.path.join(OUT, "baseline_metricas.txt"), "w") as f:
    f.write(texto_rep + "\n")
print(texto_rep)

# ---------- 4. Matriz de confusión (figura) ----------
fig, ax = plt.subplots(figsize=(7, 6))
im = ax.imshow(cm, cmap="Blues")
ax.set_xticks(range(len(clases)), clases, rotation=45, ha="right")
ax.set_yticks(range(len(clases)), clases)
for i in range(len(clases)):
    for j in range(len(clases)):
        ax.text(j, i, cm[i, j], ha="center", va="center",
                color="white" if cm[i, j] > cm.max()/2 else "black")
ax.set_xlabel("Predicho"); ax.set_ylabel("Real")
ax.set_title("Matriz de confusión — baseline TF-IDF + LogReg")
fig.colorbar(im); fig.tight_layout()
fig.savefig(os.path.join(OUT, "matriz_confusion.png"), dpi=150)

# ---------- 5. Predicciones y análisis de errores ----------
sal = te[["id_registro", "texto", "categoria"]].copy()
sal["prediccion"] = pred
sal["confianza"] = conf.round(3)
sal["requiere_revision"] = abstiene
sal["correcto"] = sal["categoria"] == sal["prediccion"]
sal.to_csv(os.path.join(OUT, "predicciones_test.csv"), index=False)

# ---------- 6. Generación de ticket estructurado (E4, Tabla 3) ----------
def generar_ticket(texto_solicitud, modelo=cal, umbral=UMBRAL_ABSTENCION):
    p = modelo.predict_proba([texto_solicitud])[0]
    i = int(np.argmax(p))
    confianza = float(p[i])
    return {
        "folio": "CDMX-" + uuid.uuid4().hex[:8].upper(),
        "texto_original": texto_solicitud,
        "categoria": str(modelo.classes_[i]),
        "confianza": round(confianza, 3),
        "estado_revision": ("automatico" if confianza >= umbral
                            else "requiere_revision"),
        "fecha_hora": datetime.datetime.now().isoformat(timespec="seconds"),
        "metadatos": {"modelo": "tfidf_logreg_calibrado",
                      "umbral_abstencion": umbral},
    }

ejemplo = generar_ticket(
    "Hay un bache enorme en la esquina de mi calle, ya se dañaron varios coches, ayuda porfa")
with open(os.path.join(OUT, "ticket_ejemplo.json"), "w") as f:
    json.dump(ejemplo, f, ensure_ascii=False, indent=2)
print("\nTICKET DE EJEMPLO:")
print(json.dumps(ejemplo, ensure_ascii=False, indent=2))

joblib.dump(cal, os.path.join(OUT, "modelo_baseline.joblib"))
print("\nModelo guardado en resultados/modelo_baseline.joblib")
