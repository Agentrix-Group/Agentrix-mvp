<?php declare(strict_types=1);

namespace App\Service\Agentrix;

use App\Entity\Agentrix\ArenaGame;
use App\Entity\Agentrix\ArenaMatch;
use App\Entity\Agentrix\ArenaMatchParticipant;
use App\Entity\Contest;
use App\Entity\Problem;
use App\Entity\Submission;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MatchmakerService
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly LoggerInterface $logger
    ) {}

    /**
     * Finds all eligible teams that have at least one submission with verdict 'correct'
     * for the given arena problem, returning an array of [teamId => ['team' => Team, 'submission' => Submission]].
     *
     * @return array<int, array{team: Team, submission: Submission}>
     */
    public function getEligibleTeams(Contest $contest, Problem $problem): array
    {
        // Query latest CORRECT submission per team for this problem in this contest
        $qb = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->select('s', 't', 'j')
            ->join('s.team', 't')
            ->join('s.judgings', 'j')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.problem = :problem')
            ->andWhere('s.valid = 1')
            ->andWhere('j.valid = 1')
            ->andWhere('j.result = :result')
            ->setParameter('contest', $contest)
            ->setParameter('problem', $problem)
            ->setParameter('result', 'correct')
            ->orderBy('s.submittime', 'DESC');

        /** @var Submission[] $submissions */
        $submissions = $qb->getQuery()->getResult();

        $eligible = [];
        foreach ($submissions as $sub) {
            $teamId = $sub->getTeam()->getTeamid();
            if (!isset($eligible[$teamId])) {
                $eligible[$teamId] = [
                    'team' => $sub->getTeam(),
                    'submission' => $sub,
                ];
            }
        }

        return $eligible;
    }

    /**
     * Schedules a tournament round of matches for eligible teams.
     *
     * @param Contest $contest
     * @param Problem $problem
     * @param ArenaGame $game
     * @param int $round Round number (e.g. 1)
     * @param int $seedsCount Number of seeds/match rotations per 5-team group (default 3)
     * @return ArenaMatch[] List of created matches
     */
    public function scheduleRound(
        Contest $contest,
        Problem $problem,
        ArenaGame $game,
        int $round = 1,
        int $seedsCount = 3
    ): array {
        $eligible = $this->getEligibleTeams($contest, $problem);

        if (empty($eligible)) {
            $this->logger->warning("No eligible teams found with CORRECT bot submissions for contest {$contest->getCid()}");
            return [];
        }

        $teamEntries = array_values($eligible);
        $totalTeams = count($teamEntries);

        // Si hay menos de 5 equipos, rellenamos cíclicamente con los mismos equipos para completar 5 asientos
        $filledTeams = $teamEntries;
        while (count($filledTeams) < 5 || count($filledTeams) % 5 !== 0) {
            $filler = $teamEntries[count($filledTeams) % $totalTeams];
            $filledTeams[] = $filler;
        }

        // Dividir en grupos de 5 equipos
        $groups = array_chunk($filledTeams, 5);
        $createdMatches = [];

        $baseSeed = 2026 + ($round * 100);

        foreach ($groups as $groupIndex => $group) {
            for ($s = 0; $s < $seedsCount; $s++) {
                $seed = $baseSeed + ($groupIndex * 10) + $s;

                $match = new ArenaMatch();
                $match->setGame($game);
                $match->setContest($contest);
                $match->setRound($round);
                $match->setSeed($seed);
                $match->setStatus(ArenaMatch::STATUS_QUEUED);

                // Rotación cíclica de asientos según la semilla
                // Asiento i recibe el equipo ((i + s) % 5)
                for ($seat = 0; $seat < 5; $seat++) {
                    $teamIdx = ($seat + $s) % 5;
                    $entry = $group[$teamIdx];

                    $participant = new ArenaMatchParticipant();
                    $participant->setMatch($match);
                    $participant->setSeat($seat);
                    $participant->setTeam($entry['team']);
                    $participant->setSubmission($entry['submission']);

                    $match->addParticipant($participant);
                    $this->em->persist($participant);
                }

                $this->em->persist($match);
                $createdMatches[] = $match;
            }
        }

        $this->em->flush();
        return $createdMatches;
    }
}
