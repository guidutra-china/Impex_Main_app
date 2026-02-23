<?php

namespace Database\Factories;

use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    protected static array $chineseSuppliers = [
        ['name' => 'Shenzhen Brightstar Electronics Co., Ltd.', 'city' => 'Shenzhen', 'state' => 'Guangdong', 'country' => 'CN'],
        ['name' => 'Ningbo Haiyu Furniture Co., Ltd.', 'city' => 'Ningbo', 'state' => 'Zhejiang', 'country' => 'CN'],
        ['name' => 'Guangzhou Yongxin Textile Co., Ltd.', 'city' => 'Guangzhou', 'state' => 'Guangdong', 'country' => 'CN'],
        ['name' => 'Foshan Deli Hardware Co., Ltd.', 'city' => 'Foshan', 'state' => 'Guangdong', 'country' => 'CN'],
        ['name' => 'Yiwu Greenpack Packaging Co., Ltd.', 'city' => 'Yiwu', 'state' => 'Zhejiang', 'country' => 'CN'],
        ['name' => 'Dongguan Sunlight Solar Technology Co., Ltd.', 'city' => 'Dongguan', 'state' => 'Guangdong', 'country' => 'CN'],
        ['name' => 'Hangzhou Meida Cable Co., Ltd.', 'city' => 'Hangzhou', 'state' => 'Zhejiang', 'country' => 'CN'],
        ['name' => 'Xiamen Topbright Lighting Co., Ltd.', 'city' => 'Xiamen', 'state' => 'Fujian', 'country' => 'CN'],
        ['name' => 'Shanghai Huawei Industrial Co., Ltd.', 'city' => 'Shanghai', 'state' => 'Shanghai', 'country' => 'CN'],
        ['name' => 'Taizhou Jianeng Battery Co., Ltd.', 'city' => 'Taizhou', 'state' => 'Zhejiang', 'country' => 'CN'],
    ];

    protected static array $brazilianClients = [
        ['name' => 'Eletro Brasil Importadora Ltda.', 'city' => 'São Paulo', 'state' => 'SP', 'country' => 'BR'],
        ['name' => 'Casa & Conforto Distribuidora S.A.', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'country' => 'BR'],
        ['name' => 'Luminar Iluminação Ltda.', 'city' => 'Curitiba', 'state' => 'PR', 'country' => 'BR'],
        ['name' => 'Ferramentas do Sul Importação Ltda.', 'city' => 'Porto Alegre', 'state' => 'RS', 'country' => 'BR'],
        ['name' => 'Têxtil Nordeste Comércio Ltda.', 'city' => 'Recife', 'state' => 'PE', 'country' => 'BR'],
        ['name' => 'Solar Energy Brasil Ltda.', 'city' => 'Belo Horizonte', 'state' => 'MG', 'country' => 'BR'],
        ['name' => 'Pack Solutions Embalagens Ltda.', 'city' => 'Campinas', 'state' => 'SP', 'country' => 'BR'],
        ['name' => 'Movelaria Premium Importadora Ltda.', 'city' => 'Joinville', 'state' => 'SC', 'country' => 'BR'],
    ];

    protected static int $supplierIndex = 0;

    protected static int $clientIndex = 0;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company() . ' ' . fake()->randomElement(['Ltda.', 'S.A.', 'Co., Ltd.', 'LLC']),
            'tax_number' => fake()->numerify('##.###.###/####-##'),
            'website' => fake()->url(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address_street' => fake()->streetName(),
            'address_number' => fake()->buildingNumber(),
            'address_city' => fake()->city(),
            'address_state' => fake()->state(),
            'address_zip' => fake()->postcode(),
            'address_country' => fake()->randomElement(['BR', 'CN', 'US']),
            'status' => CompanyStatus::ACTIVE,
        ];
    }

    public function supplier(): static
    {
        $data = static::$chineseSuppliers[static::$supplierIndex % count(static::$chineseSuppliers)];
        static::$supplierIndex++;

        return $this->state(fn () => [
            'name' => $data['name'],
            'legal_name' => $data['name'],
            'tax_number' => fake()->numerify('91########CN####'),
            'website' => 'https://www.' . fake()->domainWord() . '.cn',
            'phone' => '+86 ' . fake()->numerify('### #### ####'),
            'email' => 'sales@' . fake()->domainWord() . '.cn',
            'address_street' => fake()->randomElement(['Xinhua Road', 'Zhongshan Avenue', 'Nanshan District', 'Baoan Industrial Zone', 'Longgang Technology Park']),
            'address_number' => fake()->numerify('Building ##'),
            'address_city' => $data['city'],
            'address_state' => $data['state'],
            'address_zip' => fake()->numerify('######'),
            'address_country' => 'CN',
            'status' => CompanyStatus::ACTIVE,
        ]);
    }

    public function client(): static
    {
        $data = static::$brazilianClients[static::$clientIndex % count(static::$brazilianClients)];
        static::$clientIndex++;

        return $this->state(fn () => [
            'name' => $data['name'],
            'legal_name' => $data['name'],
            'tax_number' => fake('pt_BR')->cnpj(),
            'website' => 'https://www.' . fake()->domainWord() . '.com.br',
            'phone' => '+55 ' . fake()->numerify('## #####-####'),
            'email' => 'compras@' . fake()->domainWord() . '.com.br',
            'address_street' => fake('pt_BR')->streetName(),
            'address_number' => fake()->buildingNumber(),
            'address_city' => $data['city'],
            'address_state' => $data['state'],
            'address_zip' => fake()->numerify('#####-###'),
            'address_country' => 'BR',
            'status' => CompanyStatus::ACTIVE,
        ]);
    }

    public function prospect(): static
    {
        return $this->state(fn () => [
            'status' => CompanyStatus::PROSPECT,
        ]);
    }
}
