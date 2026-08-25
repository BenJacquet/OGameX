<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * Class DarkMatterService.
 *
 * Primary service for all Dark Matter operations.
 *
 * @package OGame\Services
 */
class DarkMatterService
{
    /**
     * DarkMatterService constructor.
     *
     * @param DarkMatterTransactionService $transactionService
     * @param SettingsService $settingsService
     */
    public function __construct(
        private DarkMatterTransactionService $transactionService,
        private SettingsService $settingsService
    ) {
    }

    /**
     * Credit Dark Matter to a user.
     *
     * @param User $user
     * @param int $amount
     * @param string $type Transaction type from DarkMatterTransactionType enum
     * @param string $description
     * @return void
     * @throws Exception
     */
    public function credit(User $user, int $amount, string $type, string $description): void
    {
        if ($amount < 0) {
            throw new Exception('Cannot credit negative amount');
        }

        DB::transaction(function () use ($user, $amount, $type, $description) {
            // Lock the user row for update
            $user = User::where('id', $user->id)->lockForUpdate()->first();
            if ($user === null) {
                throw new Exception('User not found.');
            }

            // Update balance
            $user->dark_matter += $amount;
            $user->save();

            // Record transaction
            $this->transactionService->recordTransaction(
                $user,
                $amount,
                $type,
                $description,
                $user->dark_matter
            );
        });
    }

    /**
     * Debit Dark Matter from a user.
     *
     * @param User $user
     * @param int $amount
     * @param string $type Transaction type from DarkMatterTransactionType enum
     * @param string $description
     * @return void
     * @throws Exception
     */
    public function debit(User $user, int $amount, string $type, string $description): void
    {
        if ($amount < 0) {
            throw new Exception('Cannot debit negative amount');
        }

        $this->settleRegeneration($user);

        DB::transaction(function () use ($user, $amount, $type, $description) {
            // Lock the user row for update
            $user = User::where('id', $user->id)->lockForUpdate()->first();
            if ($user === null) {
                throw new Exception('User not found.');
            }

            // Check balance
            if ($user->dark_matter < $amount) {
                throw new Exception("Insufficient Dark Matter. Required: {$amount}, Available: {$user->dark_matter}");
            }

            // Update balance
            $user->dark_matter -= $amount;
            $user->save();

            // Record transaction (negative amount for debit)
            $this->transactionService->recordTransaction(
                $user,
                -$amount,
                $type,
                $description,
                $user->dark_matter
            );
        });
    }

    /**
     * Get current Dark Matter balance for a user.
     *
     * Settles any pending lazy regeneration first so the returned value is up to date.
     *
     * @param User $user
     * @return int
     */
    public function getBalance(User $user): int
    {
        $this->settleRegeneration($user);
        return $user->dark_matter;
    }

    /**
     * Check if user can afford an amount.
     *
     * Settles any pending lazy regeneration first so the check uses an up-to-date balance.
     *
     * @param User $user
     * @param int $amount
     * @return bool
     */
    public function canAfford(User $user, int $amount): bool
    {
        $this->settleRegeneration($user);
        return $user->dark_matter >= $amount;
    }

    /**
     * Calculate how much whole Dark Matter has accrued for a user since their last
     * regeneration checkpoint, based on continuous (lazy) accrual from the combined rate
     * (admin passive regen, if enabled, plus Dark Matter Factory production).
     *
     * Does not persist anything; pure calculation.
     *
     * @param User $user
     * @return int Whole Dark Matter accrued (0 if nothing is due yet)
     */
    public function calculatePendingAccrual(User $user): int
    {
        if ($user->dark_matter_last_regen === null) {
            return 0;
        }

        $rate = $this->getTotalRegenRatePerSecond($user);
        if ($rate <= 0) {
            return 0;
        }

        $elapsedSeconds = (int)$user->dark_matter_last_regen->diffInSeconds(now());

        return (int)floor($elapsedSeconds * $rate);
    }

    /**
     * Get the current Dark Matter regeneration rate for a user, in Dark Matter per second.
     * Combines the admin-configured passive regen rate (if enabled) with the user's Dark
     * Matter Factory production summed across all of their planets.
     *
     * @param User $user
     * @return float
     */
    public function getRegenerationRatePerSecond(User $user): float
    {
        return $this->getTotalRegenRatePerSecond($user);
    }

    /**
     * Combine the admin passive-regen rate (if enabled) with the user's Dark Matter Factory
     * production rate.
     *
     * @param User $user
     * @return float Dark Matter per second
     */
    private function getTotalRegenRatePerSecond(User $user): float
    {
        $rate = 0.0;

        $regenEnabled = (bool)$this->settingsService->get('dark_matter_regen_enabled', 0);
        if ($regenEnabled) {
            $regenPeriod = (int)$this->settingsService->get('dark_matter_regen_period', 604800);
            $regenAmount = (int)$this->settingsService->get('dark_matter_regen_amount', 150000);

            if ($regenPeriod > 0 && $regenAmount > 0) {
                $rate += $regenAmount / $regenPeriod;
            }
        }

        $rate += $this->getBuildingProductionRatePerSecond($user);

        return $rate;
    }

    /**
     * Sum Dark Matter Factory production across all planets owned by the user, in Dark
     * Matter per second. Independent of the admin passive-regen setting.
     *
     * Dark Matter Factory has its own standalone formula (GameObjectProduction::$dark_matter_formula
     * on the 'dark_matter_factory' object, set in BuildingObjects.php), not bonus-stacked
     * with Plasma Technology, Geologist, Crawlers, officers, or Character Class. It respects
     * the planet's energy-shortage throttle and its own production-percentage slider, same
     * as real mines.
     *
     * @param User $user
     * @return float Dark Matter per second
     */
    private function getBuildingProductionRatePerSecond(User $user): float
    {
        $planets = Planet::where('user_id', $user->id)
            ->where('dark_matter_factory', '>', 0)
            ->get(['dark_matter_factory', 'dark_matter_factory_percent', 'energy_max', 'energy_used']);

        if ($planets->isEmpty()) {
            return 0.0;
        }

        $darkMatterFactory = ObjectService::getObjectByMachineName('dark_matter_factory');
        $formula = $darkMatterFactory->production->dark_matter_formula;
        if ($formula === null) {
            return 0.0;
        }

        $economySpeed = $this->settingsService->economySpeed();

        $totalPerHour = 0.0;
        foreach ($planets as $planet) {
            $level = (int)$planet->dark_matter_factory;
            $base = $formula($darkMatterFactory->production, $level);
            $energyFactor = PlanetService::calculateResourceProductionFactor(
                (int)$planet->energy_max,
                (int)$planet->energy_used
            );

            $totalPerHour += self::scaleFactoryOutput($base, (int)$planet->dark_matter_factory_percent, $economySpeed, $energyFactor);
        }

        return $totalPerHour / 3600;
    }

    /**
     * Get the Dark Matter Factory's output on a specific planet at a given level, in Dark
     * Matter per hour. Used for UI previews (e.g. "next level" production in the building
     * details popup) - the live per-user rate is computed via getRegenerationRatePerSecond()
     * instead, which sums this across all of a user's planets.
     *
     * @param PlanetService $planet
     * @param int $level
     * @return float Dark Matter per hour
     */
    public function getFactoryProductionPerHour(PlanetService $planet, int $level): float
    {
        if ($level <= 0) {
            return 0.0;
        }

        $darkMatterFactory = ObjectService::getObjectByMachineName('dark_matter_factory');
        $formula = $darkMatterFactory->production->dark_matter_formula;
        if ($formula === null) {
            return 0.0;
        }

        $base = $formula($darkMatterFactory->production, $level);
        $economySpeed = $this->settingsService->economySpeed();

        return self::scaleFactoryOutput($base, $planet->getBuildingPercent('dark_matter_factory'), $economySpeed, $planet->getResourceProductionFactor());
    }

    /**
     * Scale a Dark Matter Factory's base formula output by its production-percentage slider,
     * the economy speed, and the planet's energy-shortage throttle.
     *
     * @param float $base Base formula output (Dark Matter per hour, before scaling)
     * @param int $percentRaw Raw dark_matter_factory_percent value (0-10 scale, 10 = 100%)
     * @param int $economySpeed
     * @param int $energyFactorPercent Energy-shortage throttle (0-100)
     * @return float Dark Matter per hour
     */
    private static function scaleFactoryOutput(float $base, int $percentRaw, int $economySpeed, int $energyFactorPercent): float
    {
        $percent = ($percentRaw ?: 0) / 10;
        return $base * $percent * $economySpeed * ($energyFactorPercent / 100);
    }

    /**
     * Settle any pending lazy Dark Matter regeneration for a user: credits whole Dark Matter
     * accrued since the last checkpoint and advances the checkpoint to now.
     *
     * If nothing has accrued yet, the checkpoint is left untouched so elapsed time keeps
     * accumulating toward the next whole Dark Matter instead of resetting.
     *
     * @param User $user
     * @return int Amount of Dark Matter credited (0 if none)
     */
    public function settleRegeneration(User $user): int
    {
        return DB::transaction(function () use ($user) {
            // Lock the user row for update
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                throw new Exception('User not found.');
            }

            if ($lockedUser->dark_matter_last_regen === null) {
                // Seed the checkpoint so accrual starts counting from now, rather than
                // crediting a full lump sum immediately.
                $lockedUser->dark_matter_last_regen = now();
                $lockedUser->save();
                $user->dark_matter_last_regen = $lockedUser->dark_matter_last_regen;
                return 0;
            }

            $accrued = $this->calculatePendingAccrual($lockedUser);
            if ($accrued <= 0) {
                return 0;
            }

            $lockedUser->dark_matter += $accrued;
            $lockedUser->dark_matter_last_regen = now();
            $lockedUser->save();

            $this->transactionService->recordTransaction(
                $lockedUser,
                $accrued,
                DarkMatterTransactionType::REGENERATION->value,
                'Dark Matter regeneration',
                $lockedUser->dark_matter
            );

            // Reflect the settled state on the caller's instance.
            $user->dark_matter = $lockedUser->dark_matter;
            $user->dark_matter_last_regen = $lockedUser->dark_matter_last_regen;

            return $accrued;
        });
    }

    /**
     * Calculate expedition reward amount.
     *
     * @param bool $hasPathfinder Whether the expedition fleet has Pathfinder ships
     * @return int
     */
    public function calculateExpeditionReward(bool $hasPathfinder): int
    {
        $multiplier = (float)$this->settingsService->get('expedition_dark_matter_multiplier', '1.0');

        if ($hasPathfinder) {
            $min = (int)$this->settingsService->get('expedition_dark_matter_min_pathfinder', 300);
            $max = (int)$this->settingsService->get('expedition_dark_matter_max_pathfinder', 400);
        } else {
            $min = (int)$this->settingsService->get('expedition_dark_matter_min_no_pathfinder', 150);
            $max = (int)$this->settingsService->get('expedition_dark_matter_max_no_pathfinder', 200);
        }

        $baseReward = rand($min, $max);
        return (int)($baseReward * $multiplier);
    }

    /**
     * Calculate speed-up cost based on remaining time.
     * Formula: ceil((remaining_time_in_hours / 2) * (1 / universe_speed))
     * Minimum cost: 1 DM
     *
     * @param int $remainingSeconds
     * @param float $universeSpeed
     * @return int
     */
    public function calculateSpeedupCost(int $remainingSeconds, float $universeSpeed): int
    {
        $remainingHours = $remainingSeconds / 3600;
        $cost = ceil(($remainingHours / 2) * (1 / $universeSpeed));

        return max(1, (int)$cost);
    }
}
