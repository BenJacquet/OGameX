<?php

use OGame\Console\Commands\Scheduler\CleanupWreckFields;
use OGame\Console\Commands\Scheduler\DeleteOldMessages;
use OGame\Console\Commands\Scheduler\EscalateNpcBaseDifficulty;
use OGame\Console\Commands\Scheduler\GenerateAllianceHighscores;
use OGame\Console\Commands\Scheduler\GenerateHighscoreRanks;
use OGame\Console\Commands\Scheduler\GenerateHighscores;
use OGame\Console\Commands\Scheduler\ResetDebrisFields;
use OGame\Console\Commands\Scheduler\RespawnNPCBases;
use OGame\Console\Commands\Scheduler\SpawnNPCBases;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Schedule::command(GenerateHighscores::class)->everyFiveMinutes();
// Alliance highscores should run after player highscores since they depend on them
Schedule::command(GenerateAllianceHighscores::class)->everyFiveMinutes();
// Generates ranks for both player and alliance highscores
Schedule::command(GenerateHighscoreRanks::class)->everyFiveMinutes();

// Reset empty debris fields weekly on Monday at 1:00 AM
Schedule::command(ResetDebrisFields::class)->weeklyOn(1, '1:00');

// Clean up wreck fields hourly
Schedule::command(CleanupWreckFields::class)->hourly()->withoutOverlapping();

// Delete messages once they have aged out of the seven-day retention window
Schedule::command(DeleteOldMessages::class)->hourly()->withoutOverlapping();

// Top up NPC bases per galaxy
Schedule::command(SpawnNPCBases::class)->hourly()->withoutOverlapping();

// Respawn cleared NPC bases whose timer has elapsed
Schedule::command(RespawnNPCBases::class)->everyFifteenMinutes()->withoutOverlapping();

// Escalate NPC base difficulty weekly on Monday at 2:00 AM
Schedule::command(EscalateNpcBaseDifficulty::class)->weeklyOn(1, '2:00');
