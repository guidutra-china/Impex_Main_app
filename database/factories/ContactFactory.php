<?php

namespace Database\Factories;

use App\Domain\CRM\Enums\ContactFunction;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'function' => fake()->randomElement(ContactFunction::cases()),
            'position' => fake()->randomElement(['Manager', 'Director', 'Coordinator', 'Analyst', 'Assistant']),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => [
            'is_primary' => true,
        ]);
    }

    public function chinese(): static
    {
        $surnames = ['Wang', 'Li', 'Zhang', 'Liu', 'Chen', 'Yang', 'Huang', 'Zhao', 'Wu', 'Zhou'];
        $names = ['Wei', 'Fang', 'Ming', 'Jing', 'Hua', 'Xin', 'Lei', 'Yan', 'Ping', 'Jun'];

        $fullName = fake()->randomElement($surnames) . ' ' . fake()->randomElement($names);

        return $this->state(fn () => [
            'name' => $fullName,
            'email' => strtolower(str_replace(' ', '.', $fullName)) . '@' . fake()->domainWord() . '.cn',
            'phone' => '+86 ' . fake()->numerify('1## #### ####'),
            'wechat' => strtolower(str_replace(' ', '', $fullName)) . fake()->numerify('##'),
        ]);
    }

    public function brazilian(): static
    {
        return $this->state(fn () => [
            'name' => fake('pt_BR')->name(),
            'email' => fake('pt_BR')->safeEmail(),
            'phone' => '+55 ' . fake()->numerify('## #####-####'),
            'whatsapp' => '+55' . fake()->numerify('###########'),
        ]);
    }
}
