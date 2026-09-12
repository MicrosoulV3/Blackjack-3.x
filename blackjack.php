<?php

require_once __DIR__ . "/backend/functions.php";

global $CURUSER, $site_config, $phpself;

dbconn(false);

$site_config["LEFTNAV"] = false;
$site_config["MIDDLENAV"] = false;
$site_config["RIGHTNAV"] = false;

if (empty($CURUSER) || (int)$CURUSER["class"] < 1) {
    show_error_msg("Blackjack", "You do not have permission to play blackjack.", 1);
}

$phpself = "blackjack.php";
$userId = (int)$CURUSER["id"];

$MB = 1024 * 1024;
$GB = 1024 * 1024 * 1024;

$minBet = isset($site_config["blackjack_min_bet"])
    ? (int)$site_config["blackjack_min_bet"]
    : 0;

$maxBet = isset($site_config["blackjack_max_bet"])
    ? (int)$site_config["blackjack_max_bet"]
    : 0;

if ($minBet <= 0 || $maxBet <= 0 || $minBet > $maxBet) {
    show_error_msg(
        "Blackjack",
        "Blackjack betting limits are not configured correctly.",
        1
    );
}

$GLOBALS["blackjack_min_bet_runtime"] = $minBet;
$GLOBALS["blackjack_max_bet_runtime"] = $maxBet;


/*
 * --------------------------------------------------------------------------
 * Helpers
 * --------------------------------------------------------------------------
 */

function bj_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function bj_db(): mysqli
{
    if (
        empty($GLOBALS["DBconnector"])
        || !($GLOBALS["DBconnector"] instanceof mysqli)
    ) {
        throw new RuntimeException("Database connection is not available.");
    }

    return $GLOBALS["DBconnector"];
}

function bj_query_one(mysqli $db, string $sql): ?array
{
    $result = $db->query($sql);

    if (!($result instanceof mysqli_result)) {
        throw new RuntimeException("Blackjack database query failed.");
    }

    $row = $result->fetch_assoc();
    $result->free();

    return $row ?: null;
}

function bj_balance(int $userId): int
{
    $db = bj_db();

    $row = bj_query_one(
        $db,
        "SELECT uploaded
         FROM users
         WHERE id = " . $userId . "
         LIMIT 1"
    );

    return $row ? (int)$row["uploaded"] : 0;
}


function bj_query_all(mysqli $db, string $sql): array
{
    $result = $db->query($sql);

    if (!($result instanceof mysqli_result)) {
        throw new RuntimeException("Blackjack database query failed.");
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $result->free();

    return $rows;
}

function bj_signed_size(int $bytes): string
{
    if ($bytes > 0) {
        return "+" . mksize($bytes);
    }

    if ($bytes < 0) {
        return "-" . mksize(abs($bytes));
    }

    return mksize(0);
}

function bj_player_stats(int $userId): array
{
    $db = bj_db();

    $row = bj_query_one(
        $db,
        "SELECT
            COUNT(*) AS hands,
            SUM(CASE WHEN result IN ('win','blackjack') THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN result = 'loss' THEN 1 ELSE 0 END) AS losses,
            SUM(CASE WHEN result = 'push' THEN 1 ELSE 0 END) AS pushes,
            SUM(CASE WHEN result = 'blackjack' THEN 1 ELSE 0 END) AS blackjacks,
            COALESCE(SUM(wager), 0) AS wagered,
            COALESCE(SUM(payout), 0) AS payouts,
            COALESCE(SUM(profit), 0) AS net_profit,
            COALESCE(MAX(CASE WHEN profit > 0 THEN profit ELSE 0 END), 0) AS biggest_win
         FROM blackjack_games
         WHERE user_id = " . $userId . "
           AND status = 'settled'"
    );

    $stats = [
        "hands" => $row ? (int)$row["hands"] : 0,
        "wins" => $row ? (int)$row["wins"] : 0,
        "losses" => $row ? (int)$row["losses"] : 0,
        "pushes" => $row ? (int)$row["pushes"] : 0,
        "blackjacks" => $row ? (int)$row["blackjacks"] : 0,
        "wagered" => $row ? (int)$row["wagered"] : 0,
        "payouts" => $row ? (int)$row["payouts"] : 0,
        "net_profit" => $row ? (int)$row["net_profit"] : 0,
        "biggest_win" => $row ? (int)$row["biggest_win"] : 0,
        "current_streak_type" => "none",
        "current_streak" => 0,
        "best_win_streak" => 0,
    ];

    $results = bj_query_all(
        $db,
        "SELECT result
         FROM blackjack_games
         WHERE user_id = " . $userId . "
           AND status = 'settled'
         ORDER BY id ASC"
    );

    $currentType = "none";
    $currentCount = 0;
    $bestWin = 0;
    $runningWins = 0;

    foreach ($results as $resultRow) {
        $result = (string)$resultRow["result"];

        // A push is neutral
        if ($result === "push") {
            continue;
        }

        $type = in_array($result, ["win", "blackjack"], true)
            ? "win"
            : "loss";

        if ($type === $currentType) {
            $currentCount++;
        } else {
            $currentType = $type;
            $currentCount = 1;
        }

        if ($type === "win") {
            $runningWins++;
            if ($runningWins > $bestWin) {
                $bestWin = $runningWins;
            }
        } else {
            $runningWins = 0;
        }
    }

    $stats["current_streak_type"] = $currentType;
    $stats["current_streak"] = $currentCount;
    $stats["best_win_streak"] = $bestWin;

    return $stats;
}

function bj_leaderboard(int $limit = 10): array
{
    $db = bj_db();
    $limit = max(1, min(25, $limit));

    return bj_query_all(
        $db,
        "SELECT
            u.id,
            u.username,
            COUNT(*) AS hands,
            SUM(CASE WHEN bg.result IN ('win','blackjack') THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN bg.result = 'loss' THEN 1 ELSE 0 END) AS losses,
            SUM(CASE WHEN bg.result = 'push' THEN 1 ELSE 0 END) AS pushes,
            SUM(CASE WHEN bg.result = 'blackjack' THEN 1 ELSE 0 END) AS blackjacks,
            COALESCE(SUM(bg.profit), 0) AS net_profit,
            COALESCE(MAX(CASE WHEN bg.profit > 0 THEN bg.profit ELSE 0 END), 0) AS biggest_win
         FROM blackjack_games AS bg
         INNER JOIN users AS u ON u.id = bg.user_id
         WHERE bg.status = 'settled'
         GROUP BY u.id, u.username
         ORDER BY net_profit DESC, wins DESC, hands DESC, u.username ASC
         LIMIT " . $limit
    );
}

function bj_admin_stat_players(): array
{
    $db = bj_db();

    return bj_query_all(
        $db,
        "SELECT
            u.id,
            u.username,
            COUNT(*) AS hands
         FROM blackjack_games AS bg
         INNER JOIN users AS u ON u.id = bg.user_id
         WHERE bg.status = 'settled'
         GROUP BY u.id, u.username
         ORDER BY u.username ASC"
    );
}

function bj_admin_reset_stats(int $targetUserId): int
{
    global $CURUSER;

    if (empty($CURUSER) || (int)$CURUSER["class"] < 7) {
        throw new RuntimeException("You do not have permission to reset blackjack stats.");
    }

    if ($targetUserId <= 0) {
        throw new RuntimeException("Select a player to reset.");
    }

    $db = bj_db();

    $player = bj_query_one(
        $db,
        "SELECT id, username
         FROM users
         WHERE id = " . $targetUserId . "
         LIMIT 1"
    );

    if (!$player) {
        throw new RuntimeException("That player could not be found.");
    }

    // Stats are calculated entirely from settled blackjack_games rows.
    // Never touch an active hand and never alter the player's upload balance.
    $db->query(
        "DELETE FROM blackjack_games
         WHERE user_id = " . $targetUserId . "
           AND status = 'settled'"
    );

    $deleted = (int)$db->affected_rows;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION["blackjack_admin_notice"] =
        "Blackjack stats reset for " . (string)$player["username"]
        . ". Removed " . number_format($deleted) . " completed hand"
        . ($deleted === 1 ? "" : "s") . ".";

    return $deleted;
}

function bj_streak_text(array $stats): string
{
    $count = (int)$stats["current_streak"];
    $type = (string)$stats["current_streak_type"];

    if ($count <= 0 || $type === "none") {
        return "—";
    }

    return $count . " " . ($type === "win" ? "win" : "loss")
        . ($count === 1 ? "" : "s");
}

function bj_render_stats(int $userId): void
{
    $stats = bj_player_stats($userId);
    $decisions = $stats["wins"] + $stats["losses"];
    $winRate = $decisions > 0
        ? number_format(($stats["wins"] / $decisions) * 100, 1) . "%"
        : "—";

    if ($stats["losses"] > 0) {
        $ratio = number_format($stats["wins"] / $stats["losses"], 2);
    } elseif ($stats["wins"] > 0) {
        $ratio = "∞";
    } else {
        $ratio = "—";
    }

    echo '<section class="blackjack-stats-panel">';
    echo '<div class="blackjack-stats-heading">';
    echo '<div>';
    echo '<h2>Your Blackjack Stats</h2>';
    echo '<p>Pushes do not count against win rate or streaks.</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="blackjack-stats-grid">';

    $cards = [
        ["Hands Played", number_format($stats["hands"])],
        ["Record", $stats["wins"] . "-" . $stats["losses"] . "-" . $stats["pushes"]],
        ["Win Rate", $winRate],
        ["W/L Ratio", $ratio],
        ["Blackjacks", number_format($stats["blackjacks"])],
        ["Current Streak", bj_streak_text($stats)],
        ["Best Win Streak", number_format($stats["best_win_streak"])],
        ["Net Profit", bj_signed_size($stats["net_profit"])],
        ["Biggest Win", $stats["biggest_win"] > 0 ? mksize($stats["biggest_win"]) : "—"],
        ["Total Wagered", mksize($stats["wagered"])],
    ];

    foreach ($cards as $card) {
        echo '<div class="blackjack-stat-card">';
        echo '<span class="blackjack-stat-label">' . bj_h((string)$card[0]) . '</span>';
        echo '<strong class="blackjack-stat-value">' . bj_h((string)$card[1]) . '</strong>';
        echo '</div>';
    }

    echo '</div>';

    $leaders = bj_leaderboard(10);

    echo '<details class="blackjack-leaderboard">';
    echo '<summary>🏆 Leaderboard</summary>';

    if (!$leaders) {
        echo '<p class="blackjack-empty-stats">No completed hands yet.</p>';
    } else {
        echo '<div class="blackjack-leaderboard-wrap">';
        echo '<table class="blackjack-leaderboard-table">';
        echo '<thead><tr>';
        echo '<th>#</th><th>Player</th><th>Hands</th><th>Record</th>';
        echo '<th>Win Rate</th><th>Blackjacks</th><th>Net</th><th>Biggest Win</th>';
        echo '</tr></thead><tbody>';

        foreach ($leaders as $index => $leader) {
            $wins = (int)$leader["wins"];
            $losses = (int)$leader["losses"];
            $pushes = (int)$leader["pushes"];
            $leaderDecisions = $wins + $losses;
            $leaderRate = $leaderDecisions > 0
                ? number_format(($wins / $leaderDecisions) * 100, 1) . "%"
                : "—";

            $isCurrent = (int)$leader["id"] === $userId
                ? ' class="blackjack-leaderboard-you"'
                : '';

            echo '<tr' . $isCurrent . '>';
            echo '<td>' . ($index + 1) . '</td>';
            echo '<td>' . bj_h((string)$leader["username"]);
            if ((int)$leader["id"] === $userId) {
                echo ' <span class="blackjack-you-tag">YOU</span>';
            }
            echo '</td>';
            echo '<td>' . number_format((int)$leader["hands"]) . '</td>';
            echo '<td>' . $wins . '-' . $losses . '-' . $pushes . '</td>';
            echo '<td>' . bj_h($leaderRate) . '</td>';
            echo '<td>' . number_format((int)$leader["blackjacks"]) . '</td>';
            echo '<td>' . bj_h(bj_signed_size((int)$leader["net_profit"])) . '</td>';
            echo '<td>' . ((int)$leader["biggest_win"] > 0
                ? bj_h(mksize((int)$leader["biggest_win"]))
                : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    echo '</details>';
    echo '</section>';

    global $CURUSER;

    if (!empty($CURUSER) && (int)$CURUSER["class"] >= 7) {
        $notice = "";

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!empty($_SESSION["blackjack_admin_notice"])) {
            $notice = (string)$_SESSION["blackjack_admin_notice"];
            unset($_SESSION["blackjack_admin_notice"]);
        }

        $players = bj_admin_stat_players();

        echo '<section class="blackjack-admin-zone" aria-label="Blackjack administration">';
        echo '<div class="blackjack-admin-badge">ADMIN ONLY</div>';
        echo '<h3>Reset Player Blackjack Stats</h3>';
        echo '<p>Only do this at the request of the user. This deletes their blackjack history.</p>';

        if ($notice !== "") {
            echo '<div class="blackjack-admin-notice">' . bj_h($notice) . '</div>';
        }

        if ($players) {
            echo '<form class="blackjack-admin-reset-form" method="post" '
                . 'action="blackjack.php" autocomplete="off">';
            echo '<input type="hidden" name="csrf" value="'
                . bj_h(bj_csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="reset_stats">';
            echo '<select name="target_user_id" class="blackjack-admin-player" required>';
            echo '<option value="">Select player...</option>';

            foreach ($players as $player) {
                echo '<option value="' . (int)$player["id"] . '">'
                    . bj_h((string)$player["username"])
                    . ' — ' . number_format((int)$player["hands"])
                    . ' hand' . ((int)$player["hands"] === 1 ? '' : 's')
                    . '</option>';
            }

            echo '</select>';
            echo '<button class="blackjack-button blackjack-reset-button" '
                . 'type="submit">Reset Stats</button>';
            echo '</form>';
        } else {
            echo '<p class="blackjack-empty-stats">No player has completed blackjack hands.</p>';
        }

        echo '</section>';
    }
}

function bj_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION["blackjack_csrf"])) {
        $_SESSION["blackjack_csrf"] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION["blackjack_csrf"];
}

function bj_require_csrf(): void
{
    $posted = isset($_POST["csrf"]) ? (string)$_POST["csrf"] : "";
    $stored = bj_csrf_token();

    if ($posted === "" || !hash_equals($stored, $posted)) {
        show_error_msg(
            "Blackjack",
            "Your blackjack session expired. Reload the page and try again.",
            1
        );
    }
}

function bj_redirect_to_hand(int $gameId, string $animation = ""): void
{
    $location = "blackjack.php?hand=" . $gameId;

    if ($animation !== "") {
        $location .= "&anim=" . rawurlencode($animation);
    }

    header("Location: " . $location);
    exit;
}


/*
 * --------------------------------------------------------------------------
 * The database does NOT contain card definitions.
 * --------------------------------------------------------------------------
 */

function bj_deck(): array
{
    $deck = [];
    $suits = ["p", "b", "k", "c"];

    foreach ($suits as $suit) {
        for ($rank = 2; $rank <= 10; $rank++) {
            $code = $rank . $suit;

            $deck[$code] = [
                "value" => $rank,
                "pic"   => $rank . $suit . ".webp",
            ];
        }

        $deck["v" . $suit] = [
            "value" => 10,
            "pic"   => "v" . $suit . ".webp",
        ];

        $deck["d" . $suit] = [
            "value" => 10,
            "pic"   => "d" . $suit . ".webp",
        ];

        $deck["k" . $suit] = [
            "value" => 10,
            "pic"   => "k" . $suit . ".webp",
        ];

        $deck["t" . $suit] = [
            "value" => 1,
            "pic"   => "t" . $suit . ".webp",
        ];
    }

    return $deck;
}

function bj_shuffle_codes(array $codes): array
{
    $codes = array_values($codes);

    for ($i = count($codes) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);

        if ($i !== $j) {
            $tmp = $codes[$i];
            $codes[$i] = $codes[$j];
            $codes[$j] = $tmp;
        }
    }

    return $codes;
}

function bj_codes_to_string(array $codes): string
{
    return implode(",", $codes);
}

function bj_codes_from_string(string $value): array
{
    if (trim($value) === "") {
        return [];
    }

    return array_values(
        array_filter(
            array_map("trim", explode(",", $value)),
            static function ($item) {
                return $item !== "";
            }
        )
    );
}

function bj_draw(array $shoe, int &$shoePos): string
{
    if (!isset($shoe[$shoePos])) {
        throw new RuntimeException("The blackjack shoe is exhausted.");
    }

    $card = (string)$shoe[$shoePos];
    $shoePos++;

    return $card;
}

function bj_hand_value(array $cards, array $deck): int
{
    $total = 0;
    $aces = 0;

    foreach ($cards as $code) {
        if (!isset($deck[$code])) {
            throw new RuntimeException("Invalid card found in blackjack hand.");
        }

        $value = (int)$deck[$code]["value"];

        if ($value === 1) {
            $aces++;
            $total++;
        } else {
            $total += $value;
        }
    }

    while ($aces > 0 && $total + 10 <= 21) {
        $total += 10;
        $aces--;
    }

    return $total;
}

function bj_is_blackjack(array $cards, array $deck): bool
{
    return count($cards) === 2 && bj_hand_value($cards, $deck) === 21;
}


function bj_can_split(array $cards, array $deck): bool
{
    if (count($cards) !== 2) {
        return false;
    }

    if (!isset($deck[$cards[0]], $deck[$cards[1]])) {
        return false;
    }


    $rankOne = substr((string)$cards[0], 0, 1);
    $rankTwo = substr((string)$cards[1], 0, 1);

    return $rankOne === $rankTwo;
}

function bj_encode_split_state(array $state): string
{
    return "S;"
        . (int)$state["active"] . ";"
        . (string)$state["status"][0] . ";"
        . (string)$state["status"][1] . ";"
        . bj_codes_to_string($state["hands"][0]) . ";"
        . bj_codes_to_string($state["hands"][1]) . ";"
        . (int)$state["base_wager"];
}

function bj_decode_split_state(string $value): ?array
{
    if (strncmp($value, "S;", 2) !== 0) {
        return null;
    }

    $parts = explode(";", $value, 7);

    if (count($parts) !== 7) {
        throw new RuntimeException("Invalid split-hand state.");
    }

    return [
        "active" => max(0, min(1, (int)$parts[1])),
        "status" => [
            (string)$parts[2],
            (string)$parts[3],
        ],
        "hands" => [
            bj_codes_from_string((string)$parts[4]),
            bj_codes_from_string((string)$parts[5]),
        ],
        "base_wager" => (int)$parts[6],
    ];
}

function bj_split_hand_result(
    array $cards,
    int $dealerValue,
    bool $dealerBlackjack,
    array $deck
): string {
    $value = bj_hand_value($cards, $deck);

    if ($dealerBlackjack) {
        return "loss";
    }

    if ($value > 21) {
        return "loss";
    }

    if ($dealerValue > 21) {
        return "win";
    }

    if ($value > $dealerValue) {
        return "win";
    }

    if ($value === $dealerValue) {
        return "push";
    }

    return "loss";
}


/*
 * --------------------------------------------------------------------------
 * Game storage
 * --------------------------------------------------------------------------
 */

function bj_get_active_game(
    mysqli $db,
    int $userId,
    bool $forUpdate = false
): ?array {
    $sql =
        "SELECT *
         FROM blackjack_games
         WHERE user_id = " . $userId . "
           AND active_key = " . sqlesc("house:" . $userId) . "
           AND status = 'playing'
         LIMIT 1";

    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    return bj_query_one($db, $sql);
}

function bj_get_game(
    mysqli $db,
    int $gameId,
    int $userId
): ?array {
    return bj_query_one(
        $db,
        "SELECT *
         FROM blackjack_games
         WHERE id = " . $gameId . "
           AND user_id = " . $userId . "
         LIMIT 1"
    );
}

function bj_parse_wager(
    array $post,
    int $minBet,
    int $maxBet
): int {
    if (
        isset($post["wager_bytes"])
        && ctype_digit((string)$post["wager_bytes"])
        && (int)$post["wager_bytes"] > 0
    ) {
        $wager = (int)$post["wager_bytes"];
    } else {
        $amountRaw = isset($post["wager_amount"])
            ? trim((string)$post["wager_amount"])
            : "";

        $unit = isset($post["wager_unit"])
            ? strtoupper((string)$post["wager_unit"])
            : "GB";

        if ($amountRaw === "" || !ctype_digit($amountRaw) || (int)$amountRaw <= 0) {
            throw new RuntimeException("Custom wagers must be a whole number greater than zero.");
        }

        $amount = (int)$amountRaw;

        if ($unit === "MB") {
            $multiplier = 1024 * 1024;
        } elseif ($unit === "GB") {
            $multiplier = 1024 * 1024 * 1024;
        } else {
            throw new RuntimeException("Invalid wager unit.");
        }

        $wager = (int)round($amount * $multiplier);
    }

    $wholeMb = 1024 * 1024;

    if ($wager % $wholeMb !== 0) {
        throw new RuntimeException("Blackjack wagers must be in whole MB amounts.");
    }

    if ($wager < $minBet) {
        throw new RuntimeException(
            "Minimum wager is " . mksize($minBet) . "."
        );
    }

    if ($wager > $maxBet) {
        throw new RuntimeException(
            "Maximum wager is " . mksize($maxBet) . "."
        );
    }

    return $wager;
}

function bj_update_playing_hand(
    mysqli $db,
    array $game,
    array $playerCards,
    array $dealerCards,
    int $shoePos,
    int $playerValue,
    int $dealerValue,
    ?string $playerCardsStored = null
): void {
    $storedCards = $playerCardsStored !== null
        ? $playerCardsStored
        : bj_codes_to_string($playerCards);

    $db->query(
        "UPDATE blackjack_games
         SET player_cards = " . sqlesc($storedCards) . ",
             dealer_cards = " . sqlesc(bj_codes_to_string($dealerCards)) . ",
             shoe_pos = " . $shoePos . ",
             player_points = " . $playerValue . ",
             dealer_points = " . $dealerValue . ",
             updated_at = " . sqlesc(get_date_time()) . "
         WHERE id = " . (int)$game["id"] . "
           AND status = 'playing'"
    );
}

function bj_settle_locked(
    mysqli $db,
    array $game,
    array $deck,
    bool $playDealer
): array {
    $wager = (int)$game["wager"];
    $userId = (int)$game["user_id"];
    $dealerCards = bj_codes_from_string((string)$game["dealer_cards"]);
    $shoe = bj_codes_from_string((string)$game["shoe"]);
    $shoePos = (int)$game["shoe_pos"];
    $dealerValue = bj_hand_value($dealerCards, $deck);
    $dealerBlackjack = bj_is_blackjack($dealerCards, $deck);
    $split = bj_decode_split_state((string)$game["player_cards"]);

    if ($split !== null) {
        $anyLiveHand = false;

        foreach ($split["hands"] as $hand) {
            if (bj_hand_value($hand, $deck) <= 21) {
                $anyLiveHand = true;
                break;
            }
        }

        if ($playDealer && !$dealerBlackjack && $anyLiveHand) {
            while ($dealerValue < 17) {
                $dealerCards[] = bj_draw($shoe, $shoePos);
                $dealerValue = bj_hand_value($dealerCards, $deck);
            }
        }

        $baseWager = (int)$split["base_wager"];
        $payout = 0;
        $handResults = [];

        foreach ($split["hands"] as $hand) {
            $handResult = bj_split_hand_result(
                $hand,
                $dealerValue,
                $dealerBlackjack,
                $deck
            );

            $handResults[] = $handResult;

            if ($handResult === "win") {
                $payout += $baseWager * 2;
            } elseif ($handResult === "push") {
                $payout += $baseWager;
            }
        }

        $profit = $payout - ($baseWager * 2);

        if ($profit > 0) {
            $result = "win";
        } elseif ($profit < 0) {
            $result = "loss";
        } else {
            $result = "push";
        }

        if ($payout > 0) {
            $db->query(
                "UPDATE users
                 SET uploaded = uploaded + " . $payout . "
                 WHERE id = " . $userId
            );
        }

        $now = get_date_time();
        $playerPoints = bj_hand_value($split["hands"][0], $deck);

        $db->query(
            "UPDATE blackjack_games
             SET active_key = NULL,
                 status = 'settled',
                 player_cards = " . sqlesc(bj_encode_split_state($split)) . ",
                 dealer_cards = " . sqlesc(bj_codes_to_string($dealerCards)) . ",
                 shoe_pos = " . $shoePos . ",
                 player_points = " . $playerPoints . ",
                 dealer_points = " . $dealerValue . ",
                 result = " . sqlesc($result) . ",
                 payout = " . $payout . ",
                 profit = " . $profit . ",
                 updated_at = " . sqlesc($now) . ",
                 settled_at = " . sqlesc($now) . "
             WHERE id = " . (int)$game["id"] . "
               AND status = 'playing'"
        );

        $game["active_key"] = null;
        $game["status"] = "settled";
        $game["player_cards"] = bj_encode_split_state($split);
        $game["dealer_cards"] = bj_codes_to_string($dealerCards);
        $game["shoe_pos"] = $shoePos;
        $game["player_points"] = $playerPoints;
        $game["dealer_points"] = $dealerValue;
        $game["result"] = $result;
        $game["payout"] = $payout;
        $game["profit"] = $profit;

        return $game;
    }

    $playerCards = bj_codes_from_string((string)$game["player_cards"]);
    $playerValue = bj_hand_value($playerCards, $deck);
    $playerBlackjack = bj_is_blackjack($playerCards, $deck);

    if (
        $playDealer
        && !$playerBlackjack
        && !$dealerBlackjack
        && $playerValue <= 21
    ) {
        while ($dealerValue < 17) {
            $dealerCards[] = bj_draw($shoe, $shoePos);
            $dealerValue = bj_hand_value($dealerCards, $deck);
        }
    }

    $result = "loss";
    $payout = 0;
    $profit = -$wager;

    if ($playerBlackjack && $dealerBlackjack) {
        $result = "push";
        $payout = $wager;
        $profit = 0;
    } elseif ($playerBlackjack) {
        $result = "blackjack";
        $profit = intdiv($wager * 3, 2);
        $payout = $wager + $profit;
    } elseif ($dealerBlackjack) {
        $result = "loss";
    } elseif ($playerValue > 21) {
        $result = "loss";
    } elseif ($dealerValue > 21) {
        $result = "win";
        $payout = $wager * 2;
        $profit = $wager;
    } elseif ($playerValue > $dealerValue) {
        $result = "win";
        $payout = $wager * 2;
        $profit = $wager;
    } elseif ($playerValue === $dealerValue) {
        $result = "push";
        $payout = $wager;
        $profit = 0;
    }

    if ($payout > 0) {
        $db->query(
            "UPDATE users
             SET uploaded = uploaded + " . $payout . "
             WHERE id = " . $userId
        );
    }

    $now = get_date_time();

    $db->query(
        "UPDATE blackjack_games
         SET active_key = NULL,
             status = 'settled',
             player_cards = " . sqlesc(bj_codes_to_string($playerCards)) . ",
             dealer_cards = " . sqlesc(bj_codes_to_string($dealerCards)) . ",
             shoe_pos = " . $shoePos . ",
             player_points = " . $playerValue . ",
             dealer_points = " . $dealerValue . ",
             result = " . sqlesc($result) . ",
             payout = " . $payout . ",
             profit = " . $profit . ",
             updated_at = " . sqlesc($now) . ",
             settled_at = " . sqlesc($now) . "
         WHERE id = " . (int)$game["id"] . "
           AND status = 'playing'"
    );

    $game["active_key"] = null;
    $game["status"] = "settled";
    $game["player_cards"] = bj_codes_to_string($playerCards);
    $game["dealer_cards"] = bj_codes_to_string($dealerCards);
    $game["shoe_pos"] = $shoePos;
    $game["player_points"] = $playerValue;
    $game["dealer_points"] = $dealerValue;
    $game["result"] = $result;
    $game["payout"] = $payout;
    $game["profit"] = $profit;

    return $game;
}

function bj_start_hand(
    int $userId,
    int $wager,
    array $deck
): int {
    $db = bj_db();
    $db->begin_transaction();

    try {
        $user = bj_query_one(
            $db,
            "SELECT uploaded
             FROM users
             WHERE id = " . $userId . "
             LIMIT 1
             FOR UPDATE"
        );

        if (!$user) {
            throw new RuntimeException("Your account could not be loaded.");
        }

        $active = bj_get_active_game($db, $userId, true);

        if ($active) {
            $db->commit();
            return (int)$active["id"];
        }

        $balance = (int)$user["uploaded"];

        if ($balance < $wager) {
            throw new RuntimeException(
                "You do not have enough uploaded credit for that wager."
            );
        }

        $shoe = bj_shuffle_codes(array_keys($deck));
        $shoePos = 0;

        // Standard deal order: player, dealer, player, dealer.
        $playerCards = [];
        $dealerCards = [];

        $playerCards[] = bj_draw($shoe, $shoePos);
        $dealerCards[] = bj_draw($shoe, $shoePos);
        $playerCards[] = bj_draw($shoe, $shoePos);
        $dealerCards[] = bj_draw($shoe, $shoePos);

        $playerValue = bj_hand_value($playerCards, $deck);
        $dealerValue = bj_hand_value($dealerCards, $deck);

        $db->query(
            "UPDATE users
             SET uploaded = uploaded - " . $wager . "
             WHERE id = " . $userId
        );

        $now = get_date_time();

        $db->query(
            "INSERT INTO blackjack_games
                (
                    user_id,
                    active_key,
                    wager,
                    status,
                    shoe,
                    shoe_pos,
                    player_cards,
                    dealer_cards,
                    player_points,
                    dealer_points,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    " . $userId . ",
                    " . sqlesc("house:" . $userId) . ",
                    " . $wager . ",
                    'playing',
                    " . sqlesc(bj_codes_to_string($shoe)) . ",
                    " . $shoePos . ",
                    " . sqlesc(bj_codes_to_string($playerCards)) . ",
                    " . sqlesc(bj_codes_to_string($dealerCards)) . ",
                    " . $playerValue . ",
                    " . $dealerValue . ",
                    " . sqlesc($now) . ",
                    " . sqlesc($now) . "
                )"
        );

        $gameId = (int)$db->insert_id;

        $game = bj_get_game($db, $gameId, $userId);

        if (!$game) {
            throw new RuntimeException(
                "The new blackjack hand could not be loaded."
            );
        }

        if (
            bj_is_blackjack($playerCards, $deck)
            || bj_is_blackjack($dealerCards, $deck)
        ) {
            bj_settle_locked($db, $game, $deck, false);
        }

        $db->commit();

        return $gameId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function bj_play_action(
    int $userId,
    string $action,
    array $deck,
    int $maxBet
): int {
    $db = bj_db();
    $db->begin_transaction();

    try {
        $game = bj_get_active_game($db, $userId, true);

        if (!$game) {
            throw new RuntimeException("There is no active blackjack hand.");
        }

        $gameId = (int)$game["id"];
        $dealerCards = bj_codes_from_string((string)$game["dealer_cards"]);
        $shoe = bj_codes_from_string((string)$game["shoe"]);
        $shoePos = (int)$game["shoe_pos"];
        $dealerValue = bj_hand_value($dealerCards, $deck);
        $split = bj_decode_split_state((string)$game["player_cards"]);

        if ($action === "double") {
            if ($split !== null) {
                throw new RuntimeException("Double Down is not available after splitting yet.");
            }

            $playerCards = bj_codes_from_string((string)$game["player_cards"]);

            if (count($playerCards) !== 2) {
                throw new RuntimeException("Double Down is only available on your first two cards.");
            }

            $baseWager = (int)$game["wager"];
            $doubledWager = $baseWager * 2;

            if ($doubledWager > $maxBet) {
                throw new RuntimeException(
                    "Doubling would exceed the maximum wager of " . mksize($maxBet) . "."
                );
            }

            $user = bj_query_one(
                $db,
                "SELECT uploaded
                 FROM users
                 WHERE id = " . $userId . "
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$user || (int)$user["uploaded"] < $baseWager) {
                throw new RuntimeException(
                    "You need another " . mksize($baseWager)
                    . " of uploaded credit to double down."
                );
            }

            $db->query(
                "UPDATE users
                 SET uploaded = uploaded - " . $baseWager . "
                 WHERE id = " . $userId
            );

            $playerCards[] = bj_draw($shoe, $shoePos);
            $playerValue = bj_hand_value($playerCards, $deck);

            $game["wager"] = $doubledWager;
            $game["player_cards"] = bj_codes_to_string($playerCards);
            $game["dealer_cards"] = bj_codes_to_string($dealerCards);
            $game["shoe_pos"] = $shoePos;
            $game["player_points"] = $playerValue;
            $game["dealer_points"] = $dealerValue;

            $db->query(
                "UPDATE blackjack_games
                 SET wager = " . $doubledWager . ",
                     player_cards = " . sqlesc(bj_codes_to_string($playerCards)) . ",
                     shoe_pos = " . $shoePos . ",
                     player_points = " . $playerValue . ",
                     updated_at = " . sqlesc(get_date_time()) . "
                 WHERE id = " . $gameId . "
                   AND status = 'playing'"
            );

            bj_settle_locked($db, $game, $deck, $playerValue <= 21);
        } elseif ($action === "split") {
            if ($split !== null) {
                throw new RuntimeException("This hand has already been split.");
            }

            $playerCards = bj_codes_from_string((string)$game["player_cards"]);

            if (!bj_can_split($playerCards, $deck)) {
                throw new RuntimeException("This hand cannot be split.");
            }

            $baseWager = (int)$game["wager"];
            $splitTotal = $baseWager * 2;

            $user = bj_query_one(
                $db,
                "SELECT uploaded
                 FROM users
                 WHERE id = " . $userId . "
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$user || (int)$user["uploaded"] < $baseWager) {
                throw new RuntimeException(
                    "You need another " . mksize($baseWager)
                    . " of uploaded credit to split this hand."
                );
            }

            $db->query(
                "UPDATE users
                 SET uploaded = uploaded - " . $baseWager . "
                 WHERE id = " . $userId
            );

            $handOne = [$playerCards[0], bj_draw($shoe, $shoePos)];
            $handTwo = [$playerCards[1], bj_draw($shoe, $shoePos)];

            $splitAces = (int)$deck[$playerCards[0]]["value"] === 1;
            $handOneDone = $splitAces || bj_hand_value($handOne, $deck) >= 21;
            $handTwoDone = $splitAces || bj_hand_value($handTwo, $deck) >= 21;

            $activeHand = $handOneDone && !$handTwoDone ? 1 : 0;

            $split = [
                "active" => $activeHand,
                "status" => [
                    $handOneDone ? "done" : "play",
                    $handTwoDone ? "done" : "play",
                ],
                "hands" => [$handOne, $handTwo],
                "base_wager" => $baseWager,
            ];

            $db->query(
                "UPDATE blackjack_games
                 SET wager = " . $splitTotal . ",
                     player_cards = " . sqlesc(bj_encode_split_state($split)) . ",
                     shoe_pos = " . $shoePos . ",
                     player_points = " . bj_hand_value($handOne, $deck) . ",
                     updated_at = " . sqlesc(get_date_time()) . "
                 WHERE id = " . $gameId . "
                   AND status = 'playing'"
            );

            $game["wager"] = $splitTotal;
            $game["player_cards"] = bj_encode_split_state($split);
            $game["shoe_pos"] = $shoePos;

            if (
                $splitAces
                || ($split["status"][0] === "done" && $split["status"][1] === "done")
            ) {
                bj_settle_locked($db, $game, $deck, true);
            }
        } elseif ($split !== null && ($action === "hit" || $action === "stand")) {
            $active = (int)$split["active"];

            if (!isset($split["hands"][$active])) {
                throw new RuntimeException("Invalid split hand.");
            }

            if ((string)$split["status"][$active] !== "play") {
                throw new RuntimeException("That split hand is already complete.");
            }

            if ($action === "hit") {
                $split["hands"][$active][] = bj_draw($shoe, $shoePos);
                $value = bj_hand_value($split["hands"][$active], $deck);

                if ($value >= 21) {
                    $split["status"][$active] = "done";
                }
            } else {
                $split["status"][$active] = "done";
            }

            if ($split["status"][$active] === "done") {
                $other = $active === 0 ? 1 : 0;

                if ((string)$split["status"][$other] === "play") {
                    $split["active"] = $other;
                }
            }

            $allDone = $split["status"][0] === "done"
                && $split["status"][1] === "done";

            $currentHand = $split["hands"][(int)$split["active"]];
            $currentValue = bj_hand_value($currentHand, $deck);

            $game["player_cards"] = bj_encode_split_state($split);
            $game["dealer_cards"] = bj_codes_to_string($dealerCards);
            $game["shoe_pos"] = $shoePos;
            $game["player_points"] = $currentValue;
            $game["dealer_points"] = $dealerValue;

            if ($allDone) {
                bj_settle_locked($db, $game, $deck, true);
            } else {
                bj_update_playing_hand(
                    $db,
                    $game,
                    $currentHand,
                    $dealerCards,
                    $shoePos,
                    $currentValue,
                    $dealerValue,
                    bj_encode_split_state($split)
                );
            }
        } elseif ($action === "hit") {
            $playerCards = bj_codes_from_string(
                (string)$game["player_cards"]
            );

            $playerCards[] = bj_draw($shoe, $shoePos);

            $playerValue = bj_hand_value($playerCards, $deck);

            $game["player_cards"] = bj_codes_to_string($playerCards);
            $game["dealer_cards"] = bj_codes_to_string($dealerCards);
            $game["shoe_pos"] = $shoePos;
            $game["player_points"] = $playerValue;
            $game["dealer_points"] = $dealerValue;

            if ($playerValue >= 21) {
                bj_settle_locked(
                    $db,
                    $game,
                    $deck,
                    $playerValue === 21
                );
            } else {
                bj_update_playing_hand(
                    $db,
                    $game,
                    $playerCards,
                    $dealerCards,
                    $shoePos,
                    $playerValue,
                    $dealerValue
                );
            }
        } elseif ($action === "stand") {
            bj_settle_locked($db, $game, $deck, true);
        } else {
            throw new RuntimeException("Invalid blackjack action.");
        }

        $db->commit();

        return $gameId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}


/*
 * --------------------------------------------------------------------------
 * Rendering
 * --------------------------------------------------------------------------
 */

function bj_styles(): void
{
    echo <<<'CSS'
<style>
/* Casino atmosphere for the blackjack page.
   Upload casino-background.webp to /images/blackjack/casino-background.webp */
body{
    background:
        linear-gradient(rgba(4,8,11,.64),rgba(4,8,11,.82)),
        url("images/blackjack/casino-background.webp") center top / cover fixed no-repeat !important;
}

#blackjackApp{
    width:min(900px,calc(100% - 20px));
    margin:0 auto;
    text-align:center;
}

#blackjackApp *{
    box-sizing:border-box;
}

#blackjackApp .blackjack-panel{
    margin:16px auto;
    padding:22px;
    border:1px solid rgba(128,128,128,.35);
    border-radius:16px;
    background:rgba(7,12,16,.78);
    backdrop-filter:blur(3px);
}

#blackjackApp .blackjack-table{
    position:relative;
    margin:16px auto;
    padding:24px 18px;
    border:6px solid rgba(57,38,20,.78);
    border-radius:40px;
    background:radial-gradient(
        circle at center,
        rgba(26,112,64,.98),
        rgba(8,61,35,.98)
    );
    color:#fff;
    box-shadow:
        inset 0 0 28px rgba(0,0,0,.30),
        0 8px 22px rgba(0,0,0,.18);
}

#blackjackApp .blackjack-hands{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    margin-top:18px;
}

#blackjackApp .blackjack-hand{
    min-height:175px;
    padding:16px 10px;
    border:1px solid rgba(255,255,255,.18);
    border-radius:16px;
    background:rgba(0,0,0,.16);
}

#blackjackApp .blackjack-hand h2{
    margin:0 0 10px;
    color:#fff;
}

#blackjackApp .blackjack-split-active{
    outline:2px solid rgba(255,215,0,.72);
    box-shadow:0 0 16px rgba(255,215,0,.18);
}

#blackjackApp .blackjack-cards img,
#blackjackApp .blackjack-card-back{
    display:inline-block;
    width:71px;
    height:96px;
    margin:4px;
    vertical-align:middle;
    border-radius:5px;
    box-shadow:0 5px 10px rgba(0,0,0,.40);
}

#blackjackApp .blackjack-card-back{
    border:4px solid #eee;
    background:
        repeating-linear-gradient(
            45deg,
            #18263a,
            #18263a 6px,
            #314866 6px,
            #314866 12px
        );
}


#blackjackApp .blackjack-card-animated{
    opacity:0;
    transform:translateY(-42px) rotate(-7deg) scale(.92);
    transition:
        transform .34s cubic-bezier(.2,.9,.3,1.15),
        opacity .22s ease-out;
    will-change:transform,opacity;
}

#blackjackApp .blackjack-card-animated.blackjack-card-show{
    opacity:1;
    transform:translateY(0) rotate(0deg) scale(1);
}

#blackjackApp .blackjack-card-flip{
    transform-style:preserve-3d;
    backface-visibility:hidden;
}

#blackjackApp .blackjack-card-flip.blackjack-card-show{
    animation:blackjackHoleFlip .46s ease-out both;
}

@keyframes blackjackHoleFlip{
    0%{
        opacity:0;
        transform:perspective(700px) rotateY(90deg) scale(.96);
    }
    55%{
        opacity:1;
        transform:perspective(700px) rotateY(-10deg) scale(1.02);
    }
    100%{
        opacity:1;
        transform:perspective(700px) rotateY(0deg) scale(1);
    }
}

@media (prefers-reduced-motion: reduce){
    #blackjackApp .blackjack-card-animated{
        opacity:1;
        transform:none;
        transition:none;
        animation:none !important;
    }
}

#blackjackApp .blackjack-sound-control{
    display:flex;
    justify-content:flex-end;
    flex-wrap:wrap;
    gap:8px;
    margin:0 0 8px;
}

#blackjackApp button.blackjack-sound-toggle{
    appearance:none;
    -webkit-appearance:none;
    border:1px solid rgba(255,255,255,.20);
    border-radius:7px;
    background:rgba(0,0,0,.18);
    color:inherit;
    padding:6px 10px;
    font:inherit;
    font-size:13px;
    cursor:pointer;
}

#blackjackApp button.blackjack-sound-toggle:hover{
    background:rgba(255,255,255,.08);
}

#blackjackApp .blackjack-score{
    margin-top:8px;
    font-weight:bold;
}

#blackjackApp .blackjack-actions{
    display:flex;
    justify-content:center;
    align-items:center;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
}

#blackjackApp .blackjack-actions form{
    margin:0;
}

#blackjackApp .blackjack-chart-corner{
    position:absolute;
    top:14px;
    right:16px;
    z-index:2;
}

#blackjackApp button.blackjack-chart-button{
    min-width:auto !important;
    height:34px !important;
    padding:0 11px !important;
    font-size:12px !important;
    opacity:.86;
}

#blackjackApp button.blackjack-button{
    appearance:none !important;
    -webkit-appearance:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    min-width:130px !important;
    height:42px !important;
    margin:0 !important;
    padding:0 18px !important;
    border:1px solid rgba(255,255,255,.28) !important;
    border-radius:8px !important;
    background:linear-gradient(
        to bottom,
        #31435d,
        #182437
    ) !important;
    color:#fff !important;
    font-family:Arial,Helvetica,sans-serif !important;
    font-size:14px !important;
    font-weight:bold !important;
    line-height:1 !important;
    text-decoration:none !important;
    cursor:pointer !important;
    box-shadow:0 2px 5px rgba(0,0,0,.28) !important;
}

#blackjackApp button.blackjack-button:hover:not(:disabled){
    background:linear-gradient(
        to bottom,
        #3a506f,
        #21324b
    ) !important;
}

#blackjackApp button.blackjack-button:disabled{
    opacity:.42 !important;
    cursor:not-allowed !important;
}

#blackjackApp button.blackjack-button.blackjack-ready{
    box-shadow:
        0 0 0 2px rgba(255,215,0,.40),
        0 0 14px rgba(255,215,0,.45) !important;
}

#blackjackApp .blackjack-quick-bets{
    display:flex;
    justify-content:center;
    flex-wrap:wrap;
    gap:8px;
    margin:16px 0;
}

#blackjackApp .blackjack-quick-bets button.blackjack-button{
    min-width:95px !important;
}

#blackjackApp .blackjack-quick-bets button.blackjack-button.blackjack-bet-selected{
    outline:2px solid currentColor;
    outline-offset:2px;
}


#blackjackApp .blackjack-credit{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    margin:14px auto 18px;
    padding:10px 16px;
    border:1px solid rgba(255,255,255,.22);
    border-radius:10px;
    background:rgba(255,255,255,.08);
    font-weight:bold;
}

#blackjackApp .blackjack-credit-label{
    font-size:.88em;
    font-weight:normal;
    opacity:.78;
}

#blackjackApp .blackjack-credit-value{
    font-size:1.28em;
    letter-spacing:.2px;
}

#blackjackApp .blackjack-custom{
    margin:18px auto 8px;
}

#blackjackApp #blackjackAmount,
#blackjackApp #blackjackUnit{
    height:40px;
    margin:4px;
    padding:6px 10px;
    border:1px solid rgba(128,128,128,.50);
    border-radius:7px;
    font:inherit;
}

#blackjackApp #blackjackAmount{
    width:150px;
}

#blackjackApp .blackjack-result{
    max-width:620px;
    margin:20px auto 0;
    padding:18px;
    border-radius:14px;
    background:rgba(0,0,0,.24);
}

#blackjackApp .blackjack-win{
    color:#baffba;
}

#blackjackApp .blackjack-loss{
    color:#ffb3b3;
}

#blackjackApp .blackjack-push{
    color:#ffe7a3;
}

#blackjackApp .blackjack-stats-panel{
    max-width:980px;
    margin:24px auto 0;
    padding:18px;
    border:1px solid rgba(128,128,128,.30);
    border-radius:14px;
    background:rgba(0,0,0,.18);
}

#blackjackApp .blackjack-stats-heading{
    display:block;
    text-align:center;
    margin-bottom:14px;
}

#blackjackApp .blackjack-stats-heading h2{
    margin:0 0 4px;
}

#blackjackApp .blackjack-stats-heading p{
    margin:0;
    opacity:.72;
}

#blackjackApp .blackjack-stats-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:10px;
}

#blackjackApp .blackjack-stat-card{
    min-width:0;
    padding:12px 10px;
    border:1px solid rgba(128,128,128,.24);
    border-radius:10px;
    background:rgba(255,255,255,.045);
    text-align:center;
}

#blackjackApp .blackjack-stat-label{
    display:block;
    margin-bottom:5px;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.45px;
    opacity:.70;
}

#blackjackApp .blackjack-stat-value{
    display:block;
    font-size:18px;
    line-height:1.2;
    overflow-wrap:anywhere;
}

#blackjackApp .blackjack-leaderboard{
    margin-top:16px;
    border-top:1px solid rgba(128,128,128,.24);
    padding-top:12px;
}

#blackjackApp .blackjack-leaderboard summary{
    cursor:pointer;
    font-weight:700;
    font-size:16px;
    user-select:none;
}

#blackjackApp .blackjack-leaderboard-wrap{
    margin-top:12px;
    overflow-x:auto;
}

#blackjackApp .blackjack-leaderboard-table{
    width:100%;
    border-collapse:collapse;
    white-space:nowrap;
}

#blackjackApp .blackjack-leaderboard-table th,
#blackjackApp .blackjack-leaderboard-table td{
    padding:9px 10px;
    border-bottom:1px solid rgba(128,128,128,.20);
    text-align:center;
}

#blackjackApp .blackjack-leaderboard-table th:nth-child(2),
#blackjackApp .blackjack-leaderboard-table td:nth-child(2){
    text-align:left;
}

#blackjackApp .blackjack-leaderboard-you{
    background:rgba(255,255,255,.07);
}

#blackjackApp .blackjack-you-tag{
    display:inline-block;
    margin-left:5px;
    padding:2px 5px;
    border:1px solid rgba(128,128,128,.45);
    border-radius:5px;
    font-size:10px;
    letter-spacing:.4px;
    vertical-align:middle;
}

#blackjackApp .blackjack-empty-stats{
    margin:12px 0 0;
    opacity:.75;
}

#blackjackApp .blackjack-admin-zone{
    max-width:980px;
    margin:48px auto 0;
    padding:20px 18px;
    border:1px solid rgba(255,120,120,.38);
    border-radius:14px;
    background:rgba(70,12,12,.22);
    text-align:center;
    box-shadow:0 0 0 1px rgba(0,0,0,.14) inset;
}

#blackjackApp .blackjack-admin-zone::before{
    content:"";
    display:block;
    width:72%;
    height:1px;
    margin:-35px auto 34px;
    background:rgba(255,255,255,.16);
}

#blackjackApp .blackjack-admin-badge{
    display:inline-block;
    margin-bottom:8px;
    padding:4px 9px;
    border:1px solid rgba(255,120,120,.50);
    border-radius:999px;
    font-size:11px;
    font-weight:bold;
    letter-spacing:.8px;
    text-transform:uppercase;
    opacity:.88;
}

#blackjackApp .blackjack-admin-zone h3{
    margin:0 0 6px;
}

#blackjackApp .blackjack-admin-zone p{
    margin:0 auto 12px;
    max-width:760px;
    opacity:.78;
}

#blackjackApp .blackjack-admin-reset-form{
    display:flex;
    justify-content:center;
    align-items:center;
    flex-wrap:wrap;
    gap:10px;
    margin-top:12px;
}

#blackjackApp .blackjack-admin-player{
    min-width:260px;
    height:42px;
    padding:0 10px;
    border:1px solid rgba(128,128,128,.50);
    border-radius:8px;
    font:inherit;
}

#blackjackApp button.blackjack-reset-button{
    border-color:rgba(255,120,120,.55) !important;
}

#blackjackApp .blackjack-admin-notice{
    max-width:720px;
    margin:10px auto 0;
    padding:10px 12px;
    border:1px solid rgba(140,220,140,.35);
    border-radius:8px;
    background:rgba(80,160,80,.12);
    font-weight:bold;
}

@media(max-width:850px){
    #blackjackApp .blackjack-stats-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:650px){
    #blackjackApp .blackjack-hands{
        grid-template-columns:1fr;
    }

    #blackjackApp .blackjack-table{
        border-width:4px;
        border-radius:26px;
        padding:18px 10px;
    }

    #blackjackApp .blackjack-cards img,
    #blackjackApp .blackjack-card-back{
        width:58px;
        height:79px;
    }
}
</style>
CSS;
}

function bj_page_start(): void
{
    stdhead("Blackjack");
    begin_frame("Blackjack");
    bj_styles();

    echo '<div id="blackjackApp">';
    echo '<div class="blackjack-sound-control">';
    echo '<button id="blackjackSoundToggle" class="blackjack-sound-toggle" '
        . 'type="button" aria-pressed="false">🔊 Sound On</button>';
    echo '<button id="blackjackAmbienceToggle" class="blackjack-sound-toggle" '
        . 'type="button" aria-pressed="false">🎰 Ambience On</button>';
    echo '</div>';

    echo <<<'JS'
<script>
(function(){
    "use strict";

    if(window.BlackjackUI){
        return;
    }

    var STORAGE_KEY = "blackjackSoundMuted";
    var AMBIENCE_STORAGE_KEY = "blackjackAmbienceMuted";
    var base = "sounds/blackjack/";
    var files = {
        card: base + "placing-playing-card.mp3",
        flip: base + "flip.wav",
        win: base + "win.wav",
        blackjack: base + "blackjack.wav",
        lose: base + "lose.wav",
        push: base + "push.wav"
    };

    var muted = false;
    var ambienceMuted = false;
    var busy = false;
    var audioContext = null;
    var audioBuffers = {};
    var fallbackAudio = {};

    // Continuous blackjack-room ambience.
    // HTMLAudio is used instead of Web Audio so the long MP3 can stream
    // without decoding the entire track into memory.
    var ambience = new Audio(base + "blackjack-room-ambience.mp3");
    ambience.preload = "auto";
    ambience.loop = true;
    ambience.volume = 1.00;

    try {
        muted = window.localStorage.getItem(STORAGE_KEY) === "1";
        ambienceMuted = window.localStorage.getItem(AMBIENCE_STORAGE_KEY) === "1";
    } catch (e) {}

    Object.keys(files).forEach(function(name){
        var audio = new Audio(files[name]);
        audio.preload = "auto";
        fallbackAudio[name] = audio;
    });

    function getAudioContext(){
        if(audioContext){
            return audioContext;
        }

        var AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if(!AudioContextClass){
            return null;
        }

        try {
            audioContext = new AudioContextClass();
        } catch (e) {
            audioContext = null;
        }

        return audioContext;
    }

    function preloadSounds(){
        var context = getAudioContext();
        if(!context || typeof window.fetch !== "function"){
            return;
        }

        Object.keys(files).forEach(function(name){
            fetch(files[name], {credentials:"same-origin"})
                .then(function(response){
                    if(!response.ok){
                        throw new Error("Sound load failed");
                    }
                    return response.arrayBuffer();
                })
                .then(function(buffer){
                    return context.decodeAudioData(buffer);
                })
                .then(function(decoded){
                    audioBuffers[name] = decoded;
                })
                .catch(function(){});
        });
    }

    function startAmbience(){
        if(muted || ambienceMuted){
            return;
        }

        ambience.volume = 1.00;

        if(ambience.paused){
            try {
                var ambiencePromise = ambience.play();
                if(ambiencePromise && typeof ambiencePromise.catch === "function"){
                    // Autoplay may be blocked until the first user gesture.
                    // unlockSound() retries on the next blackjack interaction.
                    ambiencePromise.catch(function(){});
                }
            } catch (e) {}
        }
    }

    function pauseAmbience(){
        try {
            ambience.pause();
        } catch (e) {}
    }

    function unlockSound(){
        if(muted){
            return;
        }

        var context = getAudioContext();
        if(context && context.state === "suspended"){
            try {
                var promise = context.resume();
                if(promise && typeof promise.catch === "function"){
                    promise.catch(function(){});
                }
            } catch (e) {}
        }

        startAmbience();
    }

    function playSound(name, volume){
        if(muted){
            return;
        }

        var context = getAudioContext();
        var level = typeof volume === "number" ? volume : 0.72;

        if(context && context.state === "running" && audioBuffers[name]){
            try {
                var source = context.createBufferSource();
                var gain = context.createGain();
                source.buffer = audioBuffers[name];
                gain.gain.value = level;
                source.connect(gain);
                gain.connect(context.destination);
                source.start(0);
                return;
            } catch (e) {}
        }

        if(fallbackAudio[name]){
            try {
                var sound = fallbackAudio[name].cloneNode(true);
                sound.volume = level;
                var promise = sound.play();
                if(promise && typeof promise.catch === "function"){
                    promise.catch(function(){});
                }
            } catch (e) {}
        }
    }

    function updateSoundButton(){
        var button = document.getElementById("blackjackSoundToggle");
        if(!button){
            return;
        }

        button.textContent = muted ? "🔇 Muted" : "🔊 Sound On";
        button.setAttribute("aria-pressed", muted ? "true" : "false");
        button.title = muted
            ? "Turn blackjack sounds on"
            : "Mute blackjack sounds";
    }

    function updateAmbienceButton(){
        var button = document.getElementById("blackjackAmbienceToggle");
        if(!button){
            return;
        }

        button.textContent = ambienceMuted ? "🔇 Ambience Off" : "🎰 Ambience On";
        button.setAttribute("aria-pressed", ambienceMuted ? "true" : "false");
        button.title = ambienceMuted
            ? "Turn room ambience on"
            : "Turn room ambience off";
    }

    function toggleAmbience(){
        ambienceMuted = !ambienceMuted;

        try {
            window.localStorage.setItem(AMBIENCE_STORAGE_KEY, ambienceMuted ? "1" : "0");
        } catch (e) {}

        updateAmbienceButton();

        if(ambienceMuted){
            pauseAmbience();
        } else if(!muted){
            startAmbience();
        }
    }

    function toggleSound(){
        muted = !muted;

        try {
            window.localStorage.setItem(STORAGE_KEY, muted ? "1" : "0");
        } catch (e) {}

        updateSoundButton();

        if(!muted){
            unlockSound();
            window.setTimeout(function(){
                playSound("card", 0.45);
            }, 20);
        } else {
            pauseAmbience();
        }
    }

    function customBytes(form){
        var amount = form.querySelector("#blackjackAmount");
        var unit = form.querySelector("#blackjackUnit");

        if(!amount || !unit){
            return 0;
        }

        var raw = String(amount.value || "").trim();
        if(!/^\d+$/.test(raw)){
            return 0;
        }

        var value = parseInt(raw, 10);
        if(!isFinite(value) || value <= 0){
            return 0;
        }

        var multiplier = unit.value === "GB"
            ? 1073741824
            : 1048576;

        return value * multiplier;
    }

    function updateCustomLimits(form){
        var amount = form.querySelector("#blackjackAmount");
        var unit = form.querySelector("#blackjackUnit");
        if(!amount || !unit){
            return;
        }

        var multiplier = unit.value === "GB"
            ? 1073741824
            : 1048576;

        var minBet = parseInt(form.getAttribute("data-min-bet"), 10) || 0;
        var maxBet = parseInt(form.getAttribute("data-max-bet"), 10) || 0;

        amount.min = String(Math.max(1, Math.ceil(minBet / multiplier)));
        amount.max = String(Math.floor(maxBet / multiplier));
    }

    function setDealState(form, bytes){
        var deal = form.querySelector("#blackjackDealButton");
        if(!deal){
            return;
        }

        var balance = parseInt(form.getAttribute("data-balance"), 10) || 0;
        var minBet = parseInt(form.getAttribute("data-min-bet"), 10) || 0;
        var maxBet = parseInt(form.getAttribute("data-max-bet"), 10) || 0;

        var valid = isFinite(bytes)
            && bytes >= minBet
            && bytes <= maxBet
            && bytes <= balance;

        deal.disabled = !valid;
        deal.classList.toggle("blackjack-ready", valid);
    }

    function clearQuickSelection(form){
        var buttons = form.querySelectorAll("[data-wager]");
        for(var i = 0; i < buttons.length; i++){
            buttons[i].classList.remove("blackjack-bet-selected");
            buttons[i].setAttribute("aria-pressed", "false");
        }
    }

    function showWagerInCustomFields(form, bytes){
        var amount = form.querySelector("#blackjackAmount");
        var unit = form.querySelector("#blackjackUnit");
        if(!amount || !unit){
            return;
        }

        var GB = 1073741824;
        var MB = 1048576;
        var useGb = bytes >= GB && bytes % GB === 0;

        unit.value = useGb ? "GB" : "MB";
        unit.dataset.previousUnit = unit.value;

        var multiplier = useGb ? GB : MB;
        amount.value = String(bytes / multiplier);

        updateCustomLimits(form);
    }

    function initWager(){
        var form = document.getElementById("blackjackWagerForm");
        if(!form){
            return;
        }

        var hidden = form.querySelector("#blackjackWagerBytes");
        var unit = form.querySelector("#blackjackUnit");

        updateCustomLimits(form);

        if(hidden && hidden.value !== ""){
            setDealState(form, parseInt(hidden.value, 10) || 0);
        } else {
            setDealState(form, customBytes(form));
        }
    }

    function cleanResponseUrl(url){
        try {
            var parsed = new URL(url, window.location.href);
            parsed.searchParams.delete("anim");
            return parsed.toString();
        } catch (e) {
            return url;
        }
    }

    function getAnimationFromUrl(url){
        try {
            return new URL(url, window.location.href).searchParams.get("anim") || "";
        } catch (e) {
            return "";
        }
    }

    function animateCurrent(animation){
        var root = document.getElementById("blackjackApp");
        if(!root){
            return;
        }

        var cards = root.querySelectorAll(
            ".blackjack-card-animated[data-card-sequence]"
        );

        var lastSequence = 1;

        for(var i = 0; i < cards.length; i++){
            (function(card){
                var sequence = parseInt(
                    card.getAttribute("data-card-sequence"),
                    10
                ) || 1;

                if(sequence > lastSequence){
                    lastSequence = sequence;
                }

                window.setTimeout(function(){
                    card.classList.add("blackjack-card-show");
                    var soundName = card.classList.contains("blackjack-card-flip")
                        ? "flip"
                        : "card";
                    playSound(soundName, 0.64);
                }, 110 + ((sequence - 1) * 170));
            })(cards[i]);
        }

        var resultBox = root.querySelector(".blackjack-result[data-result]");
        if(resultBox){
            var result = resultBox.getAttribute("data-result") || "";
            var outcomeDelay = cards.length
                ? 420 + ((lastSequence - 1) * 170)
                : 120;

            window.setTimeout(function(){
                if(result === "blackjack"){
                    playSound("blackjack", 0.82);
                } else if(result === "win"){
                    playSound("win", 0.78);
                } else if(result === "push"){
                    playSound("push", 0.68);
                } else if(result === "loss"){
                    playSound("lose", 0.66);
                }
            }, outcomeDelay);
        }
    }

    function setBusy(state){
        busy = state;
        var app = document.getElementById("blackjackApp");
        if(!app){
            return;
        }

        app.setAttribute("aria-busy", state ? "true" : "false");

        var buttons = app.querySelectorAll("button.blackjack-button");
        for(var i = 0; i < buttons.length; i++){
            if(state){
                buttons[i].setAttribute("data-bj-was-disabled", buttons[i].disabled ? "1" : "0");
                buttons[i].disabled = true;
            } else {
                var previous = buttons[i].getAttribute("data-bj-was-disabled");
                if(previous === "0"){
                    buttons[i].disabled = false;
                } else if(previous === "1"){
                    buttons[i].disabled = true;
                }
                if(previous !== null){
                    buttons[i].removeAttribute("data-bj-was-disabled");
                }
            }
        }
    }

    function swapBlackjack(html, responseUrl){
        var parser = new DOMParser();
        var incomingDocument = parser.parseFromString(html, "text/html");
        var incoming = incomingDocument.getElementById("blackjackApp");
        var current = document.getElementById("blackjackApp");

        if(!incoming || !current){
            throw new Error("Blackjack response could not be loaded.");
        }

        var animation = getAnimationFromUrl(responseUrl);
        current.innerHTML = incoming.innerHTML;

        updateSoundButton();
        updateAmbienceButton();
        initWager();
        animateCurrent(animation);

        if(window.history && window.history.replaceState){
            window.history.replaceState({}, "", cleanResponseUrl(responseUrl));
        }
    }

    function request(url, options){
        if(busy){
            return Promise.resolve();
        }

        setBusy(true);

        options = options || {};
        options.credentials = "same-origin";
        options.redirect = "follow";
        options.headers = options.headers || {};
        options.headers["X-Requested-With"] = "XMLHttpRequest";

        return fetch(url, options)
            .then(function(response){
                if(!response.ok){
                    throw new Error("Blackjack request failed (" + response.status + ").");
                }

                return response.text().then(function(html){
                    swapBlackjack(html, response.url || url);
                });
            })
            .catch(function(error){
                window.alert(error && error.message
                    ? error.message
                    : "Blackjack request failed.");
            })
            .finally(function(){
                setBusy(false);
            });
    }

    function submitForm(form){
        unlockSound();

        var data = new FormData(form);
        return request(window.location.pathname, {
            method: "POST",
            body: data
        });
    }

    function loadWagerScreen(){
        unlockSound();
        return request(window.location.pathname, {
            method: "GET"
        });
    }

    document.addEventListener("pointerdown", function(event){
        if(event.target.closest("#blackjackApp button")){
            unlockSound();
        }
    }, {passive:true});

    document.addEventListener("click", function(event){
        var toggle = event.target.closest("#blackjackSoundToggle");
        if(toggle){
            event.preventDefault();
            toggleSound();
            return;
        }

        var ambienceToggle = event.target.closest("#blackjackAmbienceToggle");
        if(ambienceToggle){
            event.preventDefault();
            toggleAmbience();
            return;
        }

        var quick = event.target.closest("#blackjackApp [data-wager]");
        if(quick && !quick.disabled){
            var form = quick.closest("#blackjackWagerForm");
            if(form){
                var hidden = form.querySelector("#blackjackWagerBytes");
                var bytes = parseInt(quick.getAttribute("data-wager") || "0", 10) || 0;

                clearQuickSelection(form);
                quick.classList.add("blackjack-bet-selected");
                quick.setAttribute("aria-pressed", "true");

                if(hidden){
                    hidden.value = String(bytes);
                }

                showWagerInCustomFields(form, bytes);
                setDealState(form, bytes);
            }
            return;
        }

        var changeBet = event.target.closest("#blackjackChangeBetButton");
        if(changeBet){
            event.preventDefault();
            loadWagerScreen();
        }
    });

    document.addEventListener("keydown", function(event){
        if(event.target.matches("#blackjackAmount")){
            if([".", ",", "e", "E", "+", "-"].indexOf(event.key) !== -1){
                event.preventDefault();
            }
        }
    });

    document.addEventListener("input", function(event){
        if(event.target.matches("#blackjackAmount")){
            var form = event.target.closest("#blackjackWagerForm");
            if(form){
                var hidden = form.querySelector("#blackjackWagerBytes");
                if(hidden){
                    hidden.value = "";
                }
                clearQuickSelection(form);
                setDealState(form, customBytes(form));
            }
        }
    });

    document.addEventListener("change", function(event){
        if(event.target.matches("#blackjackUnit")){
            var form = event.target.closest("#blackjackWagerForm");
            if(form){
                var hidden = form.querySelector("#blackjackWagerBytes");

                // Keep the visible whole number unchanged when switching units.
                // Example: 1 MB -> switch to GB -> display remains 1, now meaning 1 GB.
                updateCustomLimits(form);

                if(hidden){
                    hidden.value = "";
                }
                clearQuickSelection(form);
                setDealState(form, customBytes(form));
            }
        }
    });

    document.addEventListener("submit", function(event){
        var form = event.target;
        if(!(form instanceof HTMLFormElement)){
            return;
        }

        if(!form.closest("#blackjackApp")){
            return;
        }

        if(form.classList.contains("blackjack-admin-reset-form")){
            var player = form.querySelector(".blackjack-admin-player");
            var playerName = player && player.selectedIndex >= 0
                ? player.options[player.selectedIndex].text
                : "this player";

            if(!window.confirm(
                "Reset all blackjack stats for " + playerName + "?\n\n" +
                "This cannot be undone. Their upload balance will not change."
            )){
                event.preventDefault();
                return;
            }
        }

        event.preventDefault();
        submitForm(form);
    });

    window.BlackjackSound = {
        play: playSound,
        unlock: unlockSound,
        ambience: startAmbience,
        isMuted: function(){ return muted; },
        isAmbienceMuted: function(){ return ambienceMuted; }
    };

    window.BlackjackUI = {
        request: request,
        animate: animateCurrent,
        initWager: initWager
    };

    document.addEventListener("DOMContentLoaded", function(){
        preloadSounds();
        updateSoundButton();
        updateAmbienceButton();
        initWager();
        startAmbience();
    });
})();
</script>
JS;
}

function bj_page_end(): void
{
    echo '</div>';

    end_frame();
    stdfoot();
}

function bj_render_cards(
    array $cards,
    array $deck,
    bool $hideHole,
    string $hand,
    string $animation
): void {
    echo '<div class="blackjack-cards" data-hand="' . bj_h($hand) . '">';

    $lastIndex = count($cards) - 1;

    foreach ($cards as $index => $code) {
        $classes = [];
        $animate = false;
        $sequence = 0;

        if ($animation === "deal" && $index < 2) {
            $animate = true;

            if ($hand === "player") {
                $sequence = $index === 0 ? 1 : 3;
            } else {
                $sequence = $index === 0 ? 2 : 4;
            }
        } elseif (
            ($animation === "hit" || $animation === "resolve")
            && $hand === "player"
            && $index === $lastIndex
        ) {
            $animate = true;
            $sequence = 1;
        } elseif (
            ($animation === "dealer" || $animation === "resolve")
            && $hand === "dealer"
            && $index >= 1
        ) {
            $animate = true;
            $sequence = $animation === "resolve"
                ? $index + 1
                : $index;

            if ($index === 1) {
                $classes[] = "blackjack-card-flip";
            }
        }

        if ($animate) {
            $classes[] = "blackjack-card-animated";
        }

        $classAttr = $classes
            ? ' class="' . bj_h(implode(" ", $classes)) . '"'
            : '';

        $sequenceAttr = $animate
            ? ' data-card-sequence="' . $sequence . '"'
            : '';

        if ($hideHole && $index === 1) {
            echo '<span class="blackjack-card-back'
                . ($classes ? ' ' . bj_h(implode(" ", $classes)) : '')
                . '"' . $sequenceAttr
                . ' title="Hidden dealer card"></span>';
            continue;
        }

        if (!isset($deck[$code])) {
            continue;
        }

        echo '<img' . $classAttr . $sequenceAttr
            . ' src="images/blackjack/cards/'
            . bj_h((string)$deck[$code]["pic"])
            . '" alt="Playing card">';
    }

    echo '</div>';
}

function bj_action_form(
    string $label,
    string $action,
    string $buttonId
): void {
    echo '<form method="post" action="blackjack.php" autocomplete="off">';
    echo '<input type="hidden" name="csrf" value="'
        . bj_h(bj_csrf_token()) . '">';
    echo '<input type="hidden" name="action" value="'
        . bj_h($action) . '">';
    echo '<button id="' . bj_h($buttonId)
        . '" class="blackjack-button" type="submit">'
        . bj_h($label) . '</button>';
    echo '</form>';
}

function bj_render_wager_screen(
    int $userId,
    int $minBet,
    int $maxBet
): void {
    $balance = bj_balance($userId);
    $MB = 1024 * 1024;
    $GB = 1024 * 1024 * 1024;

    $quickBets = [
        $minBet,
        100 * $MB,
        250 * $MB,
        500 * $MB,
        1 * $GB,
        5 * $GB,
        10 * $GB,
        25 * $GB,
        50 * $GB,
        100 * $GB,
        200 * $GB,
        $maxBet,
    ];

    $quickBets = array_values(array_unique(array_map("intval", $quickBets)));
    sort($quickBets, SORT_NUMERIC);

    bj_page_start();

    echo '<div class="blackjack-panel">';
    echo '<h2 style="margin-top:0;">House Blackjack</h2>';
    echo '<p>Dealer stands on 17. Natural blackjack pays 3:2.</p>';
    echo '<div class="blackjack-credit">';
    echo '<span class="blackjack-credit-label">Current Balance</span>';
    echo '<span class="blackjack-credit-value">'
        . mksize($balance) . '</span>';
    echo '</div>';

    $minBetMbValue = max(1, (int)ceil($minBet / 1048576));
    $maxBetMbValue = (int)floor($maxBet / 1048576);

    echo '<form id="blackjackWagerForm" method="post" '
        . 'action="blackjack.php" autocomplete="off" '
        . 'data-balance="' . $balance . '" '
        . 'data-min-bet="' . $minBet . '" '
        . 'data-max-bet="' . $maxBet . '">';

    echo '<input type="hidden" name="csrf" value="'
        . bj_h(bj_csrf_token()) . '">';

    echo '<input type="hidden" name="action" value="start">';
    echo '<input id="blackjackWagerBytes" type="hidden" '
        . 'name="wager_bytes" value="">';

    echo '<div class="blackjack-quick-bets">';

    foreach ($quickBets as $index => $bet) {
        if ($bet < $minBet || $bet > $maxBet) {
            continue;
        }

        $disabled = $bet > $balance ? ' disabled' : '';

        echo '<button id="blackjackQuickBet' . $index
            . '" class="blackjack-button" type="button" '
            . 'data-wager="' . $bet . '" aria-pressed="false"' . $disabled . '>'
            . bj_h(mksize($bet)) . '</button>';
    }

    echo '</div>';

    echo '<div class="blackjack-custom">';
    echo '<input id="blackjackAmount" type="number" '
        . 'name="wager_amount" min="' . bj_h((string)$minBetMbValue) . '" '
        . 'max="' . bj_h((string)$maxBetMbValue) . '" '
        . 'step="1" value="' . bj_h((string)$minBetMbValue) . '" '
        . 'inputmode="numeric" autocomplete="off">';

    echo '<select id="blackjackUnit" name="wager_unit" '
        . 'autocomplete="off">';
    echo '<option value="MB" selected>MB</option>';
    echo '<option value="GB">GB</option>';
    echo '</select>';
    echo '</div>';

    echo '<p>Minimum: ' . mksize($minBet)
        . ' &nbsp; | &nbsp; Maximum: ' . mksize($maxBet) . '</p>';

    echo '<div class="blackjack-actions">';
    echo '<button id="blackjackDealButton" '
        . 'class="blackjack-button" type="submit" disabled>'
        . 'Deal Cards</button>';
    echo '<button class="blackjack-button" type="button" '
        . 'onclick="window.open(\'blackjack_split_chart.html\',\'blackjackSplitChart\','
        . '\'width=760,height=780,toolbar=no,location=no,status=no,menubar=no,'
        . 'scrollbars=yes,resizable=yes,left=40,top=30\');return false;">'
        . 'Split Chart</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';


    bj_render_stats($userId);
    bj_page_end();
}

function bj_render_hand(array $game, array $deck, string $animation): void
{
    $dealerCards = bj_codes_from_string(
        (string)$game["dealer_cards"]
    );

    $split = bj_decode_split_state((string)$game["player_cards"]);
    $balance = bj_balance((int)$game["user_id"]);

    bj_page_start();

    echo '<div class="blackjack-table">';
    echo '<div class="blackjack-chart-corner">';
    echo '<button class="blackjack-button blackjack-chart-button" type="button" '
        . 'onclick="window.open(\'blackjack_split_chart.html\',\'blackjackSplitChart\','
        . '\'width=760,height=780,toolbar=no,location=no,status=no,menubar=no,'
        . 'scrollbars=yes,resizable=yes,left=40,top=30\');return false;">'
        . 'Split Chart</button>';
    echo '</div>';
    echo '<h2 style="margin-top:0;">Blackjack</h2>';
    echo '<div class="blackjack-credit">';
    echo '<span class="blackjack-credit-label">Current Balance</span>';
    echo '<span class="blackjack-credit-value">'
        . mksize($balance) . '</span>';
    echo '</div>';

    if ($split !== null) {
        echo '<p>Total wager: <b>' . mksize((int)$game["wager"])
            . '</b> &nbsp; (' . mksize((int)$split["base_wager"])
            . ' per hand)</p>';
    } else {
        echo '<p>Wager: <b>' . mksize((int)$game["wager"]) . '</b></p>';
    }

    echo '<div class="blackjack-hands">';

    echo '<div class="blackjack-hand">';
    echo '<h2>House</h2>';
    bj_render_cards($dealerCards, $deck, true, "dealer", $animation);
    echo '<div class="blackjack-score">One card hidden</div>';
    echo '</div>';

    if ($split !== null) {
        foreach ($split["hands"] as $index => $hand) {
            $active = (int)$split["active"] === $index
                && (string)$split["status"][$index] === "play";

            echo '<div class="blackjack-hand'
                . ($active ? ' blackjack-split-active' : '')
                . '">';
            echo '<h2>Hand ' . ($index + 1)
                . ($active ? ' — Playing' : '') . '</h2>';
            bj_render_cards($hand, $deck, false, "player", $animation);
            echo '<div class="blackjack-score">Points: '
                . bj_hand_value($hand, $deck) . '</div>';
            echo '</div>';
        }
    } else {
        $playerCards = bj_codes_from_string((string)$game["player_cards"]);
        $playerValue = bj_hand_value($playerCards, $deck);

        echo '<div class="blackjack-hand">';
        echo '<h2>You</h2>';
        bj_render_cards($playerCards, $deck, false, "player", $animation);
        echo '<div class="blackjack-score">Points: '
            . $playerValue . '</div>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="blackjack-actions">';
    bj_action_form("Hit", "hit", "blackjackHitButton");
    bj_action_form("Stand", "stand", "blackjackStandButton");

    if ($split === null) {
        $playerCards = bj_codes_from_string((string)$game["player_cards"]);

        $doubleCost = (int)$game["wager"];
        $doubleTotal = $doubleCost * 2;

        if (
            count($playerCards) === 2
            && $balance >= $doubleCost
            && $doubleTotal <= $GLOBALS["blackjack_max_bet_runtime"]
        ) {
            bj_action_form(
                "Double Down — " . mksize($doubleCost),
                "double",
                "blackjackDoubleButton"
            );
        }

        $splitCost = (int)$game["wager"];
        // Split may temporarily exceed the configured table max for this hand only.
        $splitTotal = $splitCost * 2;

        if (
            bj_can_split($playerCards, $deck)
            && $balance >= $splitCost
        ) {
            bj_action_form(
                "Split — " . mksize((int)$game["wager"]),
                "split",
                "blackjackSplitButton"
            );
        }
    }

    echo '</div>';

    if ($split !== null) {
        echo '<p style="margin:14px 0 0;opacity:.78;">'
            . 'Split hands use one wager per hand. A 21 after splitting pays 1:1.'
            . '</p>';
    }

    echo '</div>';

    echo '<script>
(function(){
    "use strict";

    var cards = document.querySelectorAll(
        "#blackjackApp .blackjack-card-animated[data-card-sequence]"
    );

    for(var i = 0; i < cards.length; i++){
        (function(card){
            var sequence = parseInt(
                card.getAttribute("data-card-sequence"),
                10
            ) || 1;

            window.setTimeout(function(){
                card.classList.add("blackjack-card-show");
                if(window.BlackjackSound){
                    var soundName = card.classList.contains("blackjack-card-flip")
                        ? "flip"
                        : "card";
                    window.BlackjackSound.play(soundName, 0.64);
                }
            }, 110 + ((sequence - 1) * 170));
        })(cards[i]);
    }

    if(window.history && window.history.replaceState){
        var cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete("anim");
        window.history.replaceState({}, "", cleanUrl.toString());
    }
})();
</script>';

    bj_render_stats((int)$game["user_id"]);
    bj_page_end();
}

function bj_render_result(array $game, array $deck, string $animation): void
{
    $dealerCards = bj_codes_from_string(
        (string)$game["dealer_cards"]
    );

    $split = bj_decode_split_state((string)$game["player_cards"]);
    $result = (string)$game["result"];
    $profit = (int)$game["profit"];
    $balance = bj_balance((int)$game["user_id"]);
    $dealerValue = (int)$game["dealer_points"];
    $dealerBlackjack = bj_is_blackjack($dealerCards, $deck);

    bj_page_start();

    echo '<div class="blackjack-table">';
    echo '<h2 style="margin-top:0;">Blackjack</h2>';

    if ($split !== null) {
        echo '<p>Total wager: <b>' . mksize((int)$game["wager"])
            . '</b> &nbsp; (' . mksize((int)$split["base_wager"])
            . ' per hand)</p>';
    } else {
        echo '<p>Wager: <b>' . mksize((int)$game["wager"]) . '</b></p>';
    }

    echo '<div class="blackjack-hands">';

    echo '<div class="blackjack-hand">';
    echo '<h2>House</h2>';
    bj_render_cards($dealerCards, $deck, false, "dealer", $animation);
    echo '<div class="blackjack-score">Points: '
        . $dealerValue . '</div>';
    echo '</div>';

    if ($split !== null) {
        foreach ($split["hands"] as $index => $hand) {
            $handValue = bj_hand_value($hand, $deck);
            $handResult = bj_split_hand_result(
                $hand,
                $dealerValue,
                $dealerBlackjack,
                $deck
            );

            echo '<div class="blackjack-hand">';
            echo '<h2>Hand ' . ($index + 1) . '</h2>';
            bj_render_cards($hand, $deck, false, "player", $animation);
            echo '<div class="blackjack-score">Points: '
                . $handValue . '</div>';

            if ($handResult === "win") {
                echo '<div class="blackjack-win"><b>WIN +'
                    . mksize((int)$split["base_wager"]) . '</b></div>';
            } elseif ($handResult === "push") {
                echo '<div class="blackjack-push"><b>PUSH</b></div>';
            } else {
                echo '<div class="blackjack-loss"><b>LOSS -'
                    . mksize((int)$split["base_wager"]) . '</b></div>';
            }

            echo '</div>';
        }
    } else {
        $playerCards = bj_codes_from_string(
            (string)$game["player_cards"]
        );

        echo '<div class="blackjack-hand">';
        echo '<h2>You</h2>';
        bj_render_cards($playerCards, $deck, false, "player", $animation);
        echo '<div class="blackjack-score">Points: '
            . (int)$game["player_points"] . '</div>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="blackjack-result" data-result="' . bj_h($result) . '">';

    if ($split !== null) {
        if ($profit > 0) {
            echo '<h2 class="blackjack-win">SPLIT HANDS WIN</h2>';
            echo '<p><b>Net Profit: +' . mksize($profit) . '</b></p>';
        } elseif ($profit < 0) {
            echo '<h2 class="blackjack-loss">HOUSE WINS THE SPLIT</h2>';
            echo '<p><b>Net Loss: -' . mksize(abs($profit)) . '</b></p>';
        } else {
            echo '<h2 class="blackjack-push">SPLIT BREAKS EVEN</h2>';
            echo '<p>Your two hands finished even overall.</p>';
        }
    } elseif ($result === "blackjack") {
        echo '<h2 class="blackjack-win">BLACKJACK!</h2>';
        echo '<p>Natural blackjack pays 3:2.</p>';
        echo '<p><b>Profit: +' . mksize($profit) . '</b></p>';
    } elseif ($result === "win") {
        echo '<h2 class="blackjack-win">YOU WIN</h2>';
        echo '<p><b>Profit: +' . mksize($profit) . '</b></p>';
    } elseif ($result === "push") {
        echo '<h2 class="blackjack-push">PUSH</h2>';
        echo '<p>Your wager was returned.</p>';
    } else {
        echo '<h2 class="blackjack-loss">HOUSE WINS</h2>';
        echo '<p>You lost ' . mksize(abs($profit)) . '.</p>';
    }

    echo '<div class="blackjack-credit">';
    echo '<span class="blackjack-credit-label">Current Balance</span>';
    echo '<span class="blackjack-credit-value">'
        . mksize($balance) . '</span>';
    echo '</div>';

    echo '<div class="blackjack-actions">';

    $repeatWager = $split !== null
        ? (int)$split["base_wager"]
        : (int)$game["wager"];

    if (
        $repeatWager >= $GLOBALS["blackjack_min_bet_runtime"]
        && $repeatWager <= $GLOBALS["blackjack_max_bet_runtime"]
        && $repeatWager <= $balance
    ) {
        echo '<form method="post" action="blackjack.php" '
            . 'autocomplete="off">';

        echo '<input type="hidden" name="csrf" value="'
            . bj_h(bj_csrf_token()) . '">';

        echo '<input type="hidden" name="action" value="start">';

        echo '<input type="hidden" name="wager_bytes" value="'
            . $repeatWager . '">';

        echo '<button id="blackjackRepeatButton" '
            . 'class="blackjack-button" type="submit">'
            . 'Repeat ' . bj_h(mksize($repeatWager))
            . '</button>';

        echo '</form>';
    }

    echo '<button id="blackjackChangeBetButton" '
        . 'class="blackjack-button" type="button">'
        . 'Change Bet</button>';

    echo '</div>';

    echo '</div>';
    echo '</div>';

    echo '<script>
(function(){
    "use strict";

    var cards = document.querySelectorAll(
        "#blackjackApp .blackjack-card-animated[data-card-sequence]"
    );

    for(var i = 0; i < cards.length; i++){
        (function(card){
            var sequence = parseInt(
                card.getAttribute("data-card-sequence"),
                10
            ) || 1;

            window.setTimeout(function(){
                card.classList.add("blackjack-card-show");
                if(window.BlackjackSound){
                    var soundName = card.classList.contains("blackjack-card-flip")
                        ? "flip"
                        : "card";
                    window.BlackjackSound.play(soundName, 0.64);
                }
            }, 110 + ((sequence - 1) * 170));
        })(cards[i]);
    }

    var blackjackResult = "' . bj_h($result) . '";
    var animatedCards = cards.length;
    var lastSequence = 1;

    for(var r = 0; r < cards.length; r++){
        var seq = parseInt(cards[r].getAttribute("data-card-sequence"), 10) || 1;
        if(seq > lastSequence){ lastSequence = seq; }
    }

    var outcomeDelay = animatedCards
        ? 420 + ((lastSequence - 1) * 170)
        : 120;

    window.setTimeout(function(){
        if(!window.BlackjackSound){ return; }
        if(blackjackResult === "blackjack"){
            window.BlackjackSound.play("blackjack", 0.82);
        } else if(blackjackResult === "win"){
            window.BlackjackSound.play("win", 0.78);
        } else if(blackjackResult === "push"){
            window.BlackjackSound.play("push", 0.68);
        } else if(blackjackResult === "loss"){
            window.BlackjackSound.play("lose", 0.66);
        }
    }, outcomeDelay);

    if(window.history && window.history.replaceState){
        var cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete("anim");
        window.history.replaceState({}, "", cleanUrl.toString());
    }
})();
</script>';

    bj_render_stats((int)$game["user_id"]);
    bj_page_end();
}


/*
 * --------------------------------------------------------------------------
 * Controller
 * --------------------------------------------------------------------------
 */

$deck = bj_deck();
$db = bj_db();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    bj_require_csrf();

    $action = isset($_POST["action"])
        ? (string)$_POST["action"]
        : "";

    try {
        if ($action === "reset_stats") {
            $targetUserId = isset($_POST["target_user_id"])
                ? (int)$_POST["target_user_id"]
                : 0;

            bj_admin_reset_stats($targetUserId);
            header("Location: blackjack.php");
            exit;
        }

        if ($action === "start") {
            $wager = bj_parse_wager($_POST, $minBet, $maxBet);
            $gameId = bj_start_hand($userId, $wager, $deck);
            bj_redirect_to_hand($gameId, "deal");
        }

        if ($action === "hit" || $action === "stand" || $action === "split" || $action === "double") {
            $gameId = bj_play_action($userId, $action, $deck, $maxBet);

            $animation = $action === "stand" ? "dealer" : ($action === "split" ? "split" : ($action === "double" ? "resolve" : "hit"));

            if ($action === "hit" || $action === "split" || $action === "double") {
                $finishedGame = bj_get_game($db, $gameId, $userId);

                if ($finishedGame && (string)$finishedGame["status"] === "settled") {
                    $animation = "resolve";
                }
            }

            bj_redirect_to_hand($gameId, $animation);
        }

        throw new RuntimeException("Unknown blackjack action.");
    } catch (Throwable $e) {
        show_error_msg(
            "Blackjack",
            bj_h($e->getMessage()),
            1
        );
    }
}

$requestedHand = isset($_GET["hand"])
    ? (int)$_GET["hand"]
    : 0;

$animation = isset($_GET["anim"]) ? (string)$_GET["anim"] : "";

if (!in_array($animation, ["deal", "hit", "split", "dealer", "resolve"], true)) {
    $animation = "";
}

if ($requestedHand > 0) {
    $game = bj_get_game($db, $requestedHand, $userId);

    if ($game) {
        if ((string)$game["status"] === "playing") {
            bj_render_hand($game, $deck, $animation);
        } else {
            bj_render_result($game, $deck, $animation);
        }

        exit;
    }
}

$activeGame = bj_get_active_game($db, $userId, false);

if ($activeGame) {
    bj_redirect_to_hand((int)$activeGame["id"]);
}

bj_render_wager_screen($userId, $minBet, $maxBet);