<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;

class ChannelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Channel::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'enabled' => $this->faker->boolean(),
            'channel' => $this->faker->randomNumber(),
            'url' => $this->faker->url(),
            'logo' => $this->faker->word(),
            'group' => $this->faker->word(),
            'tvgid' => $this->faker->word(),
            'lang' => $this->faker->word(),
            'country' => $this->faker->country(),
            'playlist_id' => Playlist::factory(),
            'group_id' => Group::factory(),
        ];
    }
}
