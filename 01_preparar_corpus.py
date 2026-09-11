# -*- coding: utf-8 -*-
"""
01_preparar_corpus.py — Sprint 1 (E1. Corpus y etiquetado)
Proyecto: Clasificador de solicitudes ciudadanas en español con generación
automática de tickets en CDMX (UNIR - Maestría en IA).

Unifica las tres fuentes Excel, corrige codificación, deduplica, asigna las
cinco categorías del proyecto mediante reglas entidad+palabras clave
(pre-etiquetado a validar por anotadores, Anexo C) y genera la partición
train/val/test estratificada sin fuga de información (deduplicación previa).

Uso:  python3 01_preparar_corpus.py
Entradas (misma carpeta): Dataset Twitter.xlsx, Quejas CDMX 2021-2022.xlsx,
                          Quejas Twitter.xlsx
Salidas: corpus/corpus_completo.csv, corpus/train.csv, corpus/val.csv,
         corpus/test.csv, corpus/excluidos_fuera_alcance.csv,
         corpus/reporte_corpus.txt
"""
import os, re, sys, hashlib
import pandas as pd

SEED = 42
BASE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(BASE, "corpus")
os.makedirs(OUT, exist_ok=True)

# ---------- 1. Carga y normalización de fuentes ----------
def fix_mojibake(s):
    """Repara texto UTF-8 leído como latin-1 (Ã­ -> í)."""
    if not isinstance(s, str):
        return s
    try:
        rep = s.encode("latin-1").decode("utf-8")
        return rep if rep.count("Ã") < s.count("Ã") else s
    except (UnicodeEncodeError, UnicodeDecodeError):
        return s

fuentes = []

f1 = os.path.join(BASE, "Dataset Twitter.xlsx")
d1 = pd.read_excel(f1)
d1 = d1.rename(columns={"Entidad_Dirigida": "entidad", "Texto_Queja": "texto",
                        "Alcaldía": "alcaldia", "Fecha": "fecha"})
d1["fuente"] = "twitter_muestra_100"
fuentes.append(d1[["texto", "entidad", "alcaldia", "fecha", "fuente"]])

f2 = os.path.join(BASE, "Quejas CDMX 2021-2022.xlsx")
d2 = pd.read_excel(f2)
d2["texto_queja"] = d2["texto_queja"].map(fix_mojibake)
d2["entidad"] = d2["entidad"].map(fix_mojibake)
d2 = d2.rename(columns={"texto_queja": "texto"})
d2["alcaldia"] = d2["entidad"].str.replace("Alcaldía ", "", regex=False)
d2.loc[d2["entidad"] == "No especificada", "alcaldia"] = ""
d2["entidad"] = ""
d2["fuente"] = "quejas_cdmx_2021_2022"
fuentes.append(d2[["texto", "entidad", "alcaldia", "fecha", "fuente"]])

f3 = os.path.join(BASE, "Quejas Twitter.xlsx")
d3 = pd.read_excel(f3)
d3 = d3.rename(columns={"entidad_dirigida": "entidad", "texto_queja": "texto"})
d3["fuente"] = "twitter_con_alcaldia"
fuentes.append(d3[["texto", "entidad", "alcaldia", "fecha", "fuente"]])

df = pd.concat(fuentes, ignore_index=True)
n_inicial = len(df)

# ---------- 2. Limpieza básica y deduplicación ----------
df["texto"] = df["texto"].astype(str).str.strip()
df = df[df["texto"].str.len() >= 15]                       # registros no útiles
df["texto_norm"] = (df["texto"].str.lower()
                    .str.replace(r"@\w+", " ", regex=True) # menciones fuera de la señal
                    .str.replace(r"\W+", " ", regex=True).str.strip())
n_prededup = len(df)
df = df.drop_duplicates("texto_norm").copy()               # duplicados exactos
n_unicos = len(df)
# Reportes relacionados: misma plantilla con distinto número (p. ej. "poste 4540"
# vs "poste 1097"). Se agrupan por plantilla (dígitos colapsados) y la partición
# se hace POR GRUPO, para no dividir reportes casi idénticos entre conjuntos
# (sección 3.5 del documento: prevención de fuga de información).
df["grupo_plantilla"] = df["texto_norm"].str.replace(r"\d+", "#", regex=True)
n_plantillas = df["grupo_plantilla"].nunique()
# Para que ninguna plantilla domine el corpus (p. ej. cientos de variantes del
# mismo reporte de C5 con distinto número de poste), se conservan como máximo
# 3 ejemplares por plantilla. Esto evita inflar métricas con casi-duplicados.
df = df.groupby("grupo_plantilla", group_keys=False).head(3)
n_tras_cap = len(df)

# ---------- 3. Pre-etiquetado: entidad + palabras clave -> 5 categorías ----------
# Categorías del proyecto (sección Alcance): baches y pavimento, alumbrado
# público, fuga de agua, recolección de basura y seguridad.
REGLAS = [
    ("fuga_de_agua", r"fuga|agua potable|sin agua|tandeo|suministro de agua|"
                     r"desazolve|coladera|alcantarill|drenaje|encharcamient|inundaci"),
    ("baches_y_pavimento", r"\bbache|pavimen|asfalt|socav[oó]n|hundimiento|"
                           r"empedrado|repavimentar|v[ií]a p[uú]blica.*mal estado|"
                           r"mal estado de la v[ií]a"),
    ("alumbrado_publico", r"luminaria|alumbrado|l[aá]mpara|farol|poste de luz|"
                          r"sin luz en la calle|luz mercurial"),
    ("recoleccion_basura", r"basura|recolecci[oó]n|desech|residuo|tiradero|"
                           r"cascajo|escombro|camion de la basura|cami[oó]n recolector|limpia\b"),
    ("seguridad", r"asalt|robo|rob[ao]n|inseguridad|vigilancia|patrulla|delincuen|"
                  r"balacera|extorsi|narcomenudeo|venta irregular de alcohol|"
                  r"c[aá]mara de vigilancia|maltrato|violencia|polic[ií]a"),
]
PRIOR_ENTIDAD = {  # apoyo cuando el texto no decide por sí solo
    "@SacmexCDMX": "fuga_de_agua",
    "@SSC_CDMX": "seguridad",
    "@FGJCDMX": "seguridad",
    "@C5_CDMX": "seguridad",
}

def etiquetar(row):
    t = row["texto_norm"]
    hits = [cat for cat, pat in REGLAS if re.search(pat, t)]
    if len(hits) == 1:
        return hits[0], "regla_texto"
    if len(hits) > 1:
        # prioridad: la primera regla que aparece antes en el texto
        pos = {cat: re.search(pat, t).start() for cat, pat in REGLAS if cat in hits}
        return min(pos, key=pos.get), "regla_texto_multiple"
    ent = str(row["entidad"]).strip()
    if ent in PRIOR_ENTIDAD:
        return PRIOR_ENTIDAD[ent], "prior_entidad"
    return "fuera_de_alcance", "sin_regla"

df[["categoria", "metodo_etiqueta"]] = df.apply(
    lambda r: pd.Series(etiquetar(r)), axis=1)

# ---------- 4. Separar corpus útil vs. fuera de alcance ----------
corpus = df[df["categoria"] != "fuera_de_alcance"].copy()
excluidos = df[df["categoria"] == "fuera_de_alcance"].copy()

# Folio reproducible por hash del texto (trazabilidad, Anexo C)
corpus["id_registro"] = corpus["texto_norm"].map(
    lambda t: "REG-" + hashlib.sha1(t.encode()).hexdigest()[:10].upper())

# ---------- 5. Partición 70/15/15 por grupos de plantilla (sin fuga) ----------
import numpy as np
rng = np.random.RandomState(SEED)
# Asignación de grupos completos a train/val/test, balanceando por categoría:
# los grupos de cada categoría se barajan y se reparten ~70/15/15.
asign = {}
for cat, sub in corpus.groupby("categoria"):
    grupos = sub["grupo_plantilla"].unique().tolist()
    rng.shuffle(grupos)
    n = len(grupos)
    n_te = max(1, round(0.15 * n))
    n_va = max(1, round(0.15 * n)) if n > 2 else 0
    for g in grupos[:n_te]:
        asign[g] = "test"
    for g in grupos[n_te:n_te + n_va]:
        asign[g] = "val"
    for g in grupos[n_te + n_va:]:
        asign[g] = "train"
corpus["particion"] = corpus["grupo_plantilla"].map(asign)
tr = corpus[corpus["particion"] == "train"]
va = corpus[corpus["particion"] == "val"]
te = corpus[corpus["particion"] == "test"]

cols = ["id_registro", "texto", "categoria", "metodo_etiqueta",
        "grupo_plantilla", "entidad", "alcaldia", "fecha", "fuente"]
corpus[cols].to_csv(os.path.join(OUT, "corpus_completo.csv"), index=False)
tr[cols].to_csv(os.path.join(OUT, "train.csv"), index=False)
va[cols].to_csv(os.path.join(OUT, "val.csv"), index=False)
te[cols].to_csv(os.path.join(OUT, "test.csv"), index=False)
excluidos[["texto", "entidad", "fuente"]].to_csv(
    os.path.join(OUT, "excluidos_fuera_alcance.csv"), index=False)

# ---------- 6. Reporte ----------
lineas = []
lineas.append("REPORTE DE CONSTRUCCIÓN DEL CORPUS")
lineas.append(f"Registros iniciales (3 fuentes): {n_inicial}")
lineas.append(f"Tras limpieza básica:            {n_prededup}")
lineas.append(f"Textos únicos (deduplicados):    {n_unicos}")
lineas.append(f"Plantillas únicas (sin dígitos): {n_plantillas}")
lineas.append(f"Tras tope de 3 por plantilla:    {n_tras_cap}")
lineas.append(f"Dentro de las 5 categorías:      {len(corpus)}")
lineas.append(f"Fuera de alcance (excluidos):    {len(excluidos)}")
lineas.append("")
lineas.append("Distribución de clases (corpus útil):")
for cat, n in corpus["categoria"].value_counts().items():
    lineas.append(f"  {cat:22s} {n:5d}  ({100*n/len(corpus):.1f} %)")
lineas.append("")
lineas.append("Método de etiquetado:")
for m, n in corpus["metodo_etiqueta"].value_counts().items():
    lineas.append(f"  {m:22s} {n:5d}")
lineas.append("")
lineas.append(f"Partición por grupos de plantilla (sin fuga entre conjuntos):")
lineas.append(f"  train={len(tr)}  val={len(va)}  test={len(te)}")
lineas.append("")
lineas.append("Distribución por partición y categoría:")
tab = corpus.pivot_table(index="categoria", columns="particion",
                         values="texto", aggfunc="count").fillna(0).astype(int)
lineas.append(tab.to_string())
lineas.append("")
lineas.append("ADVERTENCIA: las fuentes actuales son en gran parte plantillas")
lineas.append("repetidas (8,100 filas -> pocas plantillas reales). El corpus")
lineas.append("útil queda muy por debajo de la meta de 2,000 y con fuerte")
lineas.append("desbalance (ver arriba). Se recomienda ampliar las fuentes")
lineas.append("(SUAC / Datos Abiertos CDMX) para baches, alumbrado y basura,")
lineas.append("o documentar la limitación conforme a la sección 4.7.")
lineas.append("")
lineas.append("NOTA: el etiquetado es un PRE-etiquetado por reglas. Según la")
lineas.append("guía de anotación (Anexo C), dos anotadores deben revisar una")
lineas.append("muestra de control y calcular Kappa de Cohen antes de dar el")
lineas.append("corpus por definitivo.")
rep = "\n".join(lineas)
with open(os.path.join(OUT, "reporte_corpus.txt"), "w") as f:
    f.write(rep + "\n")
print(rep)
