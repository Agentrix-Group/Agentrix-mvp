<?php declare(strict_types=1);

namespace App\Entity\Agentrix;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * Replay metadata and file reference for an Arena match.
 */
#[ORM\Entity]
#[ORM\Table(name: 'arena_replay', options: ['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4'])]
#[ORM\UniqueConstraint(name: 'match_replay', columns: ['matchid'])]
class ArenaReplay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'replayid', options: ['comment' => 'Replay ID', 'unsigned' => true])]
    private ?int $replayid = null;

    #[ORM\OneToOne(targetEntity: ArenaMatch::class, inversedBy: 'replay')]
    #[ORM\JoinColumn(name: 'matchid', referencedColumnName: 'matchid', nullable: false, onDelete: 'CASCADE')]
    private ArenaMatch $match;

    #[ORM\Column(length: 512, options: ['comment' => 'Relative path to compressed replay file'])]
    private string $filePath;

    #[ORM\Column(type: 'integer', options: ['comment' => 'File size in bytes', 'unsigned' => true])]
    private int $fileSize = 0;

    #[ORM\Column(length: 64, options: ['comment' => 'SHA-256 hash of replay file'])]
    private string $fileHash = '';

    #[ORM\Column(type: 'integer', options: ['comment' => 'Total simulation ticks', 'unsigned' => true])]
    private int $ticks = 0;

    #[ORM\Column(type: 'float', options: ['comment' => 'Duration in simulation seconds'])]
    private float $durationSeconds = 0.0;

    #[ORM\Column(type: 'datetime', options: ['comment' => 'Replay generation timestamp'])]
    private DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
    }

    public function getReplayid(): ?int
    {
        return $this->replayid;
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

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function setFileHash(string $fileHash): self
    {
        $this->fileHash = $fileHash;
        return $this;
    }

    public function getTicks(): int
    {
        return $this->ticks;
    }

    public function setTicks(int $ticks): self
    {
        $this->ticks = $ticks;
        return $this;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(float $durationSeconds): self
    {
        $this->durationSeconds = $durationSeconds;
        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
