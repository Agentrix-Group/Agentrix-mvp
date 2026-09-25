<?php declare(strict_types=1);

namespace App\Entity\Agentrix;

use App\Entity\Submission;
use App\Entity\Team;
use Doctrine\ORM\Mapping as ORM;

/**
 * A participant slot (seat 0..4) in an Arena match.
 */
#[ORM\Entity]
#[ORM\Table(name: 'arena_match_participant', options: ['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4'])]
#[ORM\Index(columns: ['matchid'], name: 'matchid')]
#[ORM\Index(columns: ['teamid'], name: 'teamid')]
#[ORM\Index(columns: ['submitid'], name: 'submitid')]
#[ORM\UniqueConstraint(name: 'match_seat', columns: ['matchid', 'seat'])]
class ArenaMatchParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'participantid', options: ['comment' => 'Participant ID', 'unsigned' => true])]
    private ?int $participantid = null;

    #[ORM\ManyToOne(targetEntity: ArenaMatch::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'matchid', referencedColumnName: 'matchid', nullable: false, onDelete: 'CASCADE')]
    private ArenaMatch $match;

    #[ORM\Column(type: 'smallint', options: ['comment' => 'Seat index 0..4'])]
    private int $seat = 0;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'teamid', referencedColumnName: 'teamid', nullable: false, onDelete: 'CASCADE')]
    private Team $team;

    #[ORM\ManyToOne(targetEntity: Submission::class)]
    #[ORM\JoinColumn(name: 'submitid', referencedColumnName: 'submitid', nullable: true, onDelete: 'SET NULL')]
    private ?Submission $submission = null;

    #[ORM\Column(type: 'smallint', nullable: true, options: ['comment' => 'Final place 1..5'])]
    private ?int $place = null;

    #[ORM\Column(type: 'float', nullable: true, options: ['comment' => 'Total score'])]
    private ?float $score = null;

    #[ORM\Column(type: 'smallint', nullable: true, options: ['comment' => 'Player kills count'])]
    private ?int $kills = null;

    #[ORM\Column(type: 'smallint', nullable: true, options: ['comment' => 'Mob kills count'])]
    private ?int $mobKills = null;

    #[ORM\Column(type: 'boolean', nullable: true, options: ['comment' => 'Survived until end'])]
    private ?bool $alive = null;

    #[ORM\Column(type: 'integer', nullable: true, options: ['comment' => 'Tick when player died'])]
    private ?int $deathTick = null;

    #[ORM\Column(type: 'text', nullable: true, options: ['comment' => 'Execution stdout/stderr log'])]
    private ?string $logOutput = null;

    public function getParticipantid(): ?int
    {
        return $this->participantid;
    }

    public function getMatch(): ArenaMatch
    {
        return $this->match;
    }

    public function setMatch(ArenaMatch $match): self
    {
        $this->match = $match;
        return $this;
    }

    public function getSeat(): int
    {
        return $this->seat;
    }

    public function setSeat(int $seat): self
    {
        $this->seat = $seat;
        return $this;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function setTeam(Team $team): self
    {
        $this->team = $team;
        return $this;
    }

    public function getSubmission(): ?Submission
    {
        return $this->submission;
    }

    public function setSubmission(?Submission $submission): self
    {
        $this->submission = $submission;
        return $this;
    }

    public function getPlace(): ?int
    {
        return $this->place;
    }

    public function setPlace(?int $place): self
    {
        $this->place = $place;
        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): self
    {
        $this->score = $score;
        return $this;
    }

    public function getKills(): ?int
    {
        return $this->kills;
    }

    public function setKills(?int $kills): self
    {
        $this->kills = $kills;
        return $this;
    }

    public function getMobKills(): ?int
    {
        return $this->mobKills;
    }

    public function setMobKills(?int $mobKills): self
    {
        $this->mobKills = $mobKills;
        return $this;
    }

    public function isAlive(): ?bool
    {
        return $this->alive;
    }

    public function setAlive(?bool $alive): self
    {
        $this->alive = $alive;
        return $this;
    }

    public function getDeathTick(): ?int
    {
        return $this->deathTick;
    }

    public function setDeathTick(?int $deathTick): self
    {
        $this->deathTick = $deathTick;
        return $this;
    }

    public function getLogOutput(): ?string
    {
        return $this->logOutput;
    }

    public function setLogOutput(?string $logOutput): self
    {
        $this->logOutput = $logOutput;
        return $this;
    }
}
