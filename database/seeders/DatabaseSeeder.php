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
            'name' => 'ស៊ីប៊ីវីអេច CBVH',
            'code' => 'HQ',
            'is_hq' => true,
            'address' => 'Phnom Penh HQ',
            'is_active' => true,
        ]);

        $branch1 = Branch::create([
            'name' => 'ជាង បាក់ វណ្ណ ហុង (បាក់ទូក) Cheang Bak Van Hong (BTK)',
            'code' => 'BTK',
            'is_hq' => false,
            'address' => 'Bak Touk, Phnom Penh',
            'is_active' => true,
        ]);

        // 2. Rooms (HQ Rooms + BTK Branch Room)
        Room::create(['branch_id' => $hq->id, 'name' => 'Showroom', 'floor' => 'Ground Floor']);
        Room::create(['branch_id' => $hq->id, 'name' => 'VIP 1', 'floor' => 'Floor 3']);
        Room::create(['branch_id' => $hq->id, 'name' => 'VIP 2', 'floor' => 'Floor 3']);
        Room::create(['branch_id' => $hq->id, 'name' => 'CEO Room', 'floor' => 'Floor 3']);
        Room::create(['branch_id' => $hq->id, 'name' => 'បន្ទប់អគ្គនាយិកា', 'floor' => 'Floor 3']);
        Room::create(['branch_id' => $hq->id, 'name' => 'F4 Meeting Room', 'floor' => 'Floor 4']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Hall 1', 'floor' => 'Floor 5']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Hall 2', 'floor' => 'Floor 5']);
        Room::create(['branch_id' => $hq->id, 'name' => 'Hall 3', 'floor' => 'Floor 5']);
        Room::create(['branch_id' => $branch1->id, 'name' => 'បាក់ទូក', 'floor' => 'Ground Floor']);

        // 3. Default System Roles (Demo / Web fallback)
        User::create([
            'name' => 'System Admin',
            'employee_id' => 'ADM-001',
            'email' => 'admin@company.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'branch_id' => $hq->id,
            'phone' => '+855 12 111 222',
        ]);

        User::create([
            'name' => 'Linda Chen (Barista)',
            'employee_id' => 'BAR-001',
            'email' => 'barista@company.com',
            'password' => Hash::make('password'),
            'role' => 'barista',
            'branch_id' => $hq->id,
            'phone' => '+855 12 777 888',
        ]);

        User::create([
            'name' => 'Emily Clark (HQ Staff)',
            'employee_id' => 'HQ-001',
            'email' => 'employee@company.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'branch_id' => $hq->id,
            'phone' => '+855 12 000 111',
        ]);

        User::create([
            'name' => 'Dara Sok (Branch Staff)',
            'employee_id' => 'BTK-001',
            'email' => 'branch.staff@company.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'branch_id' => $branch1->id,
            'phone' => '+855 12 222 333',
        ]);

        // 4. Official Drink Menu Items seeded for every branch
        $menuTemplate = [
            [
                'name' => 'Ice Milk Coffee',
                'category' => 'coffee',
                'description' => 'Traditional iced milk coffee brewed fresh with rich condensed milk.',
                'options_config' => [
                    'sugar_levels' => ['No Sugar', 'Less Sugar', 'Normal Sugar'],
                    'ice_levels' => ['Less Ice', 'Normal Ice', 'More Ice', 'Separate Ice'],
                ],
            ],
            [
                'name' => 'Red Lemon Tea',
                'category' => 'tea',
                'description' => 'Refreshing brewed red tea infused with fresh fragrant lemon juice.',
                'options_config' => [
                    'sugar_levels' => ['No Sugar', 'Less Sugar', 'Normal Sugar'],
                    'ice_levels' => ['Less Ice', 'Normal Ice', 'More Ice', 'Separate Ice'],
                ],
            ],
            [
                'name' => 'Samrong Basil Seeds',
                'category' => 'tea',
                'description' => 'Traditional cooling herbal beverage with malva nut (Samrong) and basil seeds.',
                'options_config' => [
                    'sugar_levels' => ['No Sugar', 'Less Sugar', 'Normal Sugar'],
                    'ice_levels' => ['Less Ice', 'Normal Ice', 'More Ice', 'Separate Ice'],
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
