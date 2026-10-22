<?php

namespace Database\Factories;

use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A new, unassigned callback lead from the site with a unique normalized
 * phone. Rows are inserted directly (no CreateLead: no duplicate check,
 * activity or event). States: quote(), serviceRequest(), checkout(),
 * assignedTo(), status(), lost(), converted(), duplicateOf(), forCustomer(),
 * manual().
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => LeadSource::Site,
            'type' => LeadType::Callback,
            'status' => LeadStatus::New,
            'name' => fake()->name(),
            'phone' => '+99890'.fake()->unique()->numerify('#######'),
            'email' => null,
            'company' => fake()->company(),
            'message' => null,
            'locale' => 'uz',
            'status_changed_at' => now(),
        ];
    }

    public function quote(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LeadType::Quote]);
    }

    public function serviceRequest(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LeadType::ServiceRequest]);
    }

    public function checkout(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LeadType::Checkout]);
    }

    public function manual(LeadSource $source = LeadSource::Phone): static
    {
        return $this->state(fn (array $attributes) => ['source' => $source]);
    }

    public function assignedTo(User $manager): static
    {
        return $this->state(fn (array $attributes) => ['manager_id' => $manager->id]);
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'closed_at' => $status->isClosed() ? now() : null,
        ]);
    }

    public function lost(LeadLostReason $reason = LeadLostReason::NoResponse, ?string $note = null): static
    {
        return $this->status(LeadStatus::Lost)->state(fn (array $attributes) => [
            'lost_reason' => $reason,
            'lost_note' => $note,
        ]);
    }

    public function converted(): static
    {
        return $this->status(LeadStatus::Converted);
    }

    public function duplicateOf(Lead $original): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => $original->phone,
            'duplicate_of_id' => $original->id,
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => ['customer_id' => $customer->id]);
    }
}
