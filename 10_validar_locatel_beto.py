# -*- coding: utf-8 -*-
"""
10_validar_locatel_beto.py — Validación externa de BETO con la muestra de LOCATEL

Envía los 67 registros codificados por dos evaluadores humanos
(resultados/locatel_kappa_muestra.csv, exportado de
"Registros LOCATEL codificación Kappa.xlsx") a la API local de BETO
(09_api_beto.py) y compara la predicción con las etiquetas humanas.

Uso (con la API encendida en otra terminal: python3 09_api_beto.py):
    python3 10_validar_locatel_beto.py

Solo usa la biblioteca estándar de Python.
Salida: resultados/validacion_locatel_beto.csv y un resumen en pantalla.
"""
import csv, json, os, urllib.request

BASE = os.path.dirname(os.path.abspath(__file__))
API = "http://127.0.0.1:8000/clasificar"
ENTRADA = os.path.join(BASE, "resultados", "locatel_kappa_muestra.csv")
SALIDA = os.path.join(BASE, "resultados", "validacion_locatel_beto.csv")

MAPA = {"Baches y pavimento": "baches_y_pavimento", "Alumbrado público": "alumbrado_publico",
        "Fuga de agua": "fuga_de_agua", "Recolección de basura": "recoleccion_basura",
        "Seguridad": "seguridad"}
CATS = list(MAPA.values())

def clasificar(texto):
    req = urllib.request.Request(API, data=json.dumps({"texto": texto}).encode("utf-8"),
                                 headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode("utf-8"))

def kappa(a, b):
    n = len(a)
    if n == 0: return float("nan")
    po = sum(x == y for x, y in zip(a, b)) / n
    cats = set(a) | set(b)
    pe = sum((a.count(c) / n) * (b.count(c) / n) for c in cats)
    return (po - pe) / (1 - pe) if pe < 1 else float("nan")

filas = list(csv.DictReader(open(ENTRADA, encoding="utf-8")))
out = []
for i, f in enumerate(filas, 1):
    t = clasificar(f["texto"])
    out.append({"id": f["id"], "evaluador_a": f["evaluador_a"], "evaluador_b": f["evaluador_b"],
                "beto_categoria": t.get("categoria"), "beto_confianza": t.get("confianza"),
                "estado_revision": t.get("estado_revision"),
                "posible_fuera_de_alcance": t.get("posible_fuera_de_alcance"),
                "texto": f["texto"]})
    print(f"{i:>2}/{len(filas)}  {t.get('categoria'):<20} {t.get('confianza')}")
with open(SALIDA, "w", encoding="utf-8", newline="") as fh:
    w = csv.DictWriter(fh, fieldnames=list(out[0].keys())); w.writeheader(); w.writerows(out)

# ---- Resumen
cons = [o for o in out if o["evaluador_a"] == o["evaluador_b"] and o["evaluador_a"] in MAPA]
ok = [o for o in cons if o["beto_categoria"] == MAPA[o["evaluador_a"]]]
auto = [o for o in cons if o["estado_revision"] == "automatico"]
ok_auto = [o for o in auto if o["beto_categoria"] == MAPA[o["evaluador_a"]]]
print("\n=== Validación externa de BETO (muestra LOCATEL) ===")
print(f"Registros enviados: {len(out)}")
print(f"Consenso humano en una de las 5 categorías: {len(cons)}")
print(f"  Accuracy de BETO frente al consenso: {len(ok)}/{len(cons)} = {len(ok)/max(len(cons),1):.3f}")
print(f"  Casos automáticos (conf >= umbral): {len(auto)} ({len(auto)/max(len(cons),1):.1%}); "
      f"acierto en automáticos: {len(ok_auto)}/{len(auto)}")
for ev in ("evaluador_a", "evaluador_b"):
    par = [o for o in out if o[ev] in MAPA]
    k = kappa([MAPA[o[ev]] for o in par], [o["beto_categoria"] for o in par])
    print(f"  Kappa BETO vs {ev} (n={len(par)}, solo 5 categorías): {k:.3f}")
otros = [o for o in out if o["evaluador_a"] not in MAPA or o["evaluador_b"] not in MAPA]
rev = [o for o in otros if o["estado_revision"] != "automatico" or str(o["posible_fuera_de_alcance"]).lower() == "true"]
print(f"Casos marcados por algún evaluador como «No es posible determinar» o «Desechar»: {len(otros)}; "
      f"BETO los envió a revisión o los marcó fuera de alcance: {len(rev)}")
print(f"\nGuardado: {SALIDA}")
