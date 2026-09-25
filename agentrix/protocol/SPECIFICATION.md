# Especificación Oficial del Protocolo de Agentes — Agentrix v1.0

**Versión:** 1.0  
**Fecha:** Septiembre 2026  
**Transporte:** Flujo estándar (`stdin` para recepción, `stdout` para emisión), delimitado por saltos de línea (`\n`).  
**Codificación:** UTF-8 en formato JSON minificado por línea.

---

## 1. Principios de Diseño
1. **Agnosticismo Total:** El motor árbitro trata al bot como una caja negra ejecutable. Puede implementarse en cualquier lenguaje (Python, C++, Rust, JavaScript, etc.).
2. **Visión Parcial Estricta:** El bot solo recibe información de entidades que se encuentren dentro de su radio de visión y sin oclusión directa por muros.
3. **Persistencia del Proceso:** El bot se arranca **una sola vez** al inicio de la partida y se mantiene vivo a lo largo de todos los ticks.

---

## 2. Fases de Comunicación

### Fase 1: Inicialización y Warm-up (`INIT`)
Al inicio de la partida, el árbitro arranca los 5 procesos y envía a cada uno por `stdin`:

```json
{"phase":"INIT","seat":0,"name":"Brasa","color":"#f07167","timeout_warmup_ms":10000,"timeout_tick_ms":50}
```

* **Plazo de respuesta:** Hasta **10.000 ms (10 segundos)**.
* **Respuesta obligatoria del bot (por `stdout`):**
```json
{"status":"READY"}
```
* **Propósito:** Permite que librerías pesadas (PyTorch, TensorFlow, ONNX Runtime) importen módulos, carguen pesos de disco a RAM y ejecuten pasadas dummy de calentamiento sin consumir tiempo de simulación.

---

### Fase 2: Simulación por Ticks (`TICK`)
En cada paso de la simulación ($DT = 1/60 \text{ s} \approx 16.66\text{ ms}$), el árbitro envía por `stdin` la observación filtrada del bot:

```json
{
  "phase": "TICK",
  "tick": 42,
  "time": 0.70,
  "you": {
    "pos": [120.5, 300.2],
    "facing": 1.57,
    "hp": 140.0,
    "max_hp": 140.0,
    "speed": 90.0,
    "vision": 190.0,
    "damage": 8.0,
    "level": 1,
    "xp": 15.0,
    "xp_next": 40.0,
    "cooldown": 0.0
  },
  "visible_enemies": [
    {"id": 2, "pos": [180.0, 310.0], "hp": 120.0, "max_hp": 120.0, "level": 1}
  ],
  "visible_mobs": [
    {"id": 5, "pos": [150.0, 280.0], "hp": 24.0, "max_hp": 24.0}
  ],
  "visible_bullets": [
    {"pos": [130.0, 290.0], "color": "#f07167"}
  ],
  "zone": {
    "radius": 680.0,
    "center": [600.0, 375.0]
  },
  "walls": [
    {"x": 100.0, "y": 200.0, "w": 120.0, "h": 25.0}
  ]
}
```

* **Plazo de respuesta:** **50 ms** a partir del momento de envío.
* **Respuesta del bot por `stdout` (una sola línea terminada en `\n`):**
```json
{"angle": 1.45, "shoot": true}
```
  - `angle`: Dirección deseada de movimiento en radianes (entre $-\pi$ y $\pi$ o $0$ y $2\pi$).
  - `shoot`: Booleano. Si es `true` y el `cooldown <= 0`, el bot dispara un proyectil en la dirección de su orientación.

---

### Fase 3: Terminación (`TERMINATE`)
Al finalizar la partida o al morir el bot, el árbitro envía:

```json
{"phase":"TERMINATE","reason":"MATCH_ENDED","placement":1,"score":85.5}
```
El bot debe cerrar limpiamente sus recursos y terminar con código de salida `0`.

---

## 3. Política de Tolerancia a Fallos y Timeouts
1. **Timeout individual (1 tick):** Si un bot no responde dentro de los 50 ms, se descarta su acción en ese tick. El bot continúa moviéndose por inercia con su dirección previa y sin disparar.
2. **Fallo acumulado:** Si un bot acumula **10 timeouts consecutivos** o su proceso finaliza inesperadamente (crasheo/OOM), el árbitro lo declara muerto (`hp = 0, alive = false`), liberando el asiento y continuando la partida para los 4 participantes restantes.
3. **Buffering en Python:** Todo script en Python debe ejecutarse con `PYTHONUNBUFFERED=1` o invocar explícitamente `sys.stdout.flush()` tras escribir cada línea JSON.

---

## 4. Estándar de Empaquetado para Envíos (.ZIP)
Cada entrega debe ser un archivo `.zip` que contenga en la raíz un script ejecutable `run.sh`:

```text
submission.zip
├── run.sh             <- Script de arranque ejecutable (chmod +x)
├── agent.py           <- Código fuente principal (o binario compilado)
└── model.onnx         <- Pesos opcionales (si usa redes neuronales)
```

**Ejemplo de `run.sh` para Python:**
```bash
#!/bin/bash
exec python3 -u agent.py
```

**Ejemplo de `run.sh` para binario compilado en C++:**
```bash
#!/bin/bash
exec ./bot_bin
```
