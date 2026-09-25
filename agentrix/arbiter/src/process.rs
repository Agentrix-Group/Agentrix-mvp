use std::io::{BufRead, BufReader, Write};
use std::process::{Child, ChildStdin, Command, Stdio};
use std::sync::mpsc::{channel, Receiver};
use std::thread;
use std::time::{Duration, Instant};
use serde::Deserialize;

#[derive(Clone, Copy, Debug, Default)]
pub struct Action {
    pub angle: f32,
    pub shoot: bool,
}

#[derive(Deserialize)]
struct ActionResponse {
    pub angle: Option<f32>,
    pub shoot: Option<bool>,
}

#[derive(Deserialize)]
struct ReadyResponse {
    pub status: Option<String>,
}

pub struct BotHandle {
    pub id: usize,
    child: Child,
    stdin: ChildStdin,
    rx: Receiver<String>,
    pub alive: bool,
    pub consecutive_timeouts: u32,
}

impl BotHandle {
    pub fn spawn(id: usize, cmd_str: &str) -> std::io::Result<Self> {
        let mut child = Command::new("sh")
            .arg("-c")
            .arg(cmd_str)
            .env("PYTHONUNBUFFERED", "1")
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()?;

        let stdin = child.stdin.take().expect("Failed to open child stdin");
        let stdout = child.stdout.take().expect("Failed to open child stdout");

        let (tx, rx) = channel::<String>();

        thread::Builder::new()
            .name(format!("bot-reader-{}", id))
            .spawn(move || {
                let mut reader = BufReader::new(stdout);
                let mut line = String::new();
                while let Ok(n) = reader.read_line(&mut line) {
                    if n == 0 {
                        break; // EOF
                    }
                    if tx.send(line.clone()).is_err() {
                        break;
                    }
                    line.clear();
                }
            })?;

        Ok(Self {
            id,
            child,
            stdin,
            rx,
            alive: true,
            consecutive_timeouts: 0,
        })
    }

    pub fn send(&mut self, payload: &str) -> bool {
        if !self.alive {
            return false;
        }
        if writeln!(self.stdin, "{}", payload).is_err() || self.stdin.flush().is_err() {
            self.alive = false;
            return false;
        }
        true
    }

    pub fn kill(&mut self) {
        self.alive = false;
        let _ = self.child.kill();
        let _ = self.child.wait();
    }
}

pub struct BotManager {
    pub bots: Vec<BotHandle>,
}

impl BotManager {
    pub fn new(commands: &[String]) -> Self {
        let mut bots = Vec::new();
        for (i, cmd) in commands.iter().enumerate() {
            match BotHandle::spawn(i, cmd) {
                Ok(bot) => bots.push(bot),
                Err(err) => {
                    eprintln!("Error spawning bot {}: {}", i, err);
                }
            }
        }
        Self { bots }
    }

    pub fn warmup(&mut self, timeout: Duration) -> Vec<bool> {
        let mut results = vec![false; self.bots.len()];
        let deadline = Instant::now() + timeout;

        // 1. Enviar mensaje INIT a todos
        for bot in &mut self.bots {
            let msg = serde_json::json!({
                "phase": "INIT",
                "seat": bot.id,
                "timeout_warmup_ms": timeout.as_millis(),
                "timeout_tick_ms": 50
            });
            let _ = bot.send(&msg.to_string());
        }

        // 2. Esperar respuestas READY
        for (i, bot) in self.bots.iter_mut().enumerate() {
            if !bot.alive {
                continue;
            }
            while Instant::now() < deadline {
                let rem = deadline.saturating_duration_since(Instant::now());
                match bot.rx.recv_timeout(rem) {
                    Ok(line) => {
                        if let Ok(resp) = serde_json::from_str::<ReadyResponse>(line.trim()) {
                            if resp.status.as_deref() == Some("READY") {
                                results[i] = true;
                                break;
                            }
                        }
                    }
                    Err(_) => break,
                }
            }
            if !results[i] {
                eprintln!("Bot {} falló warmup (timeout o respuesta inválida)", i);
                bot.alive = false;
            }
        }

        results
    }

    pub fn step(&mut self, observations: &[String], timeout: Duration) -> Vec<Option<Action>> {
        let n = self.bots.len();
        let mut actions = vec![None; n];

        // 1. Despacho simultáneo a todos los bots vivos
        for (i, bot) in self.bots.iter_mut().enumerate() {
            if bot.alive && i < observations.len() {
                if !bot.send(&observations[i]) {
                    actions[i] = None;
                }
            }
        }

        let deadline = Instant::now() + timeout;

        // 2. Recolectar acciones de forma no bloqueante
        for (i, bot) in self.bots.iter_mut().enumerate() {
            if !bot.alive {
                continue;
            }
            let rem = deadline.saturating_duration_since(Instant::now());
            match bot.rx.recv_timeout(rem) {
                Ok(line) => {
                    if let Ok(act) = serde_json::from_str::<ActionResponse>(line.trim()) {
                        actions[i] = Some(Action {
                            angle: act.angle.unwrap_or(0.0),
                            shoot: act.shoot.unwrap_or(false),
                        });
                        bot.consecutive_timeouts = 0;
                    } else {
                        // Respuesta no parseable
                        bot.consecutive_timeouts += 1;
                    }
                }
                Err(_) => {
                    // Timeout
                    bot.consecutive_timeouts += 1;
                }
            }

            // Si acumula 10 fallos consecutivos, marcar como muerto
            if bot.consecutive_timeouts >= 10 {
                eprintln!("Bot {} acumuló 10 timeouts y fue eliminado.", i);
                bot.alive = false;
            }
        }

        actions
    }

    pub fn terminate(&mut self) {
        for bot in &mut self.bots {
            let msg = serde_json::json!({
                "phase": "TERMINATE",
                "reason": "MATCH_ENDED"
            });
            let _ = bot.send(&msg.to_string());
        }
        thread::sleep(Duration::from_millis(200));
        for bot in &mut self.bots {
            bot.kill();
        }
    }
}

impl Drop for BotManager {
    fn drop(&mut self) {
        self.terminate();
    }
}
