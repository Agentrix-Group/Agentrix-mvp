<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Contest;
use App\Service\Agentrix\ArenaRankingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'agentrix:ranking',
    description: 'Displays official Arena tournament ranking and statistics'
)]
class AgentrixRankingCommand extends Command
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly ArenaRankingService $rankingService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('contest', 'c', InputOption::VALUE_OPTIONAL, 'Contest ID (CID)', '1')
            ->addOption('round', 'r', InputOption::VALUE_OPTIONAL, 'Round number (optional)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Agentrix Arena - Clasificación Oficial');

        $cid = (int)$input->getOption('contest');
        $round = $input->getOption('round') !== null ? (int)$input->getOption('round') : null;

        $contest = $this->em->getRepository(Contest::class)->find($cid);
        if (!$contest) {
            $io->error("Contest with ID $cid not found.");
            return Command::FAILURE;
        }

        $ranking = $this->rankingService->calculateRanking($contest, $round, false);

        if (empty($ranking)) {
            $io->warning('Aún no hay partidas finalizadas para calcular el ranking.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($ranking as $r) {
            $rows[] = [
                $r['rank'] . 'º',
                $r['team_name'] . " (#{$r['team_id']})",
                $r['total_score'],
                $r['avg_score'],
                $r['matches_played'],
                $r['matches_won'],
                $r['total_kills'],
                $r['total_mob_kills'],
                round($r['total_survival_ticks'] / 60.0, 1) . ' s',
            ];
        }

        $io->table([
            'Puesto', 'Equipo', 'Puntos Totales', 'Puntos Medios',
            'Jugadas', 'Victorias', 'Kills Bot', 'Kills Mob', 'Supervivencia'
        ], $rows);

        return Command::SUCCESS;
    }
}
