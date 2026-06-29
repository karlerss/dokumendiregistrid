<?php

namespace Database\Factories;

use App\Models\TakedownRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TakedownRequest>
 */
class TakedownRequestFactory extends Factory
{
    protected $model = TakedownRequest::class;

    public function definition(): array
    {
        return [
            'original_document_url' => fake()->url(),
            'status' => TakedownRequest::STATUS_UNVERIFIED,
            'author_name' => fake()->name(),
            'author_email' => fake()->safeEmail(),
            'legal_basis' => TakedownRequest::LEGAL_BASES[0],
            'objection_note' => fake()->sentence(),
            'ip' => fake()->ipv4(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TakedownRequest::STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }
}
