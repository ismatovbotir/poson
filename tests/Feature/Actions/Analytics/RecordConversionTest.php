<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\Role;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use App\Livewire\Site\LeadForm;
use App\Models\Product;
use App\Models\SiteVisit;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

beforeEach(function () {
    config(['analytics.enabled' => true]);
});

it('records a lead from an AI assistant visit as a conversion with the session attribution', function () {
    $product = Product::factory()->create();

    session()->put('attribution', ['channel' => 'ai', 'source' => 'chatgpt.com', 'utm_source' => 'chatgpt.com', 'landing' => '/uz/product/'.$product->slug]);

    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (SiteLeadData $data): bool => $data->trafficChannel === TrafficChannel::Ai
            && $data->trafficSource === 'chatgpt.com'
            && $data->landingPath === '/uz/product/'.$product->slug
            && $data->utm === ['utm_source' => 'chatgpt.com'])
        ->andReturn('L-TEST');

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67');

    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(SiteVisit::sole())
        ->type->toBe(VisitType::Lead)
        ->channel->toBe(TrafficChannel::Ai)
        ->source->toBe('chatgpt.com')
        ->product_id->toBe($product->id)
        ->is_entry->toBeFalse()
        ->visitor_hash->toMatch('/^[0-9a-f]{16}$/');
});

it('does not record a conversion for bot-like submissions or staff', function (bool $staff) {
    $this->mock(SubmitSiteLead::class)->shouldReceive('handle')->andReturn('L-TEST');

    if ($staff) {
        $this->actingAs(staffUser(Role::SalesManager));
    }

    $component = Livewire::test(LeadForm::class)->set('name', 'Aziz')->set('phone', '90 123 45 67');

    if ($staff) {
        $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    }

    $component->call('submit')->assertSet('sent', true);

    expect(SiteVisit::count())->toBe(0);
})->with(['too fast (bot)' => [false], 'staff' => [true]]);

it('still accepts the lead when analytics fails', function () {
    $this->mock(SubmitSiteLead::class)->shouldReceive('handle')->once()->andReturn('L-TEST');
    $this->mock(TrafficRecorder::class)->shouldReceive('recordLead')->andThrow(new RuntimeException('database down'));
    Exceptions::fake();

    $component = Livewire::test(LeadForm::class)->set('name', 'Aziz')->set('phone', '90 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Exceptions::assertReported(RuntimeException::class);
    expect(SiteVisit::count())->toBe(0);
});
