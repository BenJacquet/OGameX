<?php

namespace Tests\Unit;

use Exception;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\DarkMatterService;
use OGame\Services\DarkMatterTransactionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\UnitTestCase;

class DarkMatterServiceTest extends UnitTestCase
{
    private DarkMatterService $darkMatterService;

    protected function setUp(): void
    {
        parent::setUp();

        $transactionService = app(DarkMatterTransactionService::class);
        $settingsService = app(SettingsService::class);
        $this->darkMatterService = new DarkMatterService($transactionService, $settingsService);
    }

    /**
     * Test that credit() increases user's Dark Matter balance.
     */
    public function testCreditIncreasesBalance(): void
    {
        $user = User::factory()->create();
        $user->refresh(); // Get the balance after initial bonus
        $initialBalance = $user->dark_matter;

        $this->darkMatterService->credit(
            $user,
            500,
            DarkMatterTransactionType::ADMIN_ADJUSTMENT->value,
            'Test credit'
        );

        $user->refresh();
        $this->assertEquals($initialBalance + 500, $user->dark_matter);
    }

    /**
     * Test that debit() decreases user's Dark Matter balance.
     */
    public function testDebitDecreasesBalance(): void
    {
        $user = User::factory()->create();
        $user->refresh(); // Get the balance after initial bonus
        $initialBalance = $user->dark_matter;

        $this->darkMatterService->debit(
            $user,
            300,
            DarkMatterTransactionType::ADMIN_ADJUSTMENT->value,
            'Test debit'
        );

        $user->refresh();
        $this->assertEquals($initialBalance - 300, $user->dark_matter);
    }

    /**
     * Test that debit() throws exception when balance is insufficient.
     */
    public function testDebitThrowsExceptionWhenInsufficientBalance(): void
    {
        $user = User::factory()->create();
        // Set balance to a low amount
        $user->dark_matter = 100;
        $user->save();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Insufficient Dark Matter');

        $this->darkMatterService->debit(
            $user,
            500,
            DarkMatterTransactionType::ADMIN_ADJUSTMENT->value,
            'Test debit'
        );
    }

    /**
     * Test that getBalance() returns correct balance.
     */
    public function testGetBalanceReturnsCorrectValue(): void
    {
        $user = User::factory()->create(['dark_matter' => 5000]);

        $balance = $this->darkMatterService->getBalance($user);

        $this->assertEquals(5000, $balance);
    }

    /**
     * Test that canAfford() returns true when user has enough Dark Matter.
     */
    public function testCanAffordReturnsTrueWhenSufficient(): void
    {
        $user = User::factory()->create(['dark_matter' => 1000]);

        $this->assertTrue($this->darkMatterService->canAfford($user, 500));
        $this->assertTrue($this->darkMatterService->canAfford($user, 1000));
    }

    /**
     * Test that canAfford() returns false when user doesn't have enough Dark Matter.
     */
    public function testCanAffordReturnsFalseWhenInsufficient(): void
    {
        $user = User::factory()->create(['dark_matter' => 100]);

        $this->assertFalse($this->darkMatterService->canAfford($user, 500));
    }

    /**
     * Test that calculateExpeditionReward() returns value within bounds.
     */
    public function testCalculateExpeditionRewardWithinBounds(): void
    {
        // Test with Pathfinder
        $rewardWithPathfinder = $this->darkMatterService->calculateExpeditionReward(true);
        $this->assertGreaterThanOrEqual(300, $rewardWithPathfinder);
        $this->assertLessThanOrEqual(400, $rewardWithPathfinder);

        // Test without Pathfinder
        $rewardWithoutPathfinder = $this->darkMatterService->calculateExpeditionReward(false);
        $this->assertGreaterThanOrEqual(150, $rewardWithoutPathfinder);
        $this->assertLessThanOrEqual(200, $rewardWithoutPathfinder);
    }

    /**
     * Test that calculatePendingAccrual() returns 0 when regeneration is disabled.
     */
    public function testCalculatePendingAccrualReturnsZeroWhenDisabled(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 0);

        $user = User::factory()->create();
        $user->dark_matter_last_regen = now()->subDays(30);
        $user->save();

        $this->assertEquals(0, $this->darkMatterService->calculatePendingAccrual($user));
    }

    /**
     * Test that calculatePendingAccrual() returns 0 when no checkpoint has been set yet.
     */
    public function testCalculatePendingAccrualReturnsZeroWithoutCheckpoint(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 1);

        $user = User::factory()->create();
        $user->dark_matter_last_regen = null;
        $user->save();

        $this->assertEquals(0, $this->darkMatterService->calculatePendingAccrual($user));
    }

    /**
     * Test that calculatePendingAccrual() returns a proportional whole amount for partial periods.
     */
    public function testCalculatePendingAccrualIsProportional(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 1);
        $settingsService->set('dark_matter_regen_amount', 100000);
        $settingsService->set('dark_matter_regen_period', 100000);

        $user = User::factory()->create();
        // Half the period elapsed should yield roughly half the amount.
        $user->dark_matter_last_regen = now()->subSeconds(50000);
        $user->save();

        $this->assertEquals(50000, $this->darkMatterService->calculatePendingAccrual($user));
    }

    /**
     * Test that settleRegeneration() seeds the checkpoint on first call instead of
     * crediting a full lump sum immediately.
     */
    public function testSettleRegenerationSeedsCheckpointOnFirstCall(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 1);
        $settingsService->set('dark_matter_regen_amount', 150000);
        $settingsService->set('dark_matter_regen_period', 604800);

        $user = User::factory()->create();
        $user->refresh(); // Get the balance after initial bonus
        $user->dark_matter_last_regen = null;
        $user->save();
        $initialBalance = $user->dark_matter;

        $credited = $this->darkMatterService->settleRegeneration($user);

        $this->assertEquals(0, $credited);
        $user->refresh();
        $this->assertEquals($initialBalance, $user->dark_matter);
        $this->assertNotNull($user->dark_matter_last_regen);
    }

    /**
     * Test that settleRegeneration() credits accrued Dark Matter and advances the checkpoint,
     * and that getBalance() reflects the settled amount.
     */
    public function testSettleRegenerationCreditsAccruedAmount(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 1);
        $settingsService->set('dark_matter_regen_amount', 100000);
        $settingsService->set('dark_matter_regen_period', 100000);

        $user = User::factory()->create();
        $user->refresh(); // Get the balance after initial bonus
        $initialBalance = $user->dark_matter;
        $user->dark_matter_last_regen = now()->subSeconds(50000);
        $user->save();

        $credited = $this->darkMatterService->settleRegeneration($user);

        $this->assertEquals(50000, $credited);
        $this->assertEquals($initialBalance + 50000, $user->dark_matter);

        // Checkpoint should have advanced to "now", so an immediate re-settle credits nothing.
        $this->assertEquals(0, $this->darkMatterService->settleRegeneration($user));

        $balance = $this->darkMatterService->getBalance($user);
        $this->assertEquals($initialBalance + 50000, $balance);
    }

    /**
     * Test that settleRegeneration() leaves the checkpoint untouched when less than
     * one whole Dark Matter has accrued, so elapsed time keeps accumulating.
     */
    public function testSettleRegenerationLeavesCheckpointWhenNothingAccrued(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 1);
        $settingsService->set('dark_matter_regen_amount', 1);
        $settingsService->set('dark_matter_regen_period', 100000);

        $user = User::factory()->create();
        $checkpoint = now()->subSeconds(1);
        $user->dark_matter_last_regen = $checkpoint;
        $user->save();

        $credited = $this->darkMatterService->settleRegeneration($user);

        $this->assertEquals(0, $credited);
        $user->refresh();
        $this->assertNotNull($user->dark_matter_last_regen);
        $this->assertEquals(
            $checkpoint->timestamp,
            $user->dark_matter_last_regen->timestamp
        );
    }

    /**
     * Test that calculateSpeedupCost() calculates correctly.
     */
    public function testCalculateSpeedupCost(): void
    {
        // 10 hours remaining, 1x speed: ceil((10 / 2) * 1) = 5 DM
        $cost = $this->darkMatterService->calculateSpeedupCost(36000, 1.0);
        $this->assertEquals(5, $cost);

        // 10 hours remaining, 2x speed: ceil((10 / 2) * 0.5) = 3 DM
        $cost = $this->darkMatterService->calculateSpeedupCost(36000, 2.0);
        $this->assertEquals(3, $cost);

        // 30 minutes remaining, 1x speed: minimum 1 DM
        $cost = $this->darkMatterService->calculateSpeedupCost(1800, 1.0);
        $this->assertEquals(1, $cost);
    }

    /**
     * Test that the Dark Matter Factory's resource cost is exactly 5x the Deuterium
     * Synthesizer's cost at every level.
     */
    public function testDarkMatterFactoryCostIsFiveTimesDeuteriumSynthesizer(): void
    {
        foreach ([1, 2, 5, 10] as $level) {
            $deuteriumPrice = ObjectService::getObjectRawPrice('deuterium_synthesizer', $level);
            $darkMatterPrice = ObjectService::getObjectRawPrice('dark_matter_factory', $level);

            // Allow a small delta: each price is floored independently after its own
            // base*factor^(level-1) calculation, so 5x the pre-floor base can differ from
            // 5x the already-floored Deuterium Synthesizer price by a couple of units.
            $this->assertEqualsWithDelta($deuteriumPrice->metal->get() * 5, $darkMatterPrice->metal->get(), 5, "Metal cost mismatch at level {$level}");
            $this->assertEqualsWithDelta($deuteriumPrice->crystal->get() * 5, $darkMatterPrice->crystal->get(), 5, "Crystal cost mismatch at level {$level}");

            // Deuterium cost is half the crystal cost. The base deuterium price (188) is
            // rounded from exactly half of the base crystal price (375 / 2 = 187.5), so the
            // rounding gap widens at higher levels as the growth factor compounds it.
            $this->assertEqualsWithDelta($darkMatterPrice->crystal->get() / 2, $darkMatterPrice->deuterium->get(), 25, "Deuterium cost mismatch at level {$level}");
        }
    }

    /**
     * Test that the Dark Matter Factory's energy formula is exactly 2x the Deuterium
     * Synthesizer's energy formula at every level.
     */
    public function testDarkMatterFactoryEnergyIsTwiceDeuteriumSynthesizer(): void
    {
        $deuteriumSynthesizer = ObjectService::getObjectByMachineName('deuterium_synthesizer');
        $darkMatterFactory = ObjectService::getObjectByMachineName('dark_matter_factory');

        $this->assertNotNull($deuteriumSynthesizer->production->energy_formula);
        $this->assertNotNull($darkMatterFactory->production->energy_formula);

        foreach ([1, 2, 5, 10] as $level) {
            $deuteriumEnergy = $deuteriumSynthesizer->production->energy_formula->__invoke($deuteriumSynthesizer->production, $level);
            $darkMatterEnergy = $darkMatterFactory->production->energy_formula->__invoke($darkMatterFactory->production, $level);

            $this->assertEqualsWithDelta($deuteriumEnergy * 2, $darkMatterEnergy, 0.0001, "Energy formula mismatch at level {$level}");
        }

        // The Dark Matter Factory must not produce metal/crystal/deuterium directly - its
        // output is its own standalone formula, credited via DarkMatterService rather than
        // the per-planet resource system.
        $this->assertNull($darkMatterFactory->production->metal_formula);
        $this->assertNull($darkMatterFactory->production->crystal_formula);
        $this->assertNull($darkMatterFactory->production->deuterium_formula);
        $this->assertNotNull($darkMatterFactory->production->dark_matter_formula);
    }

    /**
     * Test that Dark Matter Factory production matches its own standalone formula
     * (1 * level * 1.1^level, defined in BuildingObjects.php), and respects the
     * percentage slider / economy speed.
     */
    public function testBuildingProductionRateMatchesOwnFormula(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 0);
        $settingsService->set('economy_speed', 1);

        $user = User::factory()->create();

        $level = 5;

        Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => random_int(1, 1_000_000),
            'system' => random_int(1, 999),
            'planet' => 1,
            'dark_matter_factory' => $level,
            'dark_matter_factory_percent' => 10,
            'energy_max' => 0,
            'energy_used' => 0,
        ]);

        $expectedBase = 1 * $level * (1.1 ** $level);
        $expectedRatePerSecond = $expectedBase / 3600;

        $rate = $this->darkMatterService->getRegenerationRatePerSecond($user);

        $this->assertEqualsWithDelta($expectedRatePerSecond, $rate, 0.0001);
    }

    /**
     * Test that Dark Matter Factory production accrues regardless of the admin
     * passive-regen toggle - it's driven by the building, not the admin setting.
     */
    public function testBuildingProductionIndependentOfAdminRegenToggle(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 0);
        $settingsService->set('economy_speed', 1);

        $user = User::factory()->create();

        Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => random_int(1, 1_000_000),
            'system' => random_int(1, 999),
            'planet' => 1,
            'dark_matter_factory' => 5,
            'dark_matter_factory_percent' => 10,
        ]);

        $rateWithAdminRegenDisabled = $this->darkMatterService->getRegenerationRatePerSecond($user);
        $this->assertGreaterThan(0, $rateWithAdminRegenDisabled, 'Dark Matter Factory should produce even when admin regen is disabled');

        $settingsService->set('dark_matter_regen_enabled', 1);
        $settingsService->set('dark_matter_regen_amount', 150000);
        $settingsService->set('dark_matter_regen_period', 604800);

        $rateWithAdminRegenEnabled = $this->darkMatterService->getRegenerationRatePerSecond($user);
        $this->assertEqualsWithDelta(
            $rateWithAdminRegenDisabled + (150000 / 604800),
            $rateWithAdminRegenEnabled,
            0.0001,
            'Enabling admin regen should add to, not replace, the building rate'
        );
    }

    /**
     * Test that energy shortage throttles Dark Matter Factory output, same as real mines.
     */
    public function testBuildingProductionThrottledByEnergyShortage(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 0);
        $settingsService->set('economy_speed', 1);

        $user = User::factory()->create();

        Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => random_int(1, 1_000_000),
            'system' => random_int(1, 999),
            'planet' => 1,
            'dark_matter_factory' => 5,
            'dark_matter_factory_percent' => 10,
            'energy_max' => 50,
            'energy_used' => 100, // 50% production factor
        ]);

        $throttledRate = $this->darkMatterService->getRegenerationRatePerSecond($user);

        $level = 5;
        $fullRate = (1 * $level * (1.1 ** $level)) / 3600;

        $this->assertEqualsWithDelta($fullRate * 0.5, $throttledRate, 0.0001);
    }

    /**
     * Test that Dark Matter Factory production sums across all of a user's planets.
     */
    public function testBuildingProductionSumsAcrossMultiplePlanets(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('dark_matter_regen_enabled', 0);
        $settingsService->set('economy_speed', 1);

        $user = User::factory()->create();
        $galaxy = random_int(1, 1_000_000);
        $system = random_int(1, 999);

        Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => $galaxy,
            'system' => $system,
            'planet' => 1,
            'dark_matter_factory' => 5,
            'dark_matter_factory_percent' => 10,
        ]);
        Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => $galaxy,
            'system' => $system,
            'planet' => 2,
            'dark_matter_factory' => 3,
            'dark_matter_factory_percent' => 10,
        ]);
        // A planet without a Dark Matter Factory should contribute nothing.
        Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => $galaxy,
            'system' => $system,
            'planet' => 3,
            'dark_matter_factory' => 0,
        ]);

        $combinedRate = $this->darkMatterService->getRegenerationRatePerSecond($user);

        $rateForLevel = fn (int $level) => (1 * $level * (1.1 ** $level)) / 3600;
        $expectedRate = $rateForLevel(5) + $rateForLevel(3);

        $this->assertEqualsWithDelta($expectedRate, $combinedRate, 0.0001);
    }
}
