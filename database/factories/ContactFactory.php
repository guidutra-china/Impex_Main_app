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

    protected static array $brazilianNames = [
        'Carlos Silva', 'Ana Oliveira', 'Pedro Santos', 'Maria Costa',
        'João Souza', 'Fernanda Lima', 'Ricardo Pereira', 'Juliana Almeida',
        'Bruno Ferreira', 'Camila Rodrigues', 'Lucas Martins', 'Patricia Gomes',
    ];

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'function' => $this->faker->randomElement(ContactFunction::cases()),
            'position' => $this->faker->randomElement(['Manager', 'Director', 'Coordinator', 'Analyst', 'Assistant']),
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

        $fullName = $this->faker->randomElement($surnames) . ' ' . $this->faker->randomElement($names);

        return $this->state(fn () => [
            'name' => $fullName,
            'email' => strtolower(str_replace(' ', '.', $fullName)) . '@' . $this->faker->domainWord() . '.cn',
            'phone' => '+86 ' . $this->faker->numerify('1## #### ####'),
            'wechat' => strtolower(str_replace(' ', '', $fullName)) . $this->faker->numerify('##'),
        ]);
    }

    public function brazilian(): static
    {
        $name = $this->faker->randomElement(static::$brazilianNames);
        $emailName = strtolower(str_replace(' ', '.', $name));

        return $this->state(fn () => [
            'name' => $name,
            'email' => $emailName . '@' . $this->faker->domainWord() . '.com.br',
            'phone' => '+55 ' . $this->faker->numerify('## #####-####'),
            'whatsapp' => '+55' . $this->faker->numerify('###########'),
        ]);
    }
}
