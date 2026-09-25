use crate::model::{Player, Rank};

pub fn calculate(players: &[Player], mobs_as_kills: bool, final_result: bool) -> Vec<Rank> {
    let n = players.len();
    if n < 2 {
        return vec![];
    }
    let alive = players.iter().filter(|p| p.alive).count();
    let mut alive_ids: Vec<usize> = players
        .iter()
        .enumerate()
        .filter(|(_, p)| p.alive)
        .map(|(i, _)| i)
        .collect();

    if final_result {
        alive_ids.sort_by(|a, b| {
            players[*b]
                .hp
                .total_cmp(&players[*a].hp)
                .then(a.cmp(b))
        });
    }

    let mut dead_ids: Vec<usize> = players
        .iter()
        .enumerate()
        .filter(|(_, p)| !p.alive)
        .map(|(i, _)| i)
        .collect();

    dead_ids.sort_by(|a, b| {
        players[*b]
            .death_tick
            .cmp(&players[*a].death_tick)
            .then(a.cmp(b))
    });

    let mut places = vec![1usize; n];
    for (i, id) in alive_ids.iter().enumerate() {
        places[*id] = if i > 0 && (players[*id].hp - players[alive_ids[i - 1]].hp).abs() < 1e-6 {
            places[alive_ids[i - 1]]
        } else if final_result {
            i + 1
        } else {
            1
        };
    }

    for (i, id) in dead_ids.iter().enumerate() {
        places[*id] = if i > 0 && players[*id].death_tick == players[dead_ids[i - 1]].death_tick {
            places[dead_ids[i - 1]]
        } else {
            alive + i + 1
        };
    }

    let kills: Vec<u32> = players
        .iter()
        .map(|p| p.kills + if mobs_as_kills { p.mob_kills } else { 0 })
        .collect();
    let max_kills = kills.iter().copied().max().unwrap_or(0);

    let mut result: Vec<Rank> = players
        .iter()
        .enumerate()
        .map(|(i, _)| {
            let kill_part = if max_kills > 0 {
                kills[i] as f32 / max_kills as f32
            } else {
                0.0
            };
            let survival_part = (n - places[i]) as f32 / (n - 1) as f32;
            Rank {
                id: i,
                kills: kills[i],
                place: places[i],
                kill_part,
                survival_part,
                score: 60.0 * kill_part + 40.0 * survival_part,
            }
        })
        .collect();

    // Precalcular marcas de tiempo de última kill para evitar clones en el comparador
    let reached_times: Vec<u32> = (0..n)
        .map(|id| {
            let p = &players[id];
            let mut times = p.kill_times.clone();
            if mobs_as_kills {
                times.extend(&p.mob_kill_times);
                times.sort_unstable();
            }
            times.last().copied().unwrap_or(u32::MAX)
        })
        .collect();

    result.sort_by(|a, b| {
        b.score
            .total_cmp(&a.score)
            .then_with(|| reached_times[a.id].cmp(&reached_times[b.id]))
            .then(a.place.cmp(&b.place))
            .then(a.id.cmp(&b.id))
    });

    result
}
