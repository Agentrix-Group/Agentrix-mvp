<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Agentrix\ArenaGame;
use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\Problem;
use App\Entity\Testcase;
use App\Entity\TestcaseContent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'agentrix:create-arena-problem',
    description: 'Creates or updates an Arena Bot problem in DOMjudge linked to an ArenaGame'
)]
class AgentrixCreateArenaProblemCommand extends Command
{
    public function __construct(
        protected readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('contest', 'c', InputOption::VALUE_OPTIONAL, 'Contest ID (CID)', '1')
            ->addOption('shortname', 's', InputOption::VALUE_OPTIONAL, 'Shortname in scoreboard (e.g. D)', 'D')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Problem name', 'Agentrix Battle Royale Bot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Agentrix Arena Problem Setup');

        $cid = (int)$input->getOption('contest');
        $shortname = (string)$input->getOption('shortname');
        $name = (string)$input->getOption('name');

        $contest = $this->em->getRepository(Contest::class)->find($cid);
        if (!$contest) {
            $io->error("Contest with ID $cid not found.");
            return Command::FAILURE;
        }

        // Check if problem already exists by externalid or name
        $externalId = 'agentrix_arena_bot';
        $problemRepo = $this->em->getRepository(Problem::class);
        $problem = $problemRepo->findOneBy(['externalid' => $externalId]);

        if (!$problem) {
            $problem = new Problem();
            $problem->setExternalid($externalId);
            $this->em->persist($problem);
            $io->text("Creando nuevo problema: $name ($externalId)");
        } else {
            $io->text("Actualizando problema existente: $name ($externalId)");
        }

        $problem->setName($name);
        $problem->setTimelimit(5.0);
        $problem->setMemlimit(2097152); // 2GB
        $problem->setOutputlimit(8192);

        // Ensure ContestProblem relation
        $cpRepo = $this->em->getRepository(ContestProblem::class);
        $cp = $cpRepo->findOneBy(['contest' => $contest, 'problem' => $problem]);
        if (!$cp) {
            $cp = new ContestProblem();
            $cp->setContest($contest);
            $cp->setProblem($problem);
            $cp->setShortname($shortname);
            $cp->setPoints(1);
            $cp->setAllowSubmit(true);
            $cp->setAllowJudge(true);
            $this->em->persist($cp);
            $io->text("Vinculando problema a concurso $cid con etiqueta [$shortname]");
        } else {
            $cp->setShortname($shortname);
            $cp->setAllowSubmit(true);
            $cp->setAllowJudge(true);
        }

        // Create or update Sanity Check testcase
        $tcRepo = $this->em->getRepository(Testcase::class);
        $testcase = $tcRepo->findOneBy(['problem' => $problem, 'ranknumber' => 1]);

        $testInput = "{\"phase\":\"INIT\"}\n";
        $testOutput = "{\"status\":\"READY\"}\n";

        if (!$testcase) {
            $testcase = new Testcase();
            $testcase->setProblem($problem);
            $testcase->setRank(1);
            $testcase->setSample(true);
            $this->em->persist($testcase);
            $io->text("Creando caso de prueba de Sanity Check (rank 1)");
        }

        $testcase->setMd5sumInput(md5($testInput));
        $testcase->setMd5sumOutput(md5($testOutput));

        $content = $testcase->getContent();
        if (!$content) {
            $content = new TestcaseContent();
            $content->setTestcase($testcase);
            $this->em->persist($content);
        }
        $content->setInput($testInput);
        $content->setOutput($testOutput);

        // Link with an ArenaGame definition
        $arenaGameRepo = $this->em->getRepository(ArenaGame::class);
        $arenaGame = $arenaGameRepo->findOneBy(['contest' => $contest]);
        if (!$arenaGame) {
            $arenaGame = new ArenaGame();
            $arenaGame->setName('Agentrix Arena 5-Player Battle Royale');
            $arenaGame->setContest($contest);
            $arenaGame->setWidth(1200.0);
            $arenaGame->setHeight(800.0);
            $arenaGame->setDuration(180.0);
            $arenaGame->setMaxParticipants(5);
            $this->em->persist($arenaGame);
            $io->text("Creando configuración oficial de ArenaGame para el concurso $cid");
        }

        $this->em->flush();

        $io->success("Problema [$shortname] '$name' y ArenaGame configurados exitosamente en concurso $cid.");
        return Command::SUCCESS;
    }
}
