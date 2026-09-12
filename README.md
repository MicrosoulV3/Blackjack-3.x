# TTv3 House Blackjack

A single-player Blackjack game for TorrentTrader / TTv3.x that lets members play against the house using their upload credit as
chips.

![TTv3 House Blackjack](screenshots/blackjack.webp)

## Features

-   Single-player Blackjack against the house
-   Uses tracker upload credit as the player's balance
-   Configurable minimum and maximum wagers
-   MB / GB wager selection
-   Whole-number custom wagers
-   Quick-bet buttons automatically respect configured limits
-   Natural Blackjack pays **3:2**
-   Dealer stands on **17**
-   **Hit**
-   **Stand**
-   **Double Down**
-   **Split**
-   Split Aces support
-   Split-hand results and payouts
-   Split strategy chart
-   Repeat Bet and Change Bet controls
-   Card animations
-   Card placement, win, loss, push, Blackjack, and flip sounds
-   Casino room ambience with an independent on/off control
-   Sound preferences saved in the browser
-   Player statistics
-   Leaderboard
-   Admin-selectable player stat resets
-   CSRF protection
-   Transactional balance handling
-   One active house game per player
-   WebP playing-card artwork
-   AJAX-style gameplay without full-page reloads

## Player Statistics

The game tracks:

-   Hands played
-   Win / loss / push record
-   Win rate
-   Win/loss ratio
-   Current streak
-   Best win streak
-   Net profit
-   Biggest win
-   Total wagered
-   Natural Blackjacks

Pushes do not count against win rate or streaks.

## Betting Limits

Betting limits are controlled entirely through the site
configuration:

``` php
$site_config['blackjack_min_bet'] = 1 * MB;
$site_config['blackjack_max_bet'] = 100 * GB;
```

The configured limits are enforced server-side.

The configured maximum applies to normal wagers and **Double Down**.

**Split is the exception:** if a player has already wagered the configured
maximum and is dealt a valid pair, the hand may still be split. This can
temporarily create total exposure of up to twice the configured maximum for
that hand only. After the hand ends, Repeat Bet returns to the original base
wager.

Custom wagers use whole numbers only. For example:

``` text
1 MB
250 MB
1 GB
25 GB
```

Decimal custom wagers such as `1.3 GB` are not accepted.

Changing the custom wager unit between MB and GB keeps the visible whole-number
amount unchanged. For example, switching `1 MB` to GB displays `1 GB` rather
than converting the field to a decimal value.

## Blackjack Rules

-   Dealer stands on 17.
-   Natural Blackjack pays 3:2.
-   Normal wins pay 1:1.
-   Pushes return the wager.
-   Double Down is available on the player's initial two-card hand when
    sufficient credit is available and the doubled wager remains within
    the configured maximum.
-   Double Down draws exactly one additional card and then automatically
    stands.
-   Split is available only when the two original cards are the same
    rank.
-   Splitting requires an additional wager equal to the original wager.
-   A valid Split is allowed even when the original wager is already at the
    configured maximum. The temporary split total may therefore exceed the
    normal table maximum for that hand only.
-   Repeat Bet after a split uses the original base wager, not the combined
    split total.
-   Split hands are paid independently at 1:1.
-   A 21 after splitting is not treated as a natural Blackjack.
-   Split Aces receive one additional card per hand and then stand
    automatically.
-   Re-splitting is not currently supported.

## Requirements

-   TorrentTrader / TTv3
-   PHP 8.x supported
-   MySQL or MariaDB
-   MySQLi
-   Existing TTv3 user authentication
-   Existing `users.uploaded` upload-credit balance

## Installation

### 1. Install the database table

Import the included `blackjack.sql` file into your tracker database before
using the game.

No database tables are created or altered automatically by the PHP game.

### 2. Add the Blackjack files

Upload the files while keeping the supplied folder structure. `blackjack.php` and `blackjack_split_chart.html` are root files.

Playing-cards are expected under:

``` text
images/blackjack/cards/
```

The card images use **WebP** format.

### 3. Configure betting limits

Add the desired limits to your TTv3 configuration:

``` php
$site_config['blackjack_min_bet'] = 1 * MB;
$site_config['blackjack_max_bet'] = 100 * GB;
```

Change these values to suit your tracker economy.

### 4. Add sounds and ambience

The game supports Blackjack sound effects and casino-room ambience. Keep
the supplied sound assets in the paths referenced by `blackjack.php`.

Users can independently disable normal game sounds or room ambience.

## Upload Credit

The game treats the existing TTv3 `users.uploaded` value as the player's
Blackjack balance.

Wagers are deducted from upload credit and payouts are returned to
upload credit. Balance-changing game actions use database transactions
and row locking to protect wager operations.

This project does **not** use real money.

## Admin Stat Reset

Administrators can selectively reset an individual player's Blackjack
statistics without changing that player's upload balance or active hand.

The current implementation requires **user class 7 or higher** for this
function.

## Security

The game includes:

-   TTv3 login enforcement
-   CSRF tokens on game actions
-   Server-side wager validation
-   Config-enforced minimum and maximum starting wagers
-   Transactional credit updates
-   Row locking during balance-changing actions
-   Per-user active-game protection
-   User ownership checks when loading hands
-   Server-side validation of Split and Double Down

Client-side controls are for convenience only; wager and game rules are
enforced by PHP.

## Compatibility
PHP 8.x

## Notes

This is a house game: each logged-in player receives an independent
Blackjack game against the dealer. Multiple users can play
simultaneously without sharing a table or game state.

The project is intended as a fun feature using virtual upload
credit.

## License

Use and modify this project according to the license included with the
repository.
