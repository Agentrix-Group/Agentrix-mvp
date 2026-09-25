#!/usr/bin/env python3
"""
Agentrix Match Runner Daemon
Orchestrates isolated 5-player Arena matches with CPU core pinning and RAM limits.
"""

import argparse
import gzip
import hashlib
import json
import logging
import os
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import zipfile
from pathlib import Path
from typing import Dict, List, Optional, Tuple

try:
    import pymysql
except ImportError:
    print("Error: pymysql is required. Install with: pip install pymysql", file=sys.stderr)
    sys.exit(1)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S"
)
logger = logging.getLogger("agentrix-runner")


class MatchRunner:
    def __init__(
        self,
        db_config: Dict,
        arbiter_bin: str,
        replays_dir: str,
        poll_interval: float = 1.0,
        ram_limit_bytes: int = 2 * 1024 * 1024 * 1024,  # 2 GB
    ):
        self.db_config = db_config
        self.arbiter_bin = str(Path(arbiter_bin).resolve())
        self.replays_dir = str(Path(replays_dir).resolve())
        self.poll_interval = poll_interval
        self.ram_limit_bytes = ram_limit_bytes
        self.hostname = socket.gethostname()
        self.running = True

        os.makedirs(self.replays_dir, exist_ok=True)

        if not os.path.isfile(self.arbiter_bin) or not os.access(self.arbiter_bin, os.X_OK):
            raise FileNotFoundError(f"Árbitro no encontrado o sin permisos de ejecución en: {self.arbiter_bin}")

        signal.signal(signal.SIGINT, self._handle_signal)
        signal.signal(signal.SIGTERM, self._handle_signal)

    def _handle_signal(self, signum, frame):
        logger.info(f"Señal recibida ({signum}). Deteniendo el runner ordenadamente...")
        self.running = False

    def get_connection(self):
        return pymysql.connect(
            host=self.db_config["host"],
            port=self.db_config["port"],
            user=self.db_config["user"],
            password=self.db_config["password"],
            database=self.db_config["database"],
            autocommit=False,
            cursorclass=pymysql.cursors.DictCursor
        )

    def poll_next_match(self) -> Optional[Dict]:
        """Busca y reclama atómicamente la próxima partida en cola."""
        conn = self.get_connection()
        try:
            with conn.cursor() as cursor:
                # Buscar partida queued más antigua
                cursor.execute(
                    "SELECT m.matchid, m.gameid, m.cid, m.seed, m.round, g.duration "
                    "FROM arena_match m "
                    "JOIN arena_game g ON m.gameid = g.gameid "
                    "WHERE m.status = 'queued' "
                    "ORDER BY m.matchid ASC LIMIT 1 "
                    "FOR UPDATE"
                )
                match = cursor.fetchone()
                if not match:
                    conn.rollback()
                    return None

                match_id = match["matchid"]
                # Reclamar atómicamente la partida
                cursor.execute(
                    "UPDATE arena_match "
                    "SET status = 'running', started_at = NOW(), worker_host = %s "
                    "WHERE matchid = %s AND status = 'queued'",
                    (self.hostname, match_id)
                )
                if cursor.rowcount == 1:
                    conn.commit()
                    return match
                else:
                    conn.rollback()
                    return None
        finally:
            conn.close()

    def load_participants(self, match_id: int) -> List[Dict]:
        conn = self.get_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT p.participantid, p.seat, p.teamid, p.submitid, t.name as team_name "
                    "FROM arena_match_participant p "
                    "JOIN team t ON p.teamid = t.teamid "
                    "WHERE p.matchid = %s "
                    "ORDER BY p.seat ASC",
                    (match_id,)
                )
                return cursor.fetchall()
        finally:
            conn.close()

    def fetch_submission_zip(self, submit_id: int, target_zip_path: str):
        conn = self.get_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT sourcecode FROM submission_file WHERE submitid = %s LIMIT 1",
                    (submit_id,)
                )
                row = cursor.fetchone()
                if not row or not row["sourcecode"]:
                    raise ValueError(f"No se encontró archivo de código para submitid {submit_id}")

                with open(target_zip_path, "wb") as f:
                    f.write(row["sourcecode"])
        finally:
            conn.close()

    def unpack_bot(self, zip_path: str, extract_dir: str):
        os.makedirs(extract_dir, exist_ok=True)
        with zipfile.ZipFile(zip_path, "r") as zf:
            zf.extractall(extract_dir)

        run_script = os.path.join(extract_dir, "run.sh")
        if not os.path.isfile(run_script):
            raise FileNotFoundError(f"run.sh no encontrado en el paquete del bot ({extract_dir})")

        os.chmod(run_script, 0o755)

    def execute_match(self, match: Dict, participants: List[Dict]):
        match_id = match["matchid"]
        seed = match["seed"]
        duration = match["duration"]
        logger.info(f"▶ Ejecutando partida #{match_id} (Semilla: {seed}, Duración: {duration}s, Participantes: {len(participants)})")

        work_dir = tempfile.mkdtemp(prefix=f"agentrix_match_{match_id}_")
        bot_cmds = []

        try:
            # Descomprimir y preparar los 5 bots
            for p in participants:
                seat = p["seat"]
                submit_id = p["submitid"]
                team_name = p["team_name"]

                seat_dir = os.path.join(work_dir, f"seat_{seat}")
                zip_path = os.path.join(work_dir, f"bot_{seat}.zip")

                logger.info(f"  Preparando Asiento {seat}: {team_name} (submit #{submit_id})")
                self.fetch_submission_zip(submit_id, zip_path)
                self.unpack_bot(zip_path, seat_dir)

                run_sh = os.path.abspath(os.path.join(seat_dir, "run.sh"))
                core_id = seat + 1  # Asiento 0 -> Core 1, Asiento 1 -> Core 2, etc.

                # Comando con afinidad de CPU y límite de memoria
                bot_cmd = (
                    f"cd {seat_dir} && "
                    f"taskset -c {core_id} prlimit --as={self.ram_limit_bytes} {run_sh}"
                )
                bot_cmds.append(bot_cmd)

            results_path = os.path.join(work_dir, "results.json")
            replay_path = os.path.join(work_dir, "replay.json")

            # Construir comando del árbitro
            arbiter_cmd = [
                "taskset", "-c", "0",
                self.arbiter_bin,
                "--b0", bot_cmds[0],
                "--b1", bot_cmds[1],
                "--b2", bot_cmds[2],
                "--b3", bot_cmds[3],
                "--b4", bot_cmds[4],
                "--seed", str(seed),
                "--duration", str(duration),
                "--out-results", results_path,
                "--out-replay", replay_path,
            ]

            logger.info(f"  Lanzando agentrix-arbiter en núcleo 0...")
            start_time = time.time()
            proc = subprocess.run(
                arbiter_cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                cwd=work_dir,
                timeout=duration + 60.0
            )
            elapsed = time.time() - start_time

            if proc.returncode != 0:
                error_msg = f"Arbiter exited with code {proc.returncode}:\n{proc.stderr}\n{proc.stdout}"
                logger.error(f"✖ Error ejecutando partida #{match_id}: {error_msg}")
                self._record_failure(match_id, error_msg)
                return

            logger.info(f"✔ Partida #{match_id} completada en {elapsed:.2f}s de tiempo real.")

            with open(results_path, "r", encoding="utf-8") as f:
                results_data = json.load(f)

            with open(replay_path, "rb") as f:
                replay_raw_bytes = f.read()

            self._record_success(match_id, results_data, replay_raw_bytes, participants)

        except Exception as e:
            logger.exception(f"✖ Excepción fatal ejecutando partida #{match_id}: {e}")
            self._record_failure(match_id, str(e))
        finally:
            shutil.rmtree(work_dir, ignore_errors=True)

    def _record_success(
        self,
        match_id: int,
        results_data: Dict,
        replay_bytes: bytes,
        participants: List[Dict]
    ):
        # 1. Comprimir y guardar replay en disco
        replay_hash = hashlib.sha256(replay_bytes).hexdigest()
        compressed_replay = gzip.compress(replay_bytes, compresslevel=6)

        replay_filename = f"match_{match_id}.json.gz"
        replay_full_path = os.path.join(self.replays_dir, replay_filename)
        with open(replay_full_path, "wb") as f:
            f.write(compressed_replay)

        relative_path = os.path.relpath(replay_full_path)
        ticks = results_data.get("ticks", 0)
        sim_duration = results_data.get("duration", 0.0)

        conn = self.get_connection()
        try:
            with conn.cursor() as cursor:
                # 2. Actualizar participantes
                # Mapear ranking por id de asiento
                ranking_map = {r["id"]: r for r in results_data.get("ranking", [])}
                players_map = {p["id"]: p for p in results_data.get("players", [])}

                for p in participants:
                    seat = p["seat"]
                    pid = p["participantid"]
                    r_info = ranking_map.get(seat, {})
                    p_info = players_map.get(seat, {})

                    cursor.execute(
                        "UPDATE arena_match_participant SET "
                        "place = %s, score = %s, kills = %s, mob_kills = %s, alive = %s, death_tick = %s "
                        "WHERE participantid = %s",
                        (
                            r_info.get("place", None),
                            r_info.get("score", None),
                            r_info.get("kills", None),
                            p_info.get("mob_kills", 0),
                            1 if p_info.get("alive", False) else 0,
                            p_info.get("death_tick", None),
                            pid
                        )
                    )

                # 3. Guardar metadatos en arena_replay
                cursor.execute(
                    "INSERT INTO arena_replay "
                    "(matchid, file_path, file_size, file_hash, ticks, duration_seconds, created_at) "
                    "VALUES (%s, %s, %s, %s, %s, %s, NOW()) "
                    "ON DUPLICATE KEY UPDATE "
                    "file_path = VALUES(file_path), file_size = VALUES(file_size), "
                    "file_hash = VALUES(file_hash), ticks = VALUES(ticks), "
                    "duration_seconds = VALUES(duration_seconds)",
                    (
                        match_id,
                        relative_path,
                        len(compressed_replay),
                        replay_hash,
                        ticks,
                        sim_duration
                    )
                )

                # 4. Marcar partida como finished
                cursor.execute(
                    "UPDATE arena_match SET status = 'finished', finished_at = NOW() WHERE matchid = %s",
                    (match_id,)
                )

                conn.commit()
                logger.info(f"✔ Resultados y Replay guardados atómicamente para partida #{match_id} (Replay: {len(compressed_replay)} bytes comprimidos)")
        except Exception:
            conn.rollback()
            raise
        finally:
            conn.close()

    def _record_failure(self, match_id: int, error_msg: str):
        conn = self.get_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "UPDATE arena_match SET status = 'failed', error_message = %s, finished_at = NOW() WHERE matchid = %s",
                    (error_msg[:65535], match_id)
                )
                conn.commit()
        finally:
            conn.close()

    def run_once(self) -> bool:
        """Procesa una sola partida y retorna True si hubo partida procesada, False si la cola estaba vacía."""
        match = self.poll_next_match()
        if not match:
            return False
        participants = self.load_participants(match["matchid"])
        if len(participants) < 5:
            error_msg = f"Partida #{match['matchid']} tiene solo {len(participants)} participantes (se requieren 5)."
            logger.error(error_msg)
            self._record_failure(match["matchid"], error_msg)
            return True
        self.execute_match(match, participants)
        return True

    def run_forever(self):
        logger.info(f"=== AGENTRIX MATCH RUNNER ACTIVO ===")
        logger.info(f"Worker host: {self.hostname}")
        logger.info(f"Base de datos: {self.db_config['host']}:{self.db_config['port']}/{self.db_config['database']}")
        logger.info(f"Árbitro: {self.arbiter_bin}")
        logger.info(f"Directorio de Replays: {self.replays_dir}")
        logger.info(f"Límite RAM por bot: {self.ram_limit_bytes / (1024**3):.1f} GB")
        logger.info(f"Esperando partidas en cola...")

        while self.running:
            try:
                if not self.run_once():
                    time.sleep(self.poll_interval)
            except Exception as e:
                logger.exception(f"Error en bucle de polling: {e}")
                time.sleep(self.poll_interval)

        logger.info("Runner finalizado con éxito.")


def main():
    parser = argparse.ArgumentParser(description="Agentrix Match Runner Daemon")
    parser.add_argument("--db-host", default=os.getenv("DB_HOST", "127.0.0.1"), help="MariaDB host")
    parser.add_argument("--db-port", type=int, default=int(os.getenv("DB_PORT", "13306")), help="MariaDB port")
    parser.add_argument("--db-user", default=os.getenv("DB_USER", "domjudge"), help="MariaDB user")
    parser.add_argument("--db-pass", default=os.getenv("DB_PASS", "domjudge"), help="MariaDB password")
    parser.add_argument("--db-name", default=os.getenv("DB_NAME", "domjudge"), help="MariaDB database")
    parser.add_argument("--arbiter", default="agentrix/arbiter/target/release/agentrix-arbiter", help="Ruta al binario agentrix-arbiter")
    parser.add_argument("--replays-dir", default="var/agentrix/replays", help="Directorio donde guardar replays")
    parser.add_argument("--poll-interval", type=float, default=1.0, help="Intervalo de sondeo en segundos")
    parser.add_argument("--once", action="store_true", help="Procesa una sola partida si existe y sale")

    args = parser.parse_args()

    db_config = {
        "host": args.db_host,
        "port": args.db_port,
        "user": args.db_user,
        "password": args.db_pass,
        "database": args.db_name
    }

    runner = MatchRunner(
        db_config=db_config,
        arbiter_bin=args.arbiter,
        replays_dir=args.replays_dir,
        poll_interval=args.poll_interval
    )
    if args.once:
        processed = runner.run_once()
        logger.info(f"Ejecución única finalizada. Partida procesada: {processed}")
    else:
        runner.run_forever()


if __name__ == "__main__":
    main()
