# -*- coding: utf-8 -*-
"""
09_api_beto.py — Prototipo funcional con el MODELO GANADOR (E4 / sección 3.7)

Misma API y misma interfaz que 07_api.py, pero usando BETO (fine-tuning de
dccuchile/bert-base-spanish-wwm-cased) en lugar del baseline TF-IDF, conforme
a la decisión de la sección 4.6: BETO supera al baseline en F1 macro
(0.919 vs. 0.872, ver resultados/comparacion_final.txt) y se adopta como
modelo del prototipo.

Diferencias frente a 07_api.py:
  - Clasificador: BETO fine-tuneado (carpeta resultados/beto_cdmx_final/,
    extraída de resultados/Beto CDMX Final.zip descargado de Colab).
  - Calibración: temperatura T=0.363, el mismo valor obtenido en
    03_beto_colab.ipynb (sección "Calibración por temperatura con
    validación"), aplicada como softmax(logits / T).
  - Umbral de abstención: 0.90 en vez de 0.60. Con BETO, el umbral heredado
    del baseline deja pasar demasiados errores sin revisión (23 de 282);
    0.90 es el punto de operación recomendado en resultados/comparacion_final.txt
    (86.5% de cobertura, 9 errores emitidos, 96.3% de acierto en lo emitido).
  - El detector de fuera de alcance (E5) sigue siendo el clasificador auxiliar
    sklearn de 6 clases (resultados/modelo_guardian.joblib): es independiente
    del modelo principal y no requiere reentrenarse al cambiar de clasificador.

Requiere además de requirements.txt: torch y transformers (ver
requirements.txt y INSTALACION.md — no se necesita GPU, la inferencia en
CPU de un solo texto corto toma decenas de milisegundos).

Ejecutar:   python3 09_api_beto.py        (o: uvicorn 09_api_beto:app --reload)
Interfaz:   http://127.0.0.1:8000
API docs:   http://127.0.0.1:8000/docs
Nota: usa el mismo puerto 8000 que 07_api.py; no ejecutar ambos a la vez
(o cambiar el puerto en el último bloque del archivo).

Endpoints:
  GET  /salud       -> estado del servicio y modelo cargado
  POST /clasificar  -> {"texto": "..."} => ticket estructurado (JSON)
"""
import os, uuid, time, datetime, zipfile
import joblib
import numpy as np
import torch
import torch.nn.functional as F
from transformers import AutoTokenizer, AutoModelForSequenceClassification
from fastapi import FastAPI, HTTPException
from fastapi.responses import HTMLResponse
from pydantic import BaseModel, Field

UMBRAL_ABSTENCION = 0.90   # recomendación de resultados/comparacion_final.txt (sección 4)
UMBRAL_GUARDIAN = 0.60
TEMPERATURA = 0.363        # calibración de 03_beto_colab.ipynb (softmax(logits / T))
MAX_LONGITUD_TOKENS = 128

BASE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(BASE, "resultados")
MODELO_DIR = os.path.join(RES, "beto_cdmx_final")
MODELO_ZIP = os.path.join(RES, "Beto CDMX Final.zip")

# Si el modelo no está descomprimido pero sí está el .zip descargado de
# Colab, se descomprime automáticamente la primera vez.
if not os.path.isdir(MODELO_DIR) and os.path.exists(MODELO_ZIP):
    with zipfile.ZipFile(MODELO_ZIP) as z:
        z.extractall(RES)

if not os.path.isdir(MODELO_DIR):
    raise SystemExit(
        f"No se encontró el modelo BETO en {MODELO_DIR}. Coloca "
        f"'Beto CDMX Final.zip' (exportado de 03_beto_colab.ipynb) en "
        f"{RES} y vuelve a ejecutar."
    )

DISPOSITIVO = "cuda" if torch.cuda.is_available() else "cpu"
tokenizer = AutoTokenizer.from_pretrained(MODELO_DIR)
modelo = AutoModelForSequenceClassification.from_pretrained(MODELO_DIR)
modelo.to(DISPOSITIVO)
modelo.eval()
ID2LABEL = modelo.config.id2label  # {0: "alumbrado_publico", ...}, del fine-tuning en Colab

_g = os.path.join(RES, "modelo_guardian.joblib")
guardian = joblib.load(_g) if os.path.exists(_g) else None

ETIQUETAS = {
    "baches_y_pavimento": "Baches y pavimento",
    "alumbrado_publico": "Alumbrado público",
    "fuga_de_agua": "Agua y drenaje",
    "recoleccion_basura": "Recolección de basura",
    "seguridad": "Seguridad",
}

app = FastAPI(
    title="Clasificador de solicitudes ciudadanas CDMX — BETO",
    description="Prototipo académico (UNIR). Clasifica solicitudes en 5 "
                "categorías de servicio y genera un ticket estructurado. "
                "Modelo: BETO (dccuchile/bert-base-spanish-wwm-cased) "
                "fine-tuneado, calibrado por temperatura. Modelo adoptado "
                "conforme al criterio de decisión de la sección 4.6.",
    version="1.0.0",
)

class Solicitud(BaseModel):
    texto: str = Field(..., min_length=15, max_length=2000,
                       description="Descripción libre de la solicitud ciudadana")

class Ticket(BaseModel):
    folio: str
    texto_original: str
    categoria: str
    categoria_legible: str
    confianza: float
    posible_fuera_de_alcance: bool
    estado_revision: str
    fecha_hora: str
    tiempo_ms: float
    metadatos: dict

@torch.no_grad()
def clasificar_con_beto(texto: str):
    enc = tokenizer(texto, truncation=True, max_length=MAX_LONGITUD_TOKENS,
                     padding=True, return_tensors="pt").to(DISPOSITIVO)
    logits = modelo(**enc).logits[0]
    probs = F.softmax(logits / TEMPERATURA, dim=-1).cpu().numpy()
    i = int(np.argmax(probs))
    return ID2LABEL[i], float(probs[i])

@app.get("/salud")
def salud():
    return {"estado": "ok",
            "modelo": "beto_finetuned_calibrado",
            "dispositivo": DISPOSITIVO,
            "temperatura_calibracion": TEMPERATURA,
            "guardian_activo": guardian is not None,
            "categorias": list(ETIQUETAS.values()),
            "umbral_abstencion": UMBRAL_ABSTENCION}

@app.post("/clasificar", response_model=Ticket)
def clasificar(s: Solicitud):
    texto = s.texto.strip()
    if len(texto) < 15:
        raise HTTPException(422, "El texto es demasiado corto para clasificarse.")
    t0 = time.perf_counter()
    categoria, confianza = clasificar_con_beto(texto)
    estado = "automatico" if confianza >= UMBRAL_ABSTENCION else "requiere_revision"
    alerta = False
    if guardian is not None:
        pg = guardian.predict_proba([texto])[0]
        j = list(guardian.classes_).index("fuera_de_alcance")
        if float(pg[j]) >= UMBRAL_GUARDIAN and \
           str(guardian.classes_[int(np.argmax(pg))]) == "fuera_de_alcance":
            alerta, estado = True, "requiere_revision"
    ms = (time.perf_counter() - t0) * 1000
    return Ticket(
        folio="CDMX-" + uuid.uuid4().hex[:8].upper(),
        texto_original=texto,
        categoria=categoria,
        categoria_legible=ETIQUETAS.get(categoria, categoria),
        confianza=round(confianza, 3),
        posible_fuera_de_alcance=alerta,
        estado_revision=estado,
        fecha_hora=datetime.datetime.now().isoformat(timespec="seconds"),
        tiempo_ms=round(ms, 1),
        metadatos={"modelo": "beto_finetuned_calibrado",
                   "dispositivo": DISPOSITIVO,
                   "temperatura_calibracion": TEMPERATURA,
                   "umbral_abstencion": UMBRAL_ABSTENCION},
    )

@app.get("/", response_class=HTMLResponse)
def interfaz():
    return HTML

HTML = """<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reporte Ciudadano CDMX — Prototipo (BETO)</title>
<style>
  :root { --verde:#0b6e4f; --verde-osc:#08543c; --ambar:#b45309; --gris:#5b6470; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,'Segoe UI',Roboto,sans-serif;
         background:#f2f4f3; color:#1c2321; }
  header { background:var(--verde); color:#fff; padding:1rem 1.25rem; }
  header h1 { margin:0; font-size:1.15rem; font-weight:600; }
  header p { margin:.2rem 0 0; font-size:.8rem; opacity:.85; }
  main { max-width:640px; margin:1.5rem auto; padding:0 1rem; }
  .tarjeta { background:#fff; border:1px solid #e2e6e4; border-radius:12px;
             padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 3px rgba(0,0,0,.05); }
  label { font-weight:600; font-size:.95rem; display:block; margin-bottom:.5rem; }
  textarea { width:100%; min-height:110px; padding:.7rem .8rem; font:inherit;
             border:1px solid #c9d0cc; border-radius:8px; resize:vertical; }
  textarea:focus { outline:2px solid var(--verde); border-color:transparent; }
  .fila { display:flex; gap:.75rem; align-items:center; margin-top:.75rem; flex-wrap:wrap; }
  button { background:var(--verde); color:#fff; border:0; border-radius:8px;
           padding:.65rem 1.4rem; font:inherit; font-weight:600; cursor:pointer; }
  button:hover { background:var(--verde-osc); }
  button:disabled { background:#9ab0a7; cursor:wait; }
  .nota { font-size:.78rem; color:var(--gris); }
  #resultado { display:none; }
  .folio { font-family:ui-monospace,Menlo,monospace; font-size:1.05rem;
           font-weight:700; letter-spacing:.5px; }
  .insignia { display:inline-block; padding:.2rem .6rem; border-radius:999px;
              font-size:.78rem; font-weight:600; }
  .ins-auto { background:#e3f4ec; color:var(--verde-osc); }
  .ins-rev  { background:#fef3e2; color:var(--ambar); }
  dl { display:grid; grid-template-columns:max-content 1fr; gap:.45rem 1rem;
       margin:1rem 0 0; font-size:.92rem; }
  dt { color:var(--gris); } dd { margin:0; }
  .barra { height:8px; background:#e8ecea; border-radius:999px; overflow:hidden;
           margin-top:.3rem; max-width:220px; }
  .barra i { display:block; height:100%; background:var(--verde); }
  .alerta { margin-top: .9rem; padding:.6rem .8rem; border-radius:8px; font-size:.85rem;
            background:#fef3e2; color:var(--ambar); }
  .error { color:#b3261e; font-size:.88rem; margin-top:.6rem; }
  footer { text-align:center; font-size:.72rem; color:var(--gris); margin:2rem 0 1rem; }
  .modelo { display:inline-block; margin-top:.35rem; font-size:.72rem;
            background:rgba(255,255,255,.18); padding:.15rem .55rem; border-radius:999px; }
</style>
</head>
<body>
<header>
  <h1>Reporte Ciudadano CDMX</h1>
  <p>Prototipo académico — clasificación automática y generación de ticket</p>
  <span class="modelo">Modelo: BETO fine-tuneado (calibrado)</span>
</header>
<main>
  <div class="tarjeta">
    <label for="texto">Describe tu solicitud con tus propias palabras</label>
    <textarea id="texto" maxlength="2000"
      placeholder="Ejemplo: llevamos 5 días sin agua en la colonia Portales y no han mandado pipas…"></textarea>
    <div class="fila">
      <button id="enviar">Enviar reporte</button>
      <span class="nota">No incluyas datos personales. El sistema sugiere una
      categoría; un operador la confirmará si la confianza es baja.</span>
    </div>
    <div id="error" class="error"></div>
  </div>

  <div class="tarjeta" id="resultado">
    <div class="fila" style="justify-content:space-between; margin-top:0">
      <span class="folio" id="folio"></span>
      <span class="insignia" id="estado"></span>
    </div>
    <dl>
      <dt>Categoría sugerida</dt><dd id="categoria"></dd>
      <dt>Confianza</dt>
      <dd><span id="confianza"></span><div class="barra"><i id="barra"></i></div></dd>
      <dt>Fecha y hora</dt><dd id="fecha"></dd>
      <dt>Tiempo de generación</dt><dd id="tiempo"></dd>
    </dl>
    <div class="alerta" id="alerta" style="display:none"></div>
  </div>
</main>
<footer>Trabajo de innovación UNIR · El ticket no constituye un trámite oficial.</footer>
<script>
const btn = document.getElementById('enviar');
btn.addEventListener('click', async () => {
  const texto = document.getElementById('texto').value.trim();
  const err = document.getElementById('error');
  err.textContent = '';
  document.getElementById('resultado').style.display = 'none';
  if (texto.length < 15) { err.textContent = 'Describe tu solicitud con un poco más de detalle (mínimo 15 caracteres).'; return; }
  btn.disabled = true; btn.textContent = 'Clasificando…';
  try {
    const r = await fetch('/clasificar', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({texto})
    });
    if (!r.ok) throw new Error((await r.json()).detail || 'Error del servidor');
    const t = await r.json();
    document.getElementById('folio').textContent = t.folio;
    document.getElementById('categoria').textContent = t.categoria_legible;
    document.getElementById('confianza').textContent = (t.confianza*100).toFixed(1) + ' %';
    document.getElementById('barra').style.width = (t.confianza*100) + '%';
    document.getElementById('fecha').textContent = t.fecha_hora.replace('T',' ');
    document.getElementById('tiempo').textContent = t.tiempo_ms + ' ms';
    const estado = document.getElementById('estado');
    const auto = t.estado_revision === 'automatico';
    estado.textContent = auto ? 'Canalizado automáticamente' : 'Pasará a revisión humana';
    estado.className = 'insignia ' + (auto ? 'ins-auto' : 'ins-rev');
    const al = document.getElementById('alerta');
    if (t.posible_fuera_de_alcance) {
      al.style.display = 'block';
      al.textContent = 'Tu solicitud podría no corresponder a las categorías de este prototipo; un operador la revisará y canalizará.';
    } else { al.style.display = 'none'; }
    document.getElementById('resultado').style.display = 'block';
  } catch (e) { err.textContent = e.message; }
  finally { btn.disabled = false; btn.textContent = 'Enviar reporte'; }
});
</script>
</body>
</html>"""

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8000)
