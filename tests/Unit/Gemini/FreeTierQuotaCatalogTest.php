<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Gemini;

use Jayesh\LaravelGeminiTranslator\Gemini\FreeTierQuota;
use Jayesh\LaravelGeminiTranslator\Gemini\FreeTierQuotaCatalog;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class FreeTierQuotaCatalogTest extends TestCase
{
    public function test_shipped_snapshot_is_loaded_from_config(): void
    {
        $catalog = $this->app->make(FreeTierQuotaCatalog::class);

        $expected = [
            'gemini-3.5-flash-lite' => [15, 500],
            'gemini-3.1-flash-lite' => [15, 500],
            'gemini-2.5-flash-lite' => [10, 20],
            'gemini-2.5-flash' => [5, 20],
            'gemini-3.5-flash' => [5, 20],
            'gemini-3.6-flash' => [5, 20],
        ];

        foreach ($expected as $model => [$rpm, $rpd]) {
            $quota = $catalog->find($model);
            $this->assertNotNull($quota, $model);
            $this->assertSame($rpm, $quota->requestsPerMinute);
            $this->assertSame($rpd, $quota->requestsPerDay);
        }

        $this->assertSame('2026-08-13', $catalog->asOf());
        $this->assertCount(count($expected), $catalog->all());
    }

    public function test_config_can_add_a_new_model_with_higher_quota(): void
    {
        $catalog = FreeTierQuotaCatalog::fromConfig([
            'as_of' => '2026-12-01',
            'models' => [
                'gemini-4-flash-lite' => ['rpm' => 80, 'rpd' => 4000],
            ],
        ]);

        $quota = $catalog->find('models/Gemini-4-Flash-Lite');
        $this->assertNotNull($quota);
        $this->assertSame(80, $quota->requestsPerMinute);
        $this->assertSame(4000, $quota->requestsPerDay);
        $this->assertSame('2026-12-01', $quota->asOf);
    }

    public function test_config_can_decrease_or_zero_an_existing_model(): void
    {
        $catalog = FreeTierQuotaCatalog::fromConfig([
            'models' => [
                'gemini-2.5-flash-lite' => ['rpm' => 2, 'rpd' => 5],
                'gemini-3-flash' => ['rpm' => 0, 'rpd' => 0],
            ],
        ]);

        $reduced = $catalog->find('gemini-2.5-flash-lite');
        $this->assertNotNull($reduced);
        $this->assertSame(2, $reduced->effectiveConcurrency(15, false));

        $retired = $catalog->find('gemini-3-flash');
        $this->assertNotNull($retired);
        $this->assertTrue($retired->hasNoRequestBudget());
        $this->assertSame(1, $retired->effectiveConcurrency(15, false));
        $this->assertSame(8, $retired->effectiveConcurrency(8, true));
        $this->assertTrue($retired->exceedsDailyBudget(1));
    }

    public function test_null_row_removes_a_model_from_the_snapshot(): void
    {
        $catalog = FreeTierQuotaCatalog::fromConfig([
            'models' => [
                'gemini-2.5-flash-lite' => ['rpm' => 10, 'rpd' => 20],
                'gemini-3-flash' => null,
            ],
        ]);

        $this->assertNotNull($catalog->find('gemini-2.5-flash-lite'));
        $this->assertNull($catalog->find('gemini-3-flash'));
    }

    public function test_omitted_dimension_is_unknown_not_zero(): void
    {
        $catalog = FreeTierQuotaCatalog::fromConfig([
            'models' => [
                'gemini-new' => ['rpm' => 40],
            ],
        ]);

        $quota = $catalog->find('gemini-new');
        $this->assertNotNull($quota);
        $this->assertSame(40, $quota->requestsPerMinute);
        $this->assertNull($quota->requestsPerDay);
        $this->assertFalse($quota->exceedsDailyBudget(10_000));
        $this->assertSame('unknown', $quota->formatRpd());
    }

    public function test_unknown_models_are_not_invented(): void
    {
        $catalog = FreeTierQuotaCatalog::fromConfig([
            'models' => [
                'gemini-2.5-flash-lite' => ['rpm' => 10, 'rpd' => 20],
            ],
        ]);

        $this->assertNull($catalog->find('custom-proxy-model'));
    }

    public function test_caps_can_be_disabled(): void
    {
        $catalog = FreeTierQuotaCatalog::fromConfig([
            'apply_free_tier_caps' => false,
            'models' => [
                'gemini-2.5-flash-lite' => ['rpm' => 10, 'rpd' => 20],
            ],
        ]);

        $this->assertFalse($catalog->applyCaps());
    }

    public function test_normalize_strips_models_prefix(): void
    {
        $this->assertSame('gemini-2.5-flash-lite', FreeTierQuotaCatalog::normalize('models/Gemini-2.5-Flash-Lite'));
    }

    public function test_quota_value_object_handles_null_and_zero_rpm(): void
    {
        $unknownRpm = new FreeTierQuota('x', null, 100);
        $this->assertSame(15, $unknownRpm->effectiveConcurrency(15, false));

        $zero = new FreeTierQuota('y', 0, 0);
        $this->assertSame(1, $zero->effectiveConcurrency(15, false));
        $this->assertTrue($zero->exceedsRpm(1));
    }
}
