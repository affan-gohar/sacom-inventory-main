<?php

namespace Database\Factories;

use App\Enums\ShopType;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'code' => strtoupper(fake()->unique()->bothify('SHP-###??')),
            'type' => ShopType::Tier3,
            'is_active' => true,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['name' => 'SACOM Central Hub', 'code' => 'MAIN', 'type' => ShopType::Main]);
    }

    public function tier2(): static
    {
        return $this->state(fn () => ['type' => ShopType::Tier2]);
    }

    public function tier3(): static
    {
        return $this->state(fn () => ['type' => ShopType::Tier3]);
    }
}
