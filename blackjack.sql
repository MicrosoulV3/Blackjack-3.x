  ---------------
  – TTv3 House
  Blackjack –
  Database table
  for
  blackjack.php
  ---------------
  – MySQL /
  MariaDB –
  Engine: InnoDB
  – Character
  set: utf8mb4

  ---------------

– IMPORTANT: – This schema assumes users.id is a signed INT. – No
foreign key is used so it remains friendly to legacy – TorrentTrader /
TTv3 installations. – ——————————————————–

CREATE TABLE blackjack_games ( id BIGINT UNSIGNED NOT NULL
AUTO_INCREMENT, user_id INT NOT NULL,

    `active_key` VARCHAR(64) DEFAULT NULL,

    `wager` BIGINT UNSIGNED NOT NULL DEFAULT 0,

    `status` ENUM('playing','settled') NOT NULL DEFAULT 'playing',

    `shoe` TEXT NOT NULL,
    `shoe_pos` INT UNSIGNED NOT NULL DEFAULT 0,

    `player_cards` TEXT NOT NULL,
    `dealer_cards` TEXT NOT NULL,

    `player_points` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `dealer_points` TINYINT UNSIGNED NOT NULL DEFAULT 0,

    `result` ENUM('win','loss','push','blackjack') DEFAULT NULL,

    `payout` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `profit` BIGINT NOT NULL DEFAULT 0,

    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    `settled_at` DATETIME DEFAULT NULL,

    PRIMARY KEY (`id`),

    UNIQUE KEY `uniq_blackjack_active_key` (`active_key`),

    KEY `idx_blackjack_user` (`user_id`),
    KEY `idx_blackjack_user_status` (`user_id`,`status`),
    KEY `idx_blackjack_status` (`status`),
    KEY `idx_blackjack_settled` (`settled_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
