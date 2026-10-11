<?php

use App\Libraries\BankRateSync;
use CodeIgniter\Test\CIUnitTestCase;
use Config\BankRates;

/**
 * Provider field mapping (no database needed).
 *
 * @internal
 */
final class BankRateSyncTest extends CIUnitTestCase
{
    private function provider(array $overrides = []): array
    {
        return array_replace_recursive([
            'name'      => 'Test',
            'source'    => ['type' => 'file', 'path' => APPPATH . 'Data/bank_rates_sample.json'],
            'itemsPath' => 'data.products',
            'fields'    => [
                'code' => 'id', 'bank' => 'bank.name', 'product' => 'name', 'type' => 'category',
                'rate' => 'interest.annualRate', 'minBalance' => 'minimumBalance', 'currency' => 'currency',
                'effectiveDate' => 'interest.effectiveFrom',
            ],
            'typeMap'    => ['savings' => 'SAVINGS', 'current' => 'CURRENT', 'fixed_deposit' => 'FIXED_DEPOSIT'],
            'rateFormat' => 'percent',
        ], $overrides);
    }

    private function sync(): BankRateSync
    {
        return new BankRateSync(new BankRates());
    }

    public function testMapsNestedFields(): void
    {
        $row = $this->sync()->mapProduct([
            'id' => 'X-1', 'bank' => ['name' => ' Sampath  Bank '], 'name' => 'Savings Plus', 'category' => 'Savings',
            'currency' => 'lkr', 'minimumBalance' => '25000', 'interest' => ['annualRate' => '4.25', 'effectiveFrom' => '2026-09-01'],
        ], $this->provider());

        $this->assertSame('X-1', $row['externalcode']);
        $this->assertSame('Sampath Bank', $row['bankname']);
        $this->assertSame('SAVINGS', $row['producttype']);
        $this->assertSame(4.25, $row['interestrate']);
        $this->assertSame(25000.0, $row['minbalance']);
        $this->assertSame('LKR', $row['currency']);
        $this->assertSame('2026-09-01', $row['effectivedate']);
    }

    public function testFractionRatesBecomePercent(): void
    {
        $row = $this->sync()->mapProduct(
            ['id' => 'F', 'bank' => ['name' => 'B'], 'name' => 'P', 'interest' => ['annualRate' => 0.0425]],
            $this->provider(['rateFormat' => 'fraction'])
        );

        $this->assertSame(4.25, $row['interestrate']);
    }

    public function testUnknownTypeAndMissingCodeFallBack(): void
    {
        $row = $this->sync()->mapProduct(
            ['bank' => ['name' => 'B'], 'name' => 'Gold', 'category' => 'premium', 'interest' => ['annualRate' => 1]],
            $this->provider()
        );

        $this->assertSame('OTHER', $row['producttype']);
        $this->assertStringStartsWith('h:', $row['externalcode']);
        $this->assertSame('LKR', $row['currency']);
        $this->assertNull($row['minbalance']);
    }

    public function testRejectsItemsWithoutBankNameOrValidRate(): void
    {
        $sync = $this->sync();

        $this->assertNull($sync->mapProduct(['name' => 'P', 'interest' => ['annualRate' => 3]], $this->provider()));
        $this->assertNull($sync->mapProduct(['bank' => ['name' => 'B'], 'interest' => ['annualRate' => 3]], $this->provider()));
        $this->assertNull($sync->mapProduct(['bank' => ['name' => 'B'], 'name' => 'P', 'interest' => ['annualRate' => 'n/a']], $this->provider()));
        $this->assertNull($sync->mapProduct(['bank' => ['name' => 'B'], 'name' => 'P', 'interest' => ['annualRate' => 150]], $this->provider()));
    }

    public function testFixedBankNameForSingleBankFeeds(): void
    {
        $row = $this->sync()->mapProduct(['name' => 'P', 'interest' => ['annualRate' => 2]], $this->provider(['bank' => 'Bank of Ceylon']));

        $this->assertSame('Bank of Ceylon', $row['bankname']);
    }

    public function testStripsMarkupFromFeedText(): void
    {
        $row = $this->sync()->mapProduct(
            ['bank' => ['name' => '<b>Evil</b> Bank'], 'name' => '<script>x</script>Plan', 'interest' => ['annualRate' => 1]],
            $this->provider()
        );

        $this->assertSame('Evil Bank', $row['bankname']);
        $this->assertStringNotContainsString('<', $row['productname']);
    }

    public function testDigFollowsDotPathsAndListIndexes(): void
    {
        $sync = $this->sync();
        $data = ['a' => ['b' => [['c' => 5]]]];

        $this->assertSame(5, $sync->dig($data, 'a.b.0.c'));
        $this->assertNull($sync->dig($data, 'a.x'));
        $this->assertSame($data, $sync->dig($data, ''));
    }

    public function testBundledSampleFeedMapsEveryProduct(): void
    {
        $sync = $this->sync();
        $json = json_decode((string) file_get_contents(APPPATH . 'Data/bank_rates_sample.json'), true);
        $items = $sync->dig($json, 'data.products');

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertNotNull($sync->mapProduct($item, $this->provider()), json_encode($item));
        }
    }

    private function feeConfig(): array
    {
        return (new BankRates())->providers['sample']['fees'];
    }

    public function testMapsChargesThroughTypeAndChannelMaps(): void
    {
        $fees = $this->sync()->mapFees(['charges' => [
            ['transaction' => 'Withdrawal', 'channel' => 'other_atm', 'flat' => '30', 'note' => ' LankaPay '],
            ['transaction' => 'withdrawal', 'channel' => 'counter', 'percent' => 0.1, 'min' => 100, 'max' => 1000, 'freePerMonth' => 2],
        ]], ['fees' => $this->feeConfig()]);

        $this->assertSame(30.0, $fees['WITHDRAWAL|OTHER_ATM']['flatfee']);
        $this->assertSame('LankaPay', $fees['WITHDRAWAL|OTHER_ATM']['note']);
        $this->assertSame(0.1, $fees['WITHDRAWAL|COUNTER']['percentfee']);
        $this->assertSame(2, $fees['WITHDRAWAL|COUNTER']['freepermonth']);
    }

    public function testSkipsBadCharges(): void
    {
        $fees = $this->sync()->mapFees(['charges' => [
            ['transaction' => 'withdrawal', 'channel' => 'teleporter', 'flat' => 5],
            ['transaction' => 'refund', 'channel' => 'counter', 'flat' => 5],
            ['transaction' => 'withdrawal', 'channel' => 'own_atm', 'flat' => -5],
            ['transaction' => 'withdrawal', 'channel' => 'cdm', 'percent' => 150],
            'not a charge',
        ]], ['fees' => $this->feeConfig()]);

        $this->assertSame([], $fees);
        $this->assertSame([], $this->sync()->mapFees(['charges' => [['transaction' => 'withdrawal']]], []), 'no fee mapping = no fees');
    }

    public function testCalculatesFlatPercentFreeAndCaps(): void
    {
        $rule = ['txntype' => 'WITHDRAWAL', 'channel' => 'COUNTER', 'flatfee' => 0, 'percentfee' => 0.1, 'minfee' => 100, 'maxfee' => 1000, 'freepermonth' => 2];

        $this->assertSame(0.0, BankRateSync::calculateFee($rule, 50000, 1)['fee'], 'still free this month');
        $this->assertSame(100.0, BankRateSync::calculateFee($rule, 50000, 2)['fee'], '0.1% of 50,000 = 50, raised to the minimum');
        $this->assertSame(250.0, BankRateSync::calculateFee($rule, 250000, 5)['fee']);
        $this->assertSame(1000.0, BankRateSync::calculateFee($rule, 5000000, 5)['fee'], 'capped');

        $flat = ['txntype' => 'WITHDRAWAL', 'channel' => 'OTHER_ATM', 'flatfee' => 30, 'percentfee' => 0, 'minfee' => null, 'maxfee' => null, 'freepermonth' => 0];
        $this->assertSame(30.0, BankRateSync::calculateFee($flat, 1000)['fee']);
        $this->assertFalse(BankRateSync::calculateFee(null, 1000)['known']);
    }

    public function testSampleFeedChargesAreAllUsable(): void
    {
        $sync = $this->sync();
        $json = json_decode(file_get_contents(APPPATH . 'Data/bank_rates_sample.json'), true);
        $provider = (new BankRates())->providers['sample'];
        $count = 0;

        foreach ($json['data']['products'] as $item) {
            $mapped = $sync->mapFees($item, $provider);
            $this->assertCount(count($item['charges'] ?? []), $mapped, $item['id'] . ': every charge maps');
            $count += count($mapped);
        }

        $this->assertGreaterThan(20, $count);
    }
}
