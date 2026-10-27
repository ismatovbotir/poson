<?php

use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Telegram\LeadMessage;

it('names the roll and the rolls of a label line in the Telegram message, escaped', function () {
    $roll = RollType::factory()->size(1000, '40.0')->create();
    $product = Product::factory()->perLabel()->withRollTypes([$roll])->create(['sku' => 'LBL<1>']);
    $lead = Lead::factory()->quote()->create();
    LeadItem::factory()->for($lead)->create([
        'product_id' => $product->id,
        'sku' => 'LBL<1>',
        'product_name' => 'Labels & <b>tags</b>',
        'qty' => 5,
        'roll_type_id' => $roll->id,
        'labels_per_roll' => 1000,
        'core_diameter_mm' => '40.0',
    ]);
    LeadItem::factory()->for($lead)->create(['product_name' => 'Zebra ZD421', 'sku' => 'ZD421', 'qty' => 2]);

    $html = LeadMessage::for($lead->load(['items', 'customer']), 'ru')->html;

    expect($html)->toContain("Labels &amp; &lt;b&gt;tags&lt;/b&gt; (LBL&lt;1&gt;), 1\u{00A0}000 этикеток/рулон, втулка 40 мм × 5 рулонов")
        ->toContain('Zebra ZD421 (ZD421) ×2')
        ->not->toContain('<b>tags</b>');
});
