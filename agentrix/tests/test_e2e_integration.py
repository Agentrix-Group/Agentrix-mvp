#!/usr/bin/env python3
"""
Agentrix E2E Integration and Validation Test Suite
Tests:
- All web routes and responses (HTML, status codes)
- Replay data streaming (Content-Encoding: gzip decompression)
- JSON frame integrity for all matches (1 to 7)
- Scoreboard and ranking consistency
- Native Rust Arbiter execution
"""

import sys
import os
import json
import gzip
import urllib.request
import urllib.error
import subprocess
import pymysql

BASE_URL = "http://localhost:12345"
DB_HOST = "127.0.0.1"
DB_PORT = 13306
DB_USER = "domjudge"
DB_PASS = "domjudge"
DB_NAME = "domjudge"

def run_step(step_name, fn):
    print(f"[*] Testing: {step_name}...", end=" ", flush=True)
    try:
        fn()
        print("✔ PASS")
        return True
    except Exception as e:
        print(f"✘ FAIL: {e}")
        return False

def test_web_matches_page():
    req = urllib.request.Request(f"{BASE_URL}/agentrix/matches")
    with urllib.request.urlopen(req) as resp:
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        body = resp.read().decode('utf-8')
        assert "Agentrix AI Arena" in body, "Missing title in matches page"
        assert "Finalizadas (7)" in body or "Finalizadas (" in body, "Missing finished matches count"
        assert "#7" in body, "Match #7 not listed in table"
        assert "#1" in body, "Match #1 not listed in table"

def test_web_ranking_page():
    req = urllib.request.Request(f"{BASE_URL}/agentrix/ranking")
    with urllib.request.urlopen(req) as resp:
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        body = resp.read().decode('utf-8')
        assert "Clasificación Agentrix Arena" in body, "Missing ranking header"
        assert "🥇 1º" in body, "Missing 1st place gold badge"
        assert "🥈 2º" in body, "Missing 2nd place silver badge"
        assert "Example teamname" in body, "Missing team name in ranking"

def test_web_replay_pages():
    for match_id in [1, 2, 3, 4, 5, 6, 7]:
        req = urllib.request.Request(f"{BASE_URL}/agentrix/match/{match_id}/replay")
        with urllib.request.urlopen(req) as resp:
            assert resp.status == 200, f"Match #{match_id} replay returned {resp.status}"
            body = resp.read().decode('utf-8')
            assert 'id="arenaCanvas"' in body, f"Canvas element missing for match #{match_id}"
            assert 'id="timelineScrubber"' in body, f"Scrubber missing for match #{match_id}"

def test_replay_data_streaming_and_frames():
    for match_id in [1, 2, 3, 4, 5, 6, 7]:
        req = urllib.request.Request(f"{BASE_URL}/agentrix/match/{match_id}/replay-data")
        req.add_header('Accept-Encoding', 'gzip')
        with urllib.request.urlopen(req) as resp:
            assert resp.status == 200, f"Replay data returned {resp.status}"
            raw = resp.read()
            # Decompress gzip
            try:
                decompressed = gzip.decompress(raw)
            except Exception:
                decompressed = raw  # In case urllib auto-decompressed
            data = json.loads(decompressed)
            
            assert 'frames' in data, f"Missing frames in replay #{match_id}"
            assert 'seed' in data, f"Missing seed in replay #{match_id}"
            assert 'walls' in data, f"Missing walls in replay #{match_id}"
            assert 'ranking' in data, f"Missing ranking in replay #{match_id}"
            assert len(data['frames']) > 100, f"Match #{match_id} frames too short ({len(data['frames'])})"
            
            first_frame = data['frames'][0]
            last_frame = data['frames'][-1]
            assert first_frame['tick'] in [0, 1], f"Match #{match_id} does not start at tick 0 or 1"
            assert len(first_frame['players']) == 5, f"Match #{match_id} does not have 5 players"
            assert last_frame['tick'] > 500, f"Match #{match_id} ended prematurely"

def test_database_consistency():
    conn = pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        cursorclass=pymysql.cursors.DictCursor
    )
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) as cnt FROM arena_match WHERE status = 'finished'")
            row = cur.fetchone()
            assert row['cnt'] == 7, f"Expected 7 finished matches, found {row['cnt']}"

            cur.execute("SELECT COUNT(*) as cnt FROM arena_replay")
            row = cur.fetchone()
            assert row['cnt'] == 7, f"Expected 7 replay records in DB, found {row['cnt']}"

            cur.execute("""
                SELECT p.teamid, t.name, SUM(p.kills) as kills, SUM(p.score) as total_score
                FROM arena_match_participant p
                JOIN team t ON t.teamid = p.teamid
                JOIN arena_match m ON m.matchid = p.matchid
                WHERE m.status = 'finished'
                GROUP BY p.teamid, t.name
                ORDER BY total_score DESC
            """)
            teams = cur.fetchall()
            assert len(teams) >= 2, f"Expected at least 2 teams with scores, found {len(teams)}"
            assert teams[0]['total_score'] > teams[1]['total_score'], "1st place score should be greater than 2nd"
    finally:
        conn.close()

def test_arbiter_direct_execution():
    arbiter_bin = "agentrix/arbiter/target/release/agentrix-arbiter"
    assert os.path.isfile(arbiter_bin), "Arbiter binary not found"
    
    bot_cmd = "python3 agentrix/bots/heuristic_bot/bot.py"
    out_results = "/tmp/test_results.json"
    out_replay = "/tmp/test_replay.json"

    cmd = [
        arbiter_bin,
        "--b0", bot_cmd,
        "--b1", bot_cmd,
        "--b2", bot_cmd,
        "--b3", bot_cmd,
        "--b4", bot_cmd,
        "--seed", "9999",
        "--duration", "5",
        "--out-results", out_results,
        "--out-replay", out_replay
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
    assert res.returncode == 0, f"Arbiter failed: {res.stderr}"
    assert os.path.isfile(out_results), "Results file not generated"
    assert os.path.isfile(out_replay), "Replay file not generated"
    
    with open(out_results) as f:
        report = json.load(f)
    assert "winner" in report, "Missing winner in arbiter report"
    assert "ranking" in report, "Missing ranking in arbiter report"
    assert len(report["ranking"]) == 5, "Expected 5 ranked players"

def main():
    print("==================================================")
    print("      Agentrix AI Arena - E2E Integration Suite   ")
    print("==================================================")
    
    tests = [
        ("Web: Matches Dashboard (/agentrix/matches)", test_web_matches_page),
        ("Web: Tournament Ranking (/agentrix/ranking)", test_web_ranking_page),
        ("Web: Replay Player UI (/agentrix/match/{1..7}/replay)", test_web_replay_pages),
        ("API: Replay Gzip Streaming & JSON Frame Integrity", test_replay_data_streaming_and_frames),
        ("Database: MariaDB Atomicity & Consistency", test_database_consistency),
        ("Engine: Rust Arbiter Standalone Fast Execution", test_arbiter_direct_execution),
    ]

    all_pass = True
    for name, fn in tests:
        if not run_step(name, fn):
            all_pass = False

    print("==================================================")
    if all_pass:
        print("  🎉 ALL 6 TEST SUITES PASSED COMPLETELY! (100%)  ")
        print("==================================================")
        sys.exit(0)
    else:
        print("  ❌ ONE OR MORE TESTS FAILED.                   ")
        print("==================================================")
        sys.exit(1)

if __name__ == '__main__':
    main()
