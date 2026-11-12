<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Room;
use App\Models\User;
use App\Models\DrinkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Branches
        $hq = Branch::create([
            'name' => 'CBVH HQ (Boeung Kak)',
            'code' => 'HQ',
            'is_hq' => true,
            'address' => 'Boeung Kak Flagship HQ, Phnom Penh',
            'is_active' => true,
        ]);

        $branch1 = Branch::create([
            'name' => 'CBVH Branch (Bak Touk)',
            'code' => 'BR-BT',
            'is_hq' => false,
            'address' => 'Bak Touk Branch, Phnom Penh',
            'is_active' => true,
        ]);

        // 2. Rooms (Include Lounge & Meeting Rooms at HQ + Branch Meeting Room)
        Room::create(['branch_id' => $hq->id, 'name' => 'Executive Lounge', 'floor' => 'Floor 1']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Boardroom VIP', 'floor' => 'Floor 10']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Meeting Room A', 'floor' => 'Floor 2']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Meeting Room B', 'floor' => 'Floor 2']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Executive Office', 'floor' => 'Floor 9']);
        Room::create(['branch_id' => $branch1->id, 'name' => 'Branch Meeting Room', 'floor' => 'Floor 1']);

        // 3. Default System Roles (Demo / Web fallback)
        User::create([
            'name' => 'System Admin',
            'email' => 'admin@company.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'branch_id' => $hq->id,
            'phone' => '+855 12 111 222',
        ]);

        User::create([
            'name' => 'Linda Chen (Barista)',
            'email' => 'barista@company.com',
            'password' => Hash::make('password'),
            'role' => 'barista',
            'branch_id' => $hq->id,
            'phone' => '+855 12 777 888',
        ]);

        User::create([
            'name' => 'Emily Clark (HQ Staff)',
            'email' => 'employee@company.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'branch_id' => $hq->id,
            'phone' => '+855 12 000 111',
        ]);

        User::create([
            'name' => 'Dara Sok (Branch Staff)',
            'email' => 'branch.staff@company.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'branch_id' => $branch1->id,
            'phone' => '+855 12 222 333',
        ]);

        // 4. Drink Menu Items seeded for every branch
        $menuTemplate = [
            [
                'name' => 'Iced Americano',
                'category' => 'coffee',
                'description' => 'Espresso diluted with chilled water and poured over ice.',
                'options_config' => [
                    'sugar_levels' => ['0%', '25%', '50%', '75%', '100%'],
                    'coffee_levels' => ['Single Shot', 'Double Shot', 'Decaf'],
                    'ice_levels' => ['No Ice', 'Less Ice', 'Normal Ice'],
                ],
            ],
            [
                'name' => 'Hot Latte',
                'category' => 'coffee',
                'description' => 'Rich espresso with steamed velvety milk and micro-foam.',
                'options_config' => [
                    'sugar_levels' => ['0%', '25%', '50%', '75%', '100%'],
                    'coffee_levels' => ['Single Shot', 'Double Shot', 'Decaf'],
                    'milk_types' => ['Fresh Whole Milk', 'Oat Milk', 'Almond Milk', 'Soy Milk'],
                ],
            ],
            [
                'name' => 'Iced Green Tea Latte',
                'category' => 'tea',
                'description' => 'Pure Japanese matcha green tea whisked with fresh cold milk.',
                'options_config' => [
                    'sugar_levels' => ['0%', '25%', '50%', '75%', '100%'],
                    'ice_levels' => ['No Ice', 'Less Ice', 'Normal Ice'],
                    'milk_types' => ['Fresh Whole Milk', 'Oat Milk'],
                ],
            ],
            [
                'name' => 'Fresh Squeezed Orange Juice',
                'category' => 'juice',
                'description' => '100% freshly pressed natural orange juice.',
                'options_config' => [
                    'sugar_levels' => ['0% (Natural)', '50%'],
                    'ice_levels' => ['No Ice', 'Normal Ice'],
                ],
            ],
        ];

        foreach ([$hq, $branch1] as $branch) {
            foreach ($menuTemplate as $item) {
                DrinkItem::create([
                    'branch_id' => $branch->id,
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'description' => $item['description'],
                    'options_config' => $item['options_config'],
                    'is_available' => true,
                ]);
            }
        }
    }
}
