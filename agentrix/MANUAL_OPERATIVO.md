# Agentrix AI Arena - Manual de Operación y Arquitectura

Bienvenido a la guía técnica y operativa de **Agentrix AI Arena**, la extensión para torneos de agentes autónomos y bots de IA construida sobre **DOMjudge 9.0.1**.

---

## 1. Arquitectura General del Sistema

El sistema opera desacoplado de las funciones ICPC tradicionales de DOMjudge para garantizar estabilidad y rendimiento:

```
                  ┌────────────────────────────────────────────────────────┐
                  │                    DOMjudge 9.0.1                      │
                  │                                                        │
                  │   [Jury / Teams UI]        [Judgehost ICPC]            │
                  │          │                        │                    │
                  │          ▼                        ▼                    │
                  │  ArenaController        Sanity Check (5s INIT)         │
                  │  Matches / Replays /    (Valida ejecutable run.sh      │
                  │  Scoreboard Ranking     y pesos de ONNX/C++/Python)    │
                  └──────────┬────────────────────────┬────────────────────┘
                             │                        │
                             ▼                        ▼
                  ┌────────────────────┐   ┌───────────────────────────────┐
                  │ MariaDB 11.4+      │   │ submission_file (BLOB 100 MB) │
                  │ arena_game         │   └──────────────┬────────────────┘
                  │ arena_match        │                  │
                  │ arena_participant  │                  │ Unpack & Isol
                  │ arena_replay       │                  ▼
                  └──────────▲─────────┘   ┌───────────────────────────────┐
                             │             │  Agentrix Match Runner        │
                             │ Report      │  - taskset CPU cores (0..5)   │
                             │ results     │  - prlimit 2 GB RAM por bot   │
                             │             │  - line-based JSON IPC stdin  │
                             │             └──────────────┬────────────────┘
                             │                            │
                             │                            ▼
                             │             ┌───────────────────────────────┐
                             │             │  Native Rust Arbiter          │
                             │             │  Simulación a 60 FPS          │
                             │             │  (27x en tiempo real)         │
                             └─────────────┼───────────────────────────────┘
                                           │
                                           ▼ Genera Replay
                                  var/agentrix/replays/
                                   match_{id}.json.gz
```

---

## 2. Componentes Implementados

### 2.1. Árbitro Nativo en Rust (`agentrix/arbiter/`)
- Simula la arena a 60 FPS con mapa de 1200×750 unidades, muros aleatorios según semilla, mobs neutrales hostiles, proyectiles y zona segura que se reduce.
- Protocolo agnóstico por `stdin`/`stdout`:
  - `INIT` -> `READY`
  - `STATE` (frame con posición, vida, mobs visibles, proyectiles)
  - `ACTION` (`move_x`, `move_y`, `shoot_x`, `shoot_y`)
- Genera reporte final JSON de puntuaciones y un archivo completo de repetición fotograma a fotograma (`frames`).

### 2.2. Ejecutor Aislado (`agentrix/runner/runner.py`)
- Monitorea la cola de partidas (`status = 'queued'`).
- Desempaqueta los archivos ZIP de las últimas entregas correctas de cada equipo.
- Aplica aislamiento estricto de recursos:
  - **Afinidad de núcleos CPU**: `taskset -c 0` para el árbitro, `taskset -c 1..5` para cada uno de los 5 bots.
  - **Límite de memoria**: `prlimit --as=2147483648` (2 GB RAM virtual máxima por proceso bot).
- Comprime el archivo de repetición con Gzip en `var/agentrix/replays/match_{id}.json.gz`.
- Actualiza atómicamente la base de datos (puntuaciones, kills, supervivencia, estado).

### 2.3. Validador Sanity Check (`zip` language)
- Problema `agentrix_arena_bot` (Problema D).
- Admite paquetes `.zip` de hasta 100 MB con `run.sh` ejecutable en la raíz.
- Ejecuta una prueba de handshake de 5 segundos (`INIT` -> `READY`) con mock inputs. Si el bot crashea, no tiene permisos de ejecución, le faltan pesos ONNX o no responde en 5 segundos, la entrega es rechazada como `COMPILER-ERROR`.

### 2.4. Matchmaking y Scoring (`MatchmakerService` & `ArenaRankingService`)
- Emparejamiento por grupos de 5 equipos con rotación cíclica de asientos a través de 3 semillas pseudoaleatorias para neutralizar la ventaja de posición inicial.
- Fórmula oficial: **60 % tiempo de supervivencia (ticks) + 40 % bajas directas (kills)**. Desempate por: kills totales > supervivencia > ID de equipo.

### 2.5. Interfaz Web y Reproductor Canvas 2D
- **Rutas Web**:
  - `/agentrix/matches`: Tablero de partidas con filtros por estado y ronda, indicadores en tiempo real y acciones de reinicio.
  - `/agentrix/ranking`: Scoreboard oficial del torneo con insignias 🥇, 🥈, 🥉 y desglose de puntos.
  - `/agentrix/match/{id}/replay`: Reproductor interactivo en HTML5 Canvas 2D con scrubber de fotogramas, velocidad ajustable (0.5x, 1x, 2x, 4x), barras de vida en vivo, barra de XP y registro de combate.
  - `/agentrix/match/{id}/replay-data`: Streaming nativo comprimido en gzip (`Content-Encoding: gzip`).
- **Navegación**: Menús integrados en Jury (`arena` dropdown), Teams (`Arena` y `Ranking Arena`) y acceso público total.

---

## 3. Comandos Útiles de Consola (Jury / Operaciones)

Todos los comandos pueden ejecutarse directamente en el contenedor o con `docker compose exec domjudge`:

```bash
# 1. Configurar o reinstalar el compilador y lenguaje ZIP
php webapp/bin/console agentrix:setup-language

# 2. Crear o actualizar el problema de la arena (Problema D en Contest 1)
php webapp/bin/console agentrix:create-problem

# 3. Programar una nueva ronda del torneo (Ronda 1, 2, 3...)
php webapp/bin/console agentrix:schedule-round --round=1 --seeds=3

# 4. Ver el ranking oficial en consola
php webapp/bin/console agentrix:ranking

# 5. Ejecutar el demonio runner de partidas en segundo plano
python3 agentrix/runner/runner.py --poll-interval 2
```

---

## 4. Guía para Participantes (Cómo enviar un Bot)

1. Crear un script `run.sh` en la raíz de su proyecto con permisos de ejecución:
   ```bash
   #!/bin/bash
   # Ejemplo para bot Python
   python3 bot.py
   ```
2. El bot debe leer líneas JSON por `stdin` y escribir líneas JSON por `stdout` con flush inmediato (`sys.stdout.flush()`).
3. Empaquetar todo el directorio en un archivo ZIP (hasta 100 MB de modelos/pesos):
   ```bash
   zip -r mi_bot.zip run.sh bot.py weights.onnx model/
   ```
4. Subir `mi_bot.zip` a DOMjudge seleccionando el problema **Agentrix Arena Bot (D)** y lenguaje **Agentrix Bot Package (zip)**.
5. El juez responderá `CORRECT` si supera la prueba de inicialización (5s).
6. Una vez validado, el bot participará automáticamente en todas las rondas programadas por el jurado.
