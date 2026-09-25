<?php declare(strict_types=1);

namespace App\Entity\Agentrix;

use App\Entity\Contest;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Definition of an Arena Game configuration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'arena_game', options: ['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4'])]
class ArenaGame
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'gameid', options: ['comment' => 'Game ID', 'unsigned' => true])]
    private ?int $gameid = null;

    #[ORM\Column(length: 128, options: ['comment' => 'Name of the game/arena mode'])]
    private string $name = 'Standard Arena (5 Players)';

    #[ORM\Column(type: 'float', options: ['comment' => 'Arena width in units', 'default' => 1200.0])]
    private float $width = 1200.0;

    #[ORM\Column(type: 'float', options: ['comment' => 'Arena height in units', 'default' => 800.0])]
    private float $height = 800.0;

    #[ORM\Column(type: 'float', options: ['comment' => 'Max match duration in seconds', 'default' => 180.0])]
    private float $duration = 180.0;

    #[ORM\Column(type: 'integer', options: ['comment' => 'Max participants per match', 'default' => 5])]
    private int $maxParticipants = 5;

    #[ORM\Column(type: 'json', nullable: true, options: ['comment' => 'Game custom rules, walls, mob count, etc.'])]
    private ?array $config = null;

    #[ORM\ManyToOne(targetEntity: Contest::class)]
    #[ORM\JoinColumn(name: 'cid', referencedColumnName: 'cid', nullable: true, onDelete: 'SET NULL')]
    private ?Contest $contest = null;

    /**
     * @var Collection<int, ArenaMatch>
     */
    #[ORM\OneToMany(mappedBy: 'game', targetEntity: ArenaMatch::class)]
    private Collection $matches;

    public function __construct()
    {
        $this->matches = new ArrayCollection();
    }

    public function getGameid(): ?int
    {
        return $this->gameid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function setWidth(float $width): self
    {
        $this->width = $width;
        return $this;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function setHeight(float $height): self
    {
        $this->height = $height;
        return $this;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function setDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function getMaxParticipants(): int
    {
        return $this->maxParticipants;
    }

    public function setMaxParticipants(int $maxParticipants): self
    {
        $this->maxParticipants = $maxParticipants;
        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getContest(): ?Contest
    {
        return $this->contest;
    }

    public function setContest(?Contest $contest): self
    {
        $this->contest = $contest;
        return $this;
    }

    /**
     * @return Collection<int, ArenaMatch>
     */
    public function getMatches(): Collection
    {
        return $this->matches;
    }
}
