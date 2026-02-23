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

    protected static array $brazilianStreets = [
        'Rua Augusta', 'Av. Paulista', 'Rua das Flores', 'Av. Brasil',
        'Rua XV de Novembro', 'Av. Atlântica', 'Rua da Consolação',
        'Av. Presidente Vargas', 'Rua Oscar Freire', 'Av. Beira Mar',
    ];

    protected static int $supplierIndex = 0;

    protected static int $clientIndex = 0;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'legal_name' => $this->faker->company() . ' ' . $this->faker->randomElement(['Ltda.', 'S.A.', 'Co., Ltd.', 'LLC']),
            'tax_number' => $this->faker->numerify('##.###.###/####-##'),
            'website' => $this->faker->url(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
            'address_street' => $this->faker->streetName(),
            'address_number' => $this->faker->buildingNumber(),
            'address_city' => $this->faker->city(),
            'address_state' => $this->faker->state(),
            'address_zip' => $this->faker->postcode(),
            'address_country' => $this->faker->randomElement(['BR', 'CN', 'US']),
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
            'tax_number' => $this->faker->numerify('91########CN####'),
            'website' => 'https://www.' . $this->faker->domainWord() . '.cn',
            'phone' => '+86 ' . $this->faker->numerify('### #### ####'),
            'email' => 'sales@' . $this->faker->domainWord() . '.cn',
            'address_street' => $this->faker->randomElement(['Xinhua Road', 'Zhongshan Avenue', 'Nanshan District', 'Baoan Industrial Zone', 'Longgang Technology Park']),
            'address_number' => $this->faker->numerify('Building ##'),
            'address_city' => $data['city'],
            'address_state' => $data['state'],
            'address_zip' => $this->faker->numerify('######'),
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
            'tax_number' => $this->faker->numerify('##.###.###/####-##'),
            'website' => 'https://www.' . $this->faker->domainWord() . '.com.br',
            'phone' => '+55 ' . $this->faker->numerify('## #####-####'),
            'email' => 'compras@' . $this->faker->domainWord() . '.com.br',
            'address_street' => $this->faker->randomElement(static::$brazilianStreets),
            'address_number' => $this->faker->buildingNumber(),
            'address_city' => $data['city'],
            'address_state' => $data['state'],
            'address_zip' => $this->faker->numerify('#####-###'),
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
