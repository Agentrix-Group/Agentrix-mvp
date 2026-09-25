<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Agentrix Arena domain tables:
 * - arena_game
 * - arena_match
 * - arena_match_participant
 * - arena_replay
 */
final class Version20260924231008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Agentrix Arena tables (arena_game, arena_match, arena_match_participant, arena_replay)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE arena_game (
            gameid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Game ID\',
            cid INT UNSIGNED DEFAULT NULL COMMENT \'Contest ID\',
            name VARCHAR(128) NOT NULL COMMENT \'Name of the game/arena mode\',
            width DOUBLE PRECISION DEFAULT \'1200\' NOT NULL COMMENT \'Arena width in units\',
            height DOUBLE PRECISION DEFAULT \'800\' NOT NULL COMMENT \'Arena height in units\',
            duration DOUBLE PRECISION DEFAULT \'180\' NOT NULL COMMENT \'Max match duration in seconds\',
            max_participants INT DEFAULT 5 NOT NULL COMMENT \'Max participants per match\',
            config LONGTEXT DEFAULT NULL COMMENT \'Game custom rules, walls, mob count, etc.(DC2Type:json)\',
            INDEX IDX_3BFC1F794B30D9C4 (cid),
            PRIMARY KEY(gameid)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE arena_match (
            matchid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Match ID\',
            gameid INT UNSIGNED NOT NULL COMMENT \'Game ID\',
            cid INT UNSIGNED NOT NULL COMMENT \'Contest ID\',
            seed INT UNSIGNED NOT NULL COMMENT \'Simulation seed\',
            status VARCHAR(32) DEFAULT \'queued\' NOT NULL COMMENT \'Status: queued|running|finished|failed\',
            round INT DEFAULT 1 NOT NULL COMMENT \'Tournament round number\',
            scheduled_at DATETIME NOT NULL COMMENT \'When match was scheduled\',
            started_at DATETIME DEFAULT NULL COMMENT \'When match started running\',
            finished_at DATETIME DEFAULT NULL COMMENT \'When match finished\',
            worker_host VARCHAR(128) DEFAULT NULL COMMENT \'Worker hostname that executed the match\',
            error_message LONGTEXT DEFAULT NULL COMMENT \'Failure reason or system error\',
            INDEX IDX_B79414B879D19306 (gameid),
            INDEX cid (cid),
            INDEX status (status),
            PRIMARY KEY(matchid)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE arena_match_participant (
            participantid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Participant ID\',
            matchid INT UNSIGNED NOT NULL COMMENT \'Match ID\',
            teamid INT UNSIGNED NOT NULL COMMENT \'Team ID\',
            submitid INT UNSIGNED DEFAULT NULL COMMENT \'Submission ID\',
            seat SMALLINT NOT NULL COMMENT \'Seat index 0..4\',
            place SMALLINT DEFAULT NULL COMMENT \'Final place 1..5\',
            score DOUBLE PRECISION DEFAULT NULL COMMENT \'Total score\',
            kills SMALLINT DEFAULT NULL COMMENT \'Player kills count\',
            mob_kills SMALLINT DEFAULT NULL COMMENT \'Mob kills count\',
            alive TINYINT(1) DEFAULT NULL COMMENT \'Survived until end\',
            death_tick INT DEFAULT NULL COMMENT \'Tick when player died\',
            log_output LONGTEXT DEFAULT NULL COMMENT \'Execution stdout/stderr log\',
            INDEX matchid (matchid),
            INDEX teamid (teamid),
            INDEX submitid (submitid),
            UNIQUE INDEX match_seat (matchid, seat),
            PRIMARY KEY(participantid)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE arena_replay (
            replayid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Replay ID\',
            matchid INT UNSIGNED NOT NULL COMMENT \'Match ID\',
            file_path VARCHAR(512) NOT NULL COMMENT \'Relative path to compressed replay file\',
            file_size INT UNSIGNED NOT NULL COMMENT \'File size in bytes\',
            file_hash VARCHAR(64) NOT NULL COMMENT \'SHA-256 hash of replay file\',
            ticks INT UNSIGNED NOT NULL COMMENT \'Total simulation ticks\',
            duration_seconds DOUBLE PRECISION NOT NULL COMMENT \'Duration in simulation seconds\',
            created_at DATETIME NOT NULL COMMENT \'Replay generation timestamp\',
            UNIQUE INDEX match_replay (matchid),
            PRIMARY KEY(replayid)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE arena_game ADD CONSTRAINT FK_3BFC1F794B30D9C4 FOREIGN KEY (cid) REFERENCES contest (cid) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE arena_match ADD CONSTRAINT FK_B79414B879D19306 FOREIGN KEY (gameid) REFERENCES arena_game (gameid) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE arena_match ADD CONSTRAINT FK_B79414B84B30D9C4 FOREIGN KEY (cid) REFERENCES contest (cid) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE arena_match_participant ADD CONSTRAINT FK_1AAC47B2940DF71 FOREIGN KEY (matchid) REFERENCES arena_match (matchid) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE arena_match_participant ADD CONSTRAINT FK_1AAC47B4DD6ABF3 FOREIGN KEY (teamid) REFERENCES team (teamid) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE arena_match_participant ADD CONSTRAINT FK_1AAC47B3605A691 FOREIGN KEY (submitid) REFERENCES submission (submitid) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE arena_replay ADD CONSTRAINT FK_6C2AF4122940DF71 FOREIGN KEY (matchid) REFERENCES arena_match (matchid) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE arena_game DROP FOREIGN KEY FK_3BFC1F794B30D9C4');
        $this->addSql('ALTER TABLE arena_match DROP FOREIGN KEY FK_B79414B879D19306');
        $this->addSql('ALTER TABLE arena_match DROP FOREIGN KEY FK_B79414B84B30D9C4');
        $this->addSql('ALTER TABLE arena_match_participant DROP FOREIGN KEY FK_1AAC47B2940DF71');
        $this->addSql('ALTER TABLE arena_match_participant DROP FOREIGN KEY FK_1AAC47B4DD6ABF3');
        $this->addSql('ALTER TABLE arena_match_participant DROP FOREIGN KEY FK_1AAC47B3605A691');
        $this->addSql('ALTER TABLE arena_replay DROP FOREIGN KEY FK_6C2AF4122940DF71');
        $this->addSql('DROP TABLE arena_replay');
        $this->addSql('DROP TABLE arena_match_participant');
        $this->addSql('DROP TABLE arena_match');
        $this->addSql('DROP TABLE arena_game');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
