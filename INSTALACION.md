# Instalación del prototipo en un ambiente nuevo

## Opción recomendada: ambiente virtual (venv)

```bash
# 1. Dentro de la carpeta del proyecto:
python3 -m venv .venv

# 2. Activar el ambiente
source .venv/bin/activate          # macOS / Linux
# .venv\Scripts\activate           # Windows (PowerShell o CMD)

# 3. Instalar dependencias exactas
pip install -r requirements.txt

# 4. Arrancar la API con la interfaz
python3 07_api.py
# → interfaz en http://127.0.0.1:8000  ·  documentación en /docs
```

Requiere Python 3.10 o superior. Para volver a usar el ambiente en otra
sesión solo repite el paso 2.

## Error conocido: `ModuleNotFoundError: No module named 'sklearn.frozen'`

Significa que el ambiente tiene scikit-learn anterior a 1.6 y no puede
deserializar los modelos guardados. Dos soluciones:

1. **Preferida:** instalar las versiones del `requirements.txt`
   (`pip install -r requirements.txt`) y volver a ejecutar.
2. **Alternativa:** si necesitas conservar otra versión de scikit-learn
   (>= 1.6 de todos modos), regenera los modelos en ese mismo ambiente —
   toma segundos y evita cualquier incompatibilidad de pickle:
   ```bash
   python3 01_preparar_corpus.py
   python3 02_baseline_tfidf.py
   python3 02b_guardian_fuera_alcance.py
   ```

Regla general: los archivos `.joblib` no son portables entre versiones
distintas de scikit-learn; ante cualquier error extraño al cargarlos,
regenéralos en el ambiente donde vayan a usarse (los CSV del corpus sí son
portables y garantizan el mismo resultado con la misma semilla).

## Qué necesita cada componente

| Componente | Archivos | Dependencias |
|---|---|---|
| Pipeline de datos y modelos | 01, 02, 02b, 08 | scikit-learn, pandas, numpy, openpyxl, matplotlib |
| Evaluación y comparación | 03, 04, 06 | scikit-learn, pandas |
| Demo CLI de tickets | 05 | scikit-learn, joblib, numpy |
| API + interfaz web | 07_api.py | fastapi, uvicorn, pydantic + los de arriba |
| Fine-tuning BETO | 03_beto_colab.ipynb | se instalan en Colab (no local) |
