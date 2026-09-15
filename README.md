# TTv3.x Blackjack

<figure>
<img src="screenshots/blackjack-betting.webp" alt="TTv3 House Blackjack" />
<figcaption aria-hidden="true">TTv3.x Blackjack</figcaption>
</figure>

## Compatibility

> **PHP 8.x Compatible**
>
> Built for TTv3 / TTv3.x.

- PHP 8.x
- MySQL or MariaDB

## Features

- Single-player Blackjack against the house
- Uses tracker upload credit as the player’s balance
- Configurable minimum and maximum wagers
- MB / GB wager selection
- Whole-number custom wagers
- Quick-bet buttons automatically respect configured limits
- Natural Blackjack pays **3:2**
- Dealer stands on **17**
- **Hit**
- **Stand**
- **Double Down**
- **Split**
- Split Aces support
- Split-hand results and payouts
- Split strategy chart
- Repeat Bet and Change Bet controls
- Card animations
- Card placement, win, loss, push, Blackjack, and flip sounds
- Casino room ambience with an independent on/off control
- Sound preferences saved in the browser
- Player statistics
- Leaderboard
- Admin-selectable player stat resets
- CSRF protection
- Transactional balance handling
- One active house game per player
- WebP playing-card artwork
- AJAX-style gameplay without full-page reloads

## Gameplay Screenshots

### Betting and Player Stats

Pick a quick bet or enter your own wager in MB or GB. Your balance, betting limits, and player stats are all shown on the same screen.

![Blackjack betting screen](screenshots/blackjack-betting.webp)

### Active Hand

Once the cards are dealt, just play the hand. **Hit**, **Stand**, and **Double Down** are available when the hand allows them.

![Blackjack active hand](screenshots/blackjack-hand.webp)

### Split

Get dealt a pair and the **Split** button shows up.

![Blackjack split available](screenshots/blackjack-split-available.webp)

### Split in Progress

After splitting, play each hand one at a time against the dealer.

![Blackjack split hand in progress](screenshots/blackjack-split.webp)

### Split Result

Each split hand gets its own result, and the game shows what you won or lost overall. **Repeat Bet** goes back to your original bet.

![Blackjack split result](screenshots/blackjack-split-result.webp)

### Administrator Stat Reset

Admins with user class 7 or higher can reset a player's Blackjack stats without touching their upload credit or an active game.

![Blackjack administrator stat reset](screenshots/blackjack-admin-reset.webp)

## Player Statistics

The game tracks:

- Hands played
- Win / loss / push record
- Win rate
- Win/loss ratio
- Current streak
- Best win streak
- Net profit
- Biggest win
- Total wagered
- Natural Blackjacks

Pushes do not count against win rate or streaks.

## Betting Limits

Set the minimum and maximum bets in your site config:

``` php
$site_config['blackjack_min_bet'] = 1 * MB;
$site_config['blackjack_max_bet'] = 100 * GB;
```

Normal bets and **Double Down** stay within the configured maximum.

**Split works a little differently.** If you get a pair, you can split it as long as you have enough upload credit for the second bet. This still works when your original bet is already at the maximum. The maximum applies to each split hand, not both hands added together.

Custom bets use whole numbers only. For example:

``` text
1 MB
250 MB
1 GB
25 GB
```

Decimals such as `1.3 GB` are not accepted.

## Blackjack Rules

- Dealer stands on 17.
- Natural Blackjack pays 3:2.
- Normal wins pay 1:1.
- Pushes return the wager.
- You can Double Down on your first two cards if you have enough upload credit and the doubled bet stays within the maximum.
- Double Down gives you one more card and then automatically stands.
- You can Split when your first two cards are the same rank.
- Splitting costs another bet equal to your original bet.
- You can still Split when your original bet is at the maximum, as long as you have enough upload credit for the second hand.
- After a split, **Repeat Bet** uses your original bet.
- Each split hand pays 1:1.
- A 21 after splitting is not a natural Blackjack.
- Split Aces get one card each and automatically stand.
- Re-splitting is not supported yet.

## Requirements

- TTv3 / TTv3.x
- PHP 8.x supported
- MySQL or MariaDB

## Installation

### 1. Install the database table

Import the included `blackjack.sql` file into your tracker database before using the game.

The PHP does not create or alter database tables for you.

### 2. Add the Blackjack files

Upload the files and keep the supplied folder structure. Put `blackjack.php` and `blackjack_split_chart.html` in your tracker root.

Playing-cards are expected under:

``` text
images/blackjack/cards/
```

The card images use **WebP** format.

### 3. Configure betting limits

Set the betting limits to whatever works for your tracker. If `MB` and `GB` are not already defined, add these constants near the top of `config.php`:

``` php
// File Size Constants - DO NOT MODIFY
define('KB', 1024);
define('MB', 1024 * KB);
define('GB', 1024 * MB);

// Time Constants - DO NOT MODIFY
define('MINUTE', 60);
define('HOUR', 60 * MINUTE);
define('DAY', 24 * HOUR);
define('WEEK', 7 * DAY);
```

Then add your Blackjack limits:

``` php
$site_config['blackjack_min_bet'] = 1 * MB;
$site_config['blackjack_max_bet'] = 100 * GB;
```

Change those two values to suit what you like.

### 4. sounds and ambience

Players can turn the game sounds and casino-room ambience on or off separately. Their choices are remembered by the browser.

## Upload Credit

Blackjack uses the existing TTv3 `users.uploaded` value as the player's chip balance.

Bets come out of upload credit and winnings go back into it. The balance updates are handled with database transactions and row locking so two game actions cannot spend the same credit.

This is all virtual upload credit. **No real money is used.**

## Admin Stat Reset

Admins can reset one player's Blackjack stats without changing that player's upload credit or active game.

This requires **user class 7 or higher**.

## Security

The game includes:

- TTv3 login enforcement
- CSRF tokens on game actions
- Server-side wager validation
- Config-enforced minimum and maximum starting wagers
- Transactional credit updates
- Row locking during balance-changing actions
- Per-user active-game protection
- User ownership checks when loading hands
- Server-side validation of Split and Double Down

The buttons and browser-side checks make the game easier to use, but PHP still checks the wagers and game rules on the server.

## Notes

This is a house game. Every logged-in player gets their own game against the dealer, so multiple people can play at the same time without sharing a table.

It is meant to be a fun extra for TTv3 sites that want to give members something to do with their upload credit.

## License

Use and modify this project according to the license included with the
repository.
