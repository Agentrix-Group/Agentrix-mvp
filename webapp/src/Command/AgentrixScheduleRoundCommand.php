<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Agentrix\ArenaGame;
use App\Entity\Contest;
use App\Entity\Problem;
use App\Service\Agentrix\MatchmakerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'agentrix:schedule-round',
    description: 'Schedules a tournament round of Arena matches for eligible teams'
)]
class AgentrixScheduleRoundCommand extends Command
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly MatchmakerService $matchmaker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('contest', 'c', InputOption::VALUE_OPTIONAL, 'Contest ID (CID)', '1')
            ->addOption('round', 'r', InputOption::VALUE_OPTIONAL, 'Tournament round number', '1')
            ->addOption('seeds', 's', InputOption::VALUE_OPTIONAL, 'Seeds per group (seat rotations)', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Agentrix Tournament Matchmaker');

        $cid = (int)$input->getOption('contest');
        $round = (int)$input->getOption('round');
        $seedsCount = (int)$input->getOption('seeds');

        $contest = $this->em->getRepository(Contest::class)->find($cid);
        if (!$contest) {
            $io->error("Contest with ID $cid not found.");
            return Command::FAILURE;
        }

        $problem = $this->em->getRepository(Problem::class)->findOneBy(['externalid' => 'agentrix_arena_bot']);
        if (!$problem) {
            $io->error("Arena problem 'agentrix_arena_bot' not found. Run agentrix:create-arena-problem first.");
            return Command::FAILURE;
        }

        $arenaGame = $this->em->getRepository(ArenaGame::class)->findOneBy(['contest' => $contest]);
        if (!$arenaGame) {
            $io->error("No ArenaGame configured for contest $cid.");
            return Command::FAILURE;
        }

        $eligible = $this->matchmaker->getEligibleTeams($contest, $problem);
        $io->text(sprintf('Equipos elegibles con bot CORRECT aprobado: %d', count($eligible)));

        foreach ($eligible as $teamId => $info) {
            $io->text(sprintf('  ✔ Equipo #%d (%s) - Submit #%d',
                $teamId,
                $info['team']->getName(),
                $info['submission']->getSubmitid()
            ));
        }

        if (empty($eligible)) {
            $io->warning('No hay equipos con bots aprobados. No se programaron partidas.');
            return Command::SUCCESS;
        }

        $matches = $this->matchmaker->scheduleRound($contest, $problem, $arenaGame, $round, $seedsCount);

        $io->success(sprintf('Se programaron %d partidas para la Ronda %d con %d semillas por grupo.',
            count($matches), $round, $seedsCount
        ));

        $rows = [];
        foreach ($matches as $m) {
            $teamNames = [];
            foreach ($m->getParticipants() as $p) {
                $teamNames[] = sprintf('A%d: %s (#%d)', $p->getSeat(), $p->getTeam()->getName(), $p->getSubmission()->getSubmitid());
            }
            $rows[] = [
                $m->getMatchid(),
                $m->getRound(),
                $m->getSeed(),
                $m->getStatus(),
                implode("\n", $teamNames)
            ];
        }

        $io->table(['Match ID', 'Ronda', 'Semilla', 'Estado', 'Asientos y Equipos'], $rows);
        return Command::SUCCESS;
    }
}
