<?php declare(strict_types=1);

namespace App\Entity\Agentrix;

use App\Entity\Contest;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An individual 5-player Arena match.
 */
#[ORM\Entity]
#[ORM\Table(name: 'arena_match', options: ['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4'])]
#[ORM\Index(columns: ['cid'], name: 'cid')]
#[ORM\Index(columns: ['status'], name: 'status')]
class ArenaMatch
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'matchid', options: ['comment' => 'Match ID', 'unsigned' => true])]
    private ?int $matchid = null;

    #[ORM\ManyToOne(targetEntity: ArenaGame::class, inversedBy: 'matches')]
    #[ORM\JoinColumn(name: 'gameid', referencedColumnName: 'gameid', nullable: false, onDelete: 'CASCADE')]
    private ArenaGame $game;

    #[ORM\ManyToOne(targetEntity: Contest::class)]
    #[ORM\JoinColumn(name: 'cid', referencedColumnName: 'cid', nullable: false, onDelete: 'CASCADE')]
    private Contest $contest;

    #[ORM\Column(type: 'integer', options: ['comment' => 'Simulation seed', 'unsigned' => true])]
    private int $seed = 2026;

    #[ORM\Column(length: 32, options: ['comment' => 'Status: queued|running|finished|failed', 'default' => 'queued'])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: 'integer', options: ['comment' => 'Tournament round number', 'default' => 1])]
    private int $round = 1;

    #[ORM\Column(type: 'datetime', options: ['comment' => 'When match was scheduled'])]
    private DateTime $scheduledAt;

    #[ORM\Column(type: 'datetime', nullable: true, options: ['comment' => 'When match started running'])]
    private ?DateTime $startedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true, options: ['comment' => 'When match finished'])]
    private ?DateTime $finishedAt = null;

    #[ORM\Column(length: 128, nullable: true, options: ['comment' => 'Worker hostname that executed the match'])]
    private ?string $workerHost = null;

    #[ORM\Column(type: 'text', nullable: true, options: ['comment' => 'Failure reason or system error'])]
    private ?string $errorMessage = null;

    /**
     * @var Collection<int, ArenaMatchParticipant>
     */
    #[ORM\OneToMany(mappedBy: 'match', targetEntity: ArenaMatchParticipant::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['seat' => 'ASC'])]
    private Collection $participants;

    #[ORM\OneToOne(mappedBy: 'match', targetEntity: ArenaReplay::class, cascade: ['persist', 'remove'])]
    private ?ArenaReplay $replay = null;

    public function __construct()
    {
        $this->scheduledAt = new DateTime();
        $this->participants = new ArrayCollection();
    }

    public function getMatchid(): ?int
    {
        return $this->matchid;
    }

    public function getGame(): ArenaGame
    {
        return $this->game;
    }

    public function setGame(ArenaGame $game): self
    {
        $this->game = $game;
        return $this;
    }

    public function getContest(): Contest
    {
        return $this->contest;
    }

    public function setContest(Contest $contest): self
    {
        $this->contest = $contest;
        return $this;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function setSeed(int $seed): self
    {
        $this->seed = $seed;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function setRound(int $round): self
    {
        $this->round = $round;
        return $this;
    }

    public function getScheduledAt(): DateTime
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(DateTime $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getStartedAt(): ?DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(?DateTime $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?DateTime
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTime $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getWorkerHost(): ?string
    {
        return $this->workerHost;
    }

    public function setWorkerHost(?string $workerHost): self
    {
        $this->workerHost = $workerHost;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    /**
     * @return Collection<int, ArenaMatchParticipant>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(ArenaMatchParticipant $participant): self
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            $participant->setMatch($this);
        }
        return $this;
    }

    public function getReplay(): ?ArenaReplay
    {
        return $this->replay;
    }

    public function setReplay(?ArenaReplay $replay): self
    {
        $this->replay = $replay;
        if ($replay !== null && $replay->getMatch() !== $this) {
            $replay->setMatch($this);
        }
        return $this;
    }
}
