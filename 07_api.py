# -*- coding: utf-8 -*-
"""
07_api.py — Prototipo funcional (E4 / sección 3.7 del documento)

Servicio FastAPI que expone el flujo completo del MVP con el MODELO BASE
(decisión de la sección 4.6): texto libre -> clasificación calibrada ->
alerta de fuera de alcance -> regla de abstención -> ticket estructurado
(Tabla 3). Incluye la interfaz web de captura y resultado (wireframes,
Figura 2) servida en la raíz.

Ejecutar:   python3 07_api.py            (o: uvicorn 07_api:app --reload)
Interfaz:   http://127.0.0.1:8000
API docs:   http://127.0.0.1:8000/docs   (Swagger generado por FastAPI)

Endpoints:
  GET  /salud       -> estado del servicio y modelos cargados
  POST /clasificar  -> {"texto": "..."} => ticket estructurado (JSON)
"""
import os, uuid, time, datetime
import joblib
import numpy as np
from fastapi import FastAPI, HTTPException
from fastapi.responses import HTMLResponse
from pydantic import BaseModel, Field

UMBRAL_ABSTENCION = 0.60
UMBRAL_GUARDIAN = 0.60
BASE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(BASE, "resultados")

modelo = joblib.load(os.path.join(RES, "modelo_baseline.joblib"))
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
    title="Clasificador de solicitudes ciudadanas CDMX",
    description="Prototipo académico (UNIR). Clasifica solicitudes en 5 "
                "categorías de servicio y genera un ticket estructurado. "
                "Modelo: TF-IDF + regresión logística calibrada.",
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

@app.get("/salud")
def salud():
    return {"estado": "ok",
            "modelo": "tfidf_logreg_calibrado",
            "guardian_activo": guardian is not None,
            "categorias": list(ETIQUETAS.values()),
            "umbral_abstencion": UMBRAL_ABSTENCION}

@app.post("/clasificar", response_model=Ticket)
def clasificar(s: Solicitud):
    texto = s.texto.strip()
    if len(texto) < 15:
        raise HTTPException(422, "El texto es demasiado corto para clasificarse.")
    t0 = time.perf_counter()
    p = modelo.predict_proba([texto])[0]
    i = int(np.argmax(p)); confianza = float(p[i])
    categoria = str(modelo.classes_[i])
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
        metadatos={"modelo": "tfidf_logreg_calibrado",
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
<title>Reporte Ciudadano CDMX — Prototipo</title>
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
</style>
</head>
<body>
<header>
  <h1>Reporte Ciudadano CDMX</h1>
  <p>Prototipo académico — clasificación automática y generación de ticket</p>
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
