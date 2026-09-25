<?php declare(strict_types=1);

namespace App\Service\Agentrix;

use App\Entity\Agentrix\ArenaMatch;
use App\Entity\Agentrix\ArenaMatchParticipant;
use App\Entity\Contest;
use Doctrine\ORM\EntityManagerInterface;

class ArenaRankingService
{
    public function __construct(
        protected readonly EntityManagerInterface $em
    ) {}

    /**
     * Calculates the official Arena tournament ranking for a contest.
     * Scoring formula: 60% survival time + 40% kills.
     * Tie-breaker: Total player kills > Total survival ticks > Team ID.
     *
     * @param Contest $contest
     * @param int|null $round If specified, calculates ranking for that round only.
     * @param bool $publicView If true, respect contest freeze time.
     * @return array<int, array> Ranked list of teams with aggregated statistics.
     */
    public function calculateRanking(
        Contest $contest,
        ?int $round = null,
        bool $publicView = false
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->from(ArenaMatchParticipant::class, 'p')
            ->select('p', 'm', 't', 'g')
            ->join('p.match', 'm')
            ->join('p.team', 't')
            ->join('m.game', 'g')
            ->andWhere('m.contest = :contest')
            ->andWhere('m.status = :status')
            ->setParameter('contest', $contest)
            ->setParameter('status', ArenaMatch::STATUS_FINISHED);

        if ($round !== null) {
            $qb->andWhere('m.round = :round')
               ->setParameter('round', $round);
        }

        if ($publicView && $contest->getFreezeTime() !== null) {
            $freezeEpoch = $contest->getFreezeTime();
            $qb->andWhere('m.finishedAt <= :freezeTime')
               ->setParameter('freezeTime', (new \DateTime())->setTimestamp((int)$freezeEpoch));
        }

        /** @var ArenaMatchParticipant[] $participants */
        $participants = $qb->getQuery()->getResult();

        $teamStats = [];

        foreach ($participants as $p) {
            $team = $p->getTeam();
            $teamId = $team->getTeamid();

            if (!isset($teamStats[$teamId])) {
                $teamStats[$teamId] = [
                    'team_id' => $teamId,
                    'team_name' => $team->getName(),
                    'matches_played' => 0,
                    'matches_won' => 0,
                    'total_kills' => 0,
                    'total_mob_kills' => 0,
                    'total_survival_ticks' => 0,
                    'total_score' => 0.0,
                    'avg_score' => 0.0,
                    'places_count' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                ];
            }

            $stats = &$teamStats[$teamId];
            $stats['matches_played']++;

            $place = $p->getPlace() ?? 5;
            if (isset($stats['places_count'][$place])) {
                $stats['places_count'][$place]++;
            }
            if ($place === 1) {
                $stats['matches_won']++;
            }

            $kills = $p->getKills() ?? 0;
            $mobKills = $p->getMobKills() ?? 0;
            $stats['total_kills'] += $kills;
            $stats['total_mob_kills'] += $mobKills;

            // Calcular tiempo de supervivencia
            $gameDuration = $p->getMatch()->getGame()->getDuration();
            $maxTicks = (int)($gameDuration * 60.0);
            $survivalTicks = $p->isAlive() ? $maxTicks : ($p->getDeathTick() ?? 0);
            $stats['total_survival_ticks'] += $survivalTicks;

            // Fórmula elegida: 60% supervivencia + 40% kills
            $survivalRatio = $maxTicks > 0 ? min(1.0, $survivalTicks / $maxTicks) : 0.0;
            $killsRatio = min(1.0, $kills / 4.0); // Máximo 4 kills posibles
            $matchScore = (0.60 * $survivalRatio + 0.40 * $killsRatio) * 100.0;

            $stats['total_score'] += $matchScore;
        }

        // Calcular promedio de score y ordenar
        foreach ($teamStats as &$s) {
            if ($s['matches_played'] > 0) {
                $s['avg_score'] = round($s['total_score'] / $s['matches_played'], 2);
                $s['total_score'] = round($s['total_score'], 2);
            }
        }
        unset($s);

        // Ordenamiento por puntuación > kills > supervivencia > ID
        usort($teamStats, function (array $a, array $b): int {
            // 1. Total score desc
            if ($b['total_score'] <=> $a['total_score']) {
                return $b['total_score'] <=> $a['total_score'];
            }
            // 2. Kills totales desc
            if ($b['total_kills'] !== $a['total_kills']) {
                return $b['total_kills'] <=> $a['total_kills'];
            }
            // 3. Supervivencia total desc
            if ($b['total_survival_ticks'] !== $a['total_survival_ticks']) {
                return $b['total_survival_ticks'] <=> $a['total_survival_ticks'];
            }
            // 4. Team ID asc
            return $a['team_id'] <=> $b['team_id'];
        });

        // Asignar posición de ranking (1..N)
        $ranked = [];
        $rank = 1;
        foreach ($teamStats as $entry) {
            $entry['rank'] = $rank++;
            $ranked[] = $entry;
        }

        return $ranked;
    }
}
