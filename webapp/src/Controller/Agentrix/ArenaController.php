<?php declare(strict_types=1);

namespace App\Controller\Agentrix;

use App\Controller\BaseController;
use App\Entity\Agentrix\ArenaGame;
use App\Entity\Agentrix\ArenaMatch;
use App\Entity\Agentrix\ArenaMatchParticipant;
use App\Entity\Agentrix\ArenaReplay;
use App\Entity\Contest;
use App\Entity\Problem;
use App\Service\Agentrix\ArenaRankingService;
use App\Service\Agentrix\MatchmakerService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/agentrix')]
class ArenaController extends BaseController
{
    public function __construct(
        EntityManagerInterface $em,
        EventLogService $eventLog,
        DOMJudgeService $dj,
        KernelInterface $kernel,
        protected readonly ArenaRankingService $rankingService,
        protected readonly MatchmakerService $matchmakerService
    ) {
        parent::__construct($em, $eventLog, $dj, $kernel);
    }

    #[Route('', name: 'agentrix_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('agentrix_matches');
    }

    /**
     * Lists Arena matches with filtering by status and round.
     */
    #[Route('/matches', name: 'agentrix_matches', methods: ['GET'])]
    public function matches(Request $request): Response
    {
        $contest = $this->dj->getCurrentContest();
        if (!$contest) {
            $contests = $this->dj->getCurrentContests();
            $contest = $contests[0] ?? null;
        }

        $statusFilter = $request->query->get('status', 'all');
        $roundFilter = $request->query->get('round', 'all');

        $qb = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('m', 'p', 't', 'r', 'g')
            ->leftJoin('m.participants', 'p')
            ->leftJoin('p.team', 't')
            ->leftJoin('m.replay', 'r')
            ->leftJoin('m.game', 'g')
            ->orderBy('m.matchid', 'DESC');

        if ($contest) {
            $qb->andWhere('m.contest = :contest')
               ->setParameter('contest', $contest);
        }

        if ($statusFilter !== 'all') {
            $qb->andWhere('m.status = :status')
               ->setParameter('status', $statusFilter);
        }

        if ($roundFilter !== 'all' && is_numeric($roundFilter)) {
            $qb->andWhere('m.round = :round')
               ->setParameter('round', (int)$roundFilter);
        }

        /** @var ArenaMatch[] $matches */
        $matches = $qb->getQuery()->getResult();

        // Get available rounds for dropdown filter
        $roundsQb = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('DISTINCT m.round')
            ->orderBy('m.round', 'ASC');
        if ($contest) {
            $roundsQb->where('m.contest = :contest')
                     ->setParameter('contest', $contest);
        }
        $availableRounds = array_column($roundsQb->getQuery()->getScalarResult(), 'round');

        // Match statistics counts
        $statusCounts = [
            'all' => 0,
            'queued' => 0,
            'running' => 0,
            'finished' => 0,
            'failed' => 0,
        ];
        $allMatchesQb = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('m.status, COUNT(m.matchid) as cnt')
            ->groupBy('m.status');
        if ($contest) {
            $allMatchesQb->where('m.contest = :contest')
                         ->setParameter('contest', $contest);
        }
        foreach ($allMatchesQb->getQuery()->getResult() as $row) {
            $statusCounts[$row['status']] = (int)$row['cnt'];
            $statusCounts['all'] += (int)$row['cnt'];
        }

        return $this->render('agentrix/matches.html.twig', [
            'contest' => $contest,
            'matches' => $matches,
            'statusFilter' => $statusFilter,
            'roundFilter' => $roundFilter,
            'availableRounds' => $availableRounds,
            'statusCounts' => $statusCounts,
        ]);
    }

    /**
     * Arena Replay Web Player interface.
     */
    #[Route('/match/{matchId}/replay', name: 'agentrix_match_replay', methods: ['GET'])]
    public function replay(int $matchId): Response
    {
        /** @var ArenaMatch|null $match */
        $match = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('m', 'p', 't', 'r', 'g')
            ->leftJoin('m.participants', 'p')
            ->leftJoin('p.team', 't')
            ->leftJoin('m.replay', 'r')
            ->leftJoin('m.game', 'g')
            ->where('m.matchid = :id')
            ->setParameter('id', $matchId)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$match) {
            throw $this->createNotFoundException(sprintf('Partida #%d no encontrada.', $matchId));
        }

        return $this->render('agentrix/replay.html.twig', [
            'match' => $match,
            'participants' => $match->getParticipants(),
            'replay' => $match->getReplay(),
            'replayDataUrl' => $this->generateUrl('agentrix_match_replay_data', ['matchId' => $matchId]),
        ]);
    }

    /**
     * Streams gzip-compressed replay JSON data.
     * With Content-Encoding: gzip, the browser automatically transparently uncompresses it.
     */
    #[Route('/match/{matchId}/replay-data', name: 'agentrix_match_replay_data', methods: ['GET'])]
    public function replayData(int $matchId): Response
    {
        /** @var ArenaMatch|null $match */
        $match = $this->em->getRepository(ArenaMatch::class)->find($matchId);
        if (!$match) {
            throw $this->createNotFoundException(sprintf('Partida #%d no encontrada.', $matchId));
        }

        $replay = $match->getReplay();
        $repoRoot = dirname($this->kernel->getProjectDir());

        $candidatePaths = [];
        if ($replay && $replay->getFilePath()) {
            $path = $replay->getFilePath();
            $candidatePaths[] = str_starts_with($path, '/') ? $path : $repoRoot . '/' . $path;
        }
        $candidatePaths[] = $repoRoot . '/var/agentrix/replays/match_' . $matchId . '.json.gz';

        $fullPath = null;
        foreach ($candidatePaths as $p) {
            if (file_exists($p)) {
                $fullPath = $p;
                break;
            }
        }

        if (!$fullPath) {
            throw $this->createNotFoundException(sprintf('Archivo de repetición no encontrado para la partida #%d.', $matchId));
        }

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Cache-Control', 'public, max-age=604800, immutable');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="match_%d.json"', $matchId));

        return $response;
    }

    /**
     * Arena Tournament Scoreboard ranking.
     */
    #[Route('/ranking', name: 'agentrix_ranking', methods: ['GET'])]
    public function ranking(Request $request): Response
    {
        $contest = $this->dj->getCurrentContest();
        if (!$contest) {
            $contests = $this->dj->getCurrentContests();
            $contest = $contests[0] ?? null;
        }

        if (!$contest) {
            return $this->render('agentrix/ranking.html.twig', [
                'contest' => null,
                'rankings' => [],
                'totalFinishedMatches' => 0,
                'roundFilter' => 'all',
                'availableRounds' => [],
            ]);
        }

        $roundFilter = $request->query->get('round', 'all');
        $round = ($roundFilter !== 'all' && is_numeric($roundFilter)) ? (int)$roundFilter : null;

        $isPublic = !$this->isGranted('ROLE_JURY');
        $rankings = $this->rankingService->calculateRanking($contest, $round, $isPublic);

        // Available rounds
        $roundsQb = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('DISTINCT m.round')
            ->where('m.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('m.round', 'ASC');
        $availableRounds = array_column($roundsQb->getQuery()->getScalarResult(), 'round');

        // Total finished matches
        $totalFinishedQb = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('COUNT(m.matchid)')
            ->where('m.contest = :contest')
            ->andWhere('m.status = :status')
            ->setParameter('contest', $contest)
            ->setParameter('status', ArenaMatch::STATUS_FINISHED);
        if ($round !== null) {
            $totalFinishedQb->andWhere('m.round = :round')
                            ->setParameter('round', $round);
        }
        $totalFinishedMatches = (int)$totalFinishedQb->getQuery()->getSingleScalarResult();

        return $this->render('agentrix/ranking.html.twig', [
            'contest' => $contest,
            'rankings' => $rankings,
            'roundFilter' => $roundFilter,
            'availableRounds' => $availableRounds,
            'totalFinishedMatches' => $totalFinishedMatches,
        ]);
    }

    /**
     * Resets a failed or stuck match back to queued.
     */
    #[Route('/match/{matchId}/retry', name: 'agentrix_match_retry', methods: ['POST'])]
    #[IsGranted('ROLE_JURY')]
    public function retryMatch(int $matchId): Response
    {
        /** @var ArenaMatch|null $match */
        $match = $this->em->getRepository(ArenaMatch::class)->find($matchId);
        if (!$match) {
            throw $this->createNotFoundException(sprintf('Partida #%d no encontrada.', $matchId));
        }

        $match->setStatus(ArenaMatch::STATUS_QUEUED);
        $match->setStartedAt(null);
        $match->setFinishedAt(null);
        $match->setWorkerHost(null);
        $match->setErrorMessage(null);

        foreach ($match->getParticipants() as $p) {
            $p->setKills(null);
            $p->setMobKills(null);
            $p->setScore(null);
            $p->setPlace(null);
            $p->setAlive(true);
            $p->setDeathTick(null);
            $p->setErrorMessage(null);
        }

        if ($match->getReplay()) {
            $this->em->remove($match->getReplay());
        }

        $this->em->flush();
        $this->addFlash('info', sprintf('Partida #%d reiniciada a cola de ejecución.', $matchId));

        return $this->redirectToRoute('agentrix_matches');
    }

    /**
     * Schedules the next tournament round.
     */
    #[Route('/round/schedule', name: 'agentrix_round_schedule', methods: ['POST'])]
    #[IsGranted('ROLE_JURY')]
    public function scheduleNextRound(Request $request): Response
    {
        $contest = $this->dj->getCurrentContest();
        if (!$contest) {
            $contests = $this->dj->getCurrentContests();
            $contest = $contests[0] ?? null;
        }

        if (!$contest) {
            $this->addFlash('danger', 'No hay concurso activo para programar rondas.');
            return $this->redirectToRoute('agentrix_matches');
        }

        // Find arena game
        $game = $this->em->getRepository(ArenaGame::class)->findOneBy(['active' => true]);
        if (!$game) {
            $this->addFlash('danger', 'No hay modo de juego activo configurado.');
            return $this->redirectToRoute('agentrix_matches');
        }

        // Find arena problem
        $problem = $this->em->getRepository(Problem::class)->findOneBy(['externalid' => 'agentrix_arena_bot']);
        if (!$problem) {
            $problem = $this->em->getRepository(Problem::class)->findOneBy(['name' => 'Agentrix Arena Bot']);
        }
        if (!$problem) {
            $this->addFlash('danger', 'No se encontró el problema del bot de la arena (agentrix_arena_bot).');
            return $this->redirectToRoute('agentrix_matches');
        }

        // Get latest round
        $roundQb = $this->em->createQueryBuilder()
            ->from(ArenaMatch::class, 'm')
            ->select('MAX(m.round)')
            ->where('m.contest = :contest')
            ->setParameter('contest', $contest);
        $maxRound = (int)$roundQb->getQuery()->getSingleScalarResult();
        $nextRound = $maxRound + 1;

        $matches = $this->matchmakerService->scheduleRound($contest, $problem, $game, $nextRound, 3);

        if (empty($matches)) {
            $this->addFlash('warning', sprintf('No se pudieron crear partidas para la Ronda %d. Se requiere al menos un equipo con bot verificado CORRECT.', $nextRound));
        } else {
            $this->addFlash('success', sprintf('Ronda %d programada exitosamente con %d partidas.', $nextRound, count($matches)));
        }

        return $this->redirectToRoute('agentrix_matches', ['round' => $nextRound]);
    }
}
