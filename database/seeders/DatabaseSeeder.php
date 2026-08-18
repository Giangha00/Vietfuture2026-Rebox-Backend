<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Notification;
use App\Models\OtpCode;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    private const CONDITIONS = ['Like New', 'Good', 'Fair'];

    private const PRODUCT_STATUSES = ['active', 'reserved', 'sold', 'archived'];

    private const MODERATION_STATUSES = ['pending', 'approved', 'rejected'];

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        Offer::query()->truncate();
        Order::query()->truncate();
        Notification::query()->truncate();
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->truncate();
        }
        OtpCode::query()->truncate();
        Product::query()->truncate();
        Category::query()->truncate();
        Station::query()->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('model_has_permissions')->truncate();
        User::query()->truncate();
        Schema::enableForeignKeyConstraints();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $profiles = $this->userProfiles();

        $users = collect($profiles)->map(function (array $profile, int $index) {
            return User::query()->create([
                'full_name' => $profile['fullName'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
                'password' => 'Rebox@123',
                'role' => 'user',
                'avatar_url' => '/default-avatar.svg',
                'bio' => $profile['bio'],
                'email_verified' => true,
                'email_verified_at' => now(),
                'fcm_tokens' => [],
                'delivery_address' => [
                    'fullName' => $profile['fullName'],
                    'phone' => $profile['phone'],
                    'line1' => (12 + $index).' Nguyen Trai',
                    'line2' => 'Floor '.(($index % 5) + 1),
                    'city' => $index % 2 === 0 ? 'Ho Chi Minh' : 'Ha Noi',
                    'district' => $index % 2 === 0 ? 'District 1' : 'Cau Giay',
                    'note' => 'Ring the bell',
                ],
                'pickup_address' => [
                    'fullName' => $profile['fullName'],
                    'phone' => $profile['phone'],
                    'line1' => (30 + $index).' Le Lai',
                    'line2' => '',
                    'city' => $index % 2 === 0 ? 'Ho Chi Minh' : 'Ha Noi',
                    'district' => $index % 2 === 0 ? 'District 3' : 'Dong Da',
                    'note' => 'Seller pickup point',
                ],
            ]);
        });

        $shipper = User::query()->create([
            'full_name' => 'Shipper Demo',
            'email' => 'shipper@rebox.com',
            'phone' => '0912345678',
            'password' => 'Rebox@123',
            'role' => 'shipper',
            'avatar_url' => '/default-avatar.svg',
            'bio' => 'Demo shipper account.',
            'email_verified' => true,
            'email_verified_at' => now(),
            'fcm_tokens' => [],
            'delivery_address' => [
                'fullName' => 'Shipper Demo',
                'phone' => '0912345678',
                'line1' => '1 Shipper Depot',
                'line2' => '',
                'city' => 'Ho Chi Minh',
                'district' => 'Tan Binh',
                'note' => '',
            ],
            'pickup_address' => [
                'fullName' => 'Shipper Demo',
                'phone' => '0912345678',
                'line1' => '1 Shipper Depot',
                'line2' => '',
                'city' => 'Ho Chi Minh',
                'district' => 'Tan Binh',
                'note' => '',
            ],
        ]);

        $admin = User::query()->create([
            'full_name' => 'ReBox Admin',
            'email' => 'admin@rebox.com',
            'phone' => '0900000000',
            'password' => 'Admin@123',
            'role' => 'admin',
            'avatar_url' => '/default-avatar.svg',
            'bio' => 'System administrator',
            'email_verified' => true,
            'email_verified_at' => now(),
            'fcm_tokens' => [],
            'delivery_address' => [
                'fullName' => 'ReBox Admin',
                'phone' => '0900000000',
                'line1' => '100 Admin Street',
                'line2' => '',
                'city' => 'Ho Chi Minh',
                'district' => 'District 1',
                'note' => '',
            ],
            'pickup_address' => [
                'fullName' => 'ReBox Admin',
                'phone' => '0900000000',
                'line1' => '100 Admin Street',
                'line2' => '',
                'city' => 'Ho Chi Minh',
                'district' => 'District 1',
                'note' => '',
            ],
        ]);

        // Filament Shield RBAC (separate from users.role used by the marketplace API)
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('panel_user', 'web');
        $admin->assignRole('super_admin');

        $sellers = $users->values();

        $categoryRows = [
            ['name' => 'Keyboards', 'slug' => 'keyboards', 'icon' => 'keyboard'],
            ['name' => 'Mice', 'slug' => 'mice', 'icon' => 'mouse'],
            ['name' => 'Monitors', 'slug' => 'monitors', 'icon' => 'monitor'],
        ];

        $categories = collect($categoryRows)->mapWithKeys(function (array $row) {
            $category = Category::query()->create($row);

            return [$row['slug'] => $category];
        });

        $stationRows = [
            ['city' => 'Ho Chi Minh', 'address' => 'Legacy station (unused)', 'locker_code' => 'A12', 'partner_name' => 'Circle K', 'is_active' => false],
        ];

        $stations = collect($stationRows)->map(fn (array $row) => Station::query()->create($row))->values();

        $templates = $this->productTemplates();
        $products = collect();

        for ($index = 0; $index < 100; $index++) {
            $template = $this->pick($templates, $index);
            $seller = $this->pick($sellers->all(), $index);
            $category = $categories[$template['category']] ?? $categories['keyboards'];
            $condition = $this->pick(self::CONDITIONS, $index);
            $status = $index % 10 < 7 ? 'active' : $this->pick(self::PRODUCT_STATUSES, $index);
            $moderationStatus = $index % 10 < 7
                ? 'approved'
                : $this->pick(self::MODERATION_STATUSES, intdiv($index, 2));
            $moderation = $this->buildModeration($moderationStatus, $admin->id, $index);

            $products->push(Product::query()->create(array_merge([
                'title' => $template['title'].' #'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'brand' => $template['brand'],
                'description' => implode(' ', [
                    $template['title'].' in '.strtolower($condition).' condition.',
                    'Listed by '.$seller->full_name.'.',
                    $status === 'active'
                        ? 'Item stays with the seller until a courier picks it up after purchase.'
                        : 'Current listing status: '.$status.'.',
                    $moderationStatus === 'rejected'
                        ? 'Needs seller updates before approval.'
                        : 'Includes original accessories when available.',
                ]),
                'price' => $this->priceFor($template['basePrice'], $index),
                'condition' => $condition,
                'attributes' => $template['attributes'],
                'images' => $template['images'],
                'is_verified' => $moderationStatus === 'approved' && $index % 4 === 0,
                'seller_id' => $seller->id,
                'category_id' => $category->id,
                'station_id' => null,
                'status' => $status,
                'accepts_offers' => $index % 5 !== 0,
            ], $moderation)));
        }

        $approvedActive = $products
            ->filter(fn (Product $p) => $p->moderation_status === 'approved' && $p->status === 'active')
            ->values();

        if ($approvedActive->count() >= 2) {
            $ttl = now()->addHours(48);
            $targets = [
                [$approvedActive[0], $sellers[2], 10, 'Can pick up today.'],
                [$approvedActive[1], $sellers[0], 5, 'Offer for fast trade.'],
                [
                    $approvedActive[min(5, $approvedActive->count() - 1)],
                    $sellers[5],
                    15,
                    'Interested if box is included.',
                ],
            ];

            foreach ($targets as [$product, $buyer, $percent, $message]) {
                Offer::query()->create([
                    'product_id' => $product->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $product->seller_id,
                    'list_price' => $product->price,
                    'discount_percent' => $percent,
                    'offer_price' => Offer::calcOfferPrice((float) $product->price, $percent),
                    'message' => $message,
                    'status' => 'pending',
                    'expires_at' => $ttl,
                ]);
            }
        }

        $this->command?->info('Seed completed.');
        $this->command?->table(
            ['Metric', 'Count'],
            [
                ['users', $sellers->count()],
                ['shipper', 1],
                ['admin', 1],
                ['categories', $categories->count()],
                ['stations', $stations->count()],
                ['products', $products->count()],
                ['offers', Offer::query()->count()],
            ]
        );
        $this->command?->info('Demo logins: *@rebox.com / Rebox@123 | shipper@rebox.com / Rebox@123 | admin@rebox.com / Admin@123');
    }

    private function pick(array $list, int $index): mixed
    {
        return $list[$index % count($list)];
    }

    private function priceFor(float|int $basePrice, int $index): float
    {
        $jitter = (($index * 17) % 41) - 20;

        return (float) max(8, (int) round($basePrice + $jitter));
    }

    private function buildModeration(string $status, int $adminId, int $index): array
    {
        if ($status === 'approved') {
            return [
                'moderation_status' => 'approved',
                'moderated_at' => now()->subHours($index),
                'moderated_by' => $adminId,
                'rejection_reason' => '',
            ];
        }

        if ($status === 'rejected') {
            return [
                'moderation_status' => 'rejected',
                'moderated_at' => now()->subHours($index),
                'moderated_by' => $adminId,
                'rejection_reason' => $this->pick([
                    'Blurry product photos.',
                    'Incomplete description.',
                    'Price looks unrealistic.',
                    'Item does not match category.',
                ], $index),
            ];
        }

        return [
            'moderation_status' => 'pending',
            'moderated_at' => null,
            'moderated_by' => null,
            'rejection_reason' => '',
        ];
    }

    private function img(string $filename): string
    {
        $base = rtrim((string) config('rebox.public_base_url', config('app.url')), '/');

        return $base.'/seed/'.$filename;
    }

    private function userProfiles(): array
    {
        return [
            ['fullName' => 'Marcus Chen', 'email' => 'marcus@rebox.com', 'phone' => '0900000001', 'bio' => 'Keyboard collector in District 1.'],
            ['fullName' => 'Linh Tran', 'email' => 'linh@rebox.com', 'phone' => '0900000002', 'bio' => 'Desk setup & peripheral seller.'],
            ['fullName' => 'Buyer Demo', 'email' => 'buyer@rebox.com', 'phone' => '0900000003', 'bio' => 'Demo buyer account.'],
            ['fullName' => 'Minh Nguyen', 'email' => 'minh@rebox.com', 'phone' => '0900000004', 'bio' => 'Monitor upgrade specialist.'],
            ['fullName' => 'An Pham', 'email' => 'an@rebox.com', 'phone' => '0900000005', 'bio' => 'Wireless mice & office gear.'],
            ['fullName' => 'Hoa Le', 'email' => 'hoa@rebox.com', 'phone' => '0900000006', 'bio' => 'Compact mechanical keyboards.'],
            ['fullName' => 'Khoa Vo', 'email' => 'khoa@rebox.com', 'phone' => '0900000007', 'bio' => 'Gaming peripherals.'],
            ['fullName' => 'Thu Dang', 'email' => 'thu@rebox.com', 'phone' => '0900000008', 'bio' => 'Ultralight mice.'],
            ['fullName' => 'Bao Hoang', 'email' => 'bao@rebox.com', 'phone' => '0900000009', 'bio' => '144Hz+ monitor finder.'],
            ['fullName' => 'Nga Bui', 'email' => 'nga@rebox.com', 'phone' => '0900000010', 'bio' => 'Minimal desk peripherals.'],
            ['fullName' => 'Quang Do', 'email' => 'quang@rebox.com', 'phone' => '0900000011', 'bio' => 'Laptop docking & monitors.'],
            ['fullName' => 'Yen Mai', 'email' => 'yen@rebox.com', 'phone' => '0900000012', 'bio' => 'Quiet office keyboards.'],
            ['fullName' => 'Dat Phan', 'email' => 'dat@rebox.com', 'phone' => '0900000013', 'bio' => 'Esports mouse gear.'],
            ['fullName' => 'Trang Vu', 'email' => 'trang@rebox.com', 'phone' => '0900000014', 'bio' => 'Hot-swap keyboard builds.'],
            ['fullName' => 'Hung Tran', 'email' => 'hung@rebox.com', 'phone' => '0900000015', 'bio' => 'IPS monitor specialist.'],
            ['fullName' => 'My Chau', 'email' => 'my@rebox.com', 'phone' => '0900000016', 'bio' => 'Wireless productivity mice.'],
            ['fullName' => 'Son Le', 'email' => 'son@rebox.com', 'phone' => '0900000017', 'bio' => 'RGB gaming keyboards.'],
            ['fullName' => 'Lan Huynh', 'email' => 'lan@rebox.com', 'phone' => '0900000018', 'bio' => 'Study desk setups.'],
            ['fullName' => 'Phuc Ngo', 'email' => 'phuc@rebox.com', 'phone' => '0900000019', 'bio' => '4K creator monitors.'],
            ['fullName' => 'Ha Dinh', 'email' => 'ha@rebox.com', 'phone' => '0900000020', 'bio' => 'Budget peripherals.'],
        ];
    }

    private function productTemplates(): array
    {
        return [
            [
                'title' => 'Keychron K2 V2 Brown Switch',
                'category' => 'keyboards',
                'brand' => 'Keychron',
                'basePrice' => 79,
                'attributes' => ['layout' => '75%', 'switch' => 'brown', 'connectivity' => 'wireless', 'hot_swap' => true],
                'images' => [$this->img('keyboard-custom-65.jpg')],
            ],
            [
                'title' => 'Akko 3068B Plus Red Switch',
                'category' => 'keyboards',
                'brand' => 'Akko',
                'basePrice' => 69,
                'attributes' => ['layout' => '65%', 'switch' => 'red', 'connectivity' => 'tri-mode', 'hot_swap' => true],
                'images' => [$this->img('keyboard-designer.jpg')],
            ],
            [
                'title' => 'Logitech G Pro X TKL',
                'category' => 'keyboards',
                'brand' => 'Logitech',
                'basePrice' => 115,
                'attributes' => ['layout' => 'TKL', 'switch' => 'other', 'connectivity' => 'wired', 'hot_swap' => true],
                'images' => [$this->img('keyboard-rgb-blue.jpg')],
            ],
            [
                'title' => 'Razer Huntsman Mini 60%',
                'category' => 'keyboards',
                'brand' => 'Razer',
                'basePrice' => 95,
                'attributes' => ['layout' => '60%', 'switch' => 'red', 'connectivity' => 'wired', 'hot_swap' => false],
                'images' => [$this->img('keyboard-rgb-purple.jpg')],
            ],
            [
                'title' => 'Royal Kludge RK84 Blue Switch',
                'category' => 'keyboards',
                'brand' => 'Royal Kludge',
                'basePrice' => 55,
                'attributes' => ['layout' => '75%', 'switch' => 'blue', 'connectivity' => 'tri-mode', 'hot_swap' => true],
                'images' => [$this->img('keyboard-rgb-rainbow.jpg')],
            ],
            [
                'title' => 'Corsair K70 RGB Full Size',
                'category' => 'keyboards',
                'brand' => 'Corsair',
                'basePrice' => 120,
                'attributes' => ['layout' => 'Full', 'switch' => 'red', 'connectivity' => 'wired', 'hot_swap' => false],
                'images' => [$this->img('keyboard-rgb-rainbow.jpg')],
            ],
            [
                'title' => 'NuPhy Air75 Silent',
                'category' => 'keyboards',
                'brand' => 'NuPhy',
                'basePrice' => 105,
                'attributes' => ['layout' => '75%', 'switch' => 'silent', 'connectivity' => 'tri-mode', 'hot_swap' => true],
                'images' => [$this->img('keyboard-custom-65.jpg')],
            ],
            [
                'title' => 'Anne Pro 2 60% Brown',
                'category' => 'keyboards',
                'brand' => 'Anne Pro',
                'basePrice' => 62,
                'attributes' => ['layout' => '60%', 'switch' => 'brown', 'connectivity' => 'wireless', 'hot_swap' => false],
                'images' => [$this->img('keyboard-rgb-blue.jpg')],
            ],
            [
                'title' => 'Logitech G Pro X Superlight',
                'category' => 'mice',
                'brand' => 'Logitech',
                'basePrice' => 110,
                'attributes' => ['connectivity' => 'wireless', 'shape' => 'ambi', 'weight_g' => 63, 'max_dpi' => 25600, 'polling_hz' => '1000', 'buttons' => 5],
                'images' => [$this->img('mouse-logitech-white.jpg')],
            ],
            [
                'title' => 'Razer Viper V2 Pro',
                'category' => 'mice',
                'brand' => 'Razer',
                'basePrice' => 125,
                'attributes' => ['connectivity' => 'wireless', 'shape' => 'ambi', 'weight_g' => 58, 'max_dpi' => 30000, 'polling_hz' => '1000', 'buttons' => 5],
                'images' => [$this->img('mouse-honeycomb.jpg')],
            ],
            [
                'title' => 'Logitech G502 Hero',
                'category' => 'mice',
                'brand' => 'Logitech',
                'basePrice' => 48,
                'attributes' => ['connectivity' => 'wired', 'shape' => 'ergo', 'weight_g' => 121, 'max_dpi' => 25600, 'polling_hz' => '1000', 'buttons' => 11],
                'images' => [$this->img('mouse-logitech-g.jpg')],
            ],
            [
                'title' => 'Pulsar X2 Mini Wireless',
                'category' => 'mice',
                'brand' => 'Pulsar',
                'basePrice' => 89,
                'attributes' => ['connectivity' => 'wireless', 'shape' => 'ambi', 'weight_g' => 52, 'max_dpi' => 26000, 'polling_hz' => '1000', 'buttons' => 5],
                'images' => [$this->img('mouse-honeycomb.jpg')],
            ],
            [
                'title' => 'Lamzu Atlantis Mini',
                'category' => 'mice',
                'brand' => 'Lamzu',
                'basePrice' => 99,
                'attributes' => ['connectivity' => 'wireless', 'shape' => 'ambi', 'weight_g' => 49, 'max_dpi' => 26000, 'polling_hz' => '1000', 'buttons' => 5],
                'images' => [$this->img('mouse-rgb.jpg')],
            ],
            [
                'title' => 'Razer DeathAdder V3',
                'category' => 'mice',
                'brand' => 'Razer',
                'basePrice' => 72,
                'attributes' => ['connectivity' => 'wired', 'shape' => 'ergo', 'weight_g' => 59, 'max_dpi' => 30000, 'polling_hz' => '8000', 'buttons' => 5],
                'images' => [$this->img('mouse-rgb.jpg')],
            ],
            [
                'title' => 'Logitech MX Master 3S',
                'category' => 'mice',
                'brand' => 'Logitech',
                'basePrice' => 85,
                'attributes' => ['connectivity' => 'wireless', 'shape' => 'ergo', 'weight_g' => 141, 'max_dpi' => 8000, 'polling_hz' => '125', 'buttons' => 7],
                'images' => [$this->img('mouse-logitech-g.jpg')],
            ],
            [
                'title' => 'Zowie EC2-CW',
                'category' => 'mice',
                'brand' => 'Zowie',
                'basePrice' => 118,
                'attributes' => ['connectivity' => 'wireless', 'shape' => 'ergo', 'weight_g' => 77, 'max_dpi' => 3200, 'polling_hz' => '1000', 'buttons' => 5],
                'images' => [$this->img('mouse-logitech-white.jpg')],
            ],
            [
                'title' => 'LG UltraGear 27" 165Hz IPS',
                'category' => 'monitors',
                'brand' => 'LG',
                'basePrice' => 220,
                'attributes' => ['size_inch' => '27', 'refresh_hz' => '165', 'panel' => 'ips', 'resolution' => 'qhd'],
                'images' => [$this->img('monitor-lg.jpg')],
            ],
            [
                'title' => 'Samsung Odyssey G5 27"',
                'category' => 'monitors',
                'brand' => 'Samsung',
                'basePrice' => 195,
                'attributes' => ['size_inch' => '27', 'refresh_hz' => '144', 'panel' => 'va', 'resolution' => 'qhd'],
                'images' => [$this->img('monitor-gaming.jpg')],
            ],
            [
                'title' => 'Dell S2721DGF 27" 165Hz',
                'category' => 'monitors',
                'brand' => 'Dell',
                'basePrice' => 240,
                'attributes' => ['size_inch' => '27', 'refresh_hz' => '165', 'panel' => 'ips', 'resolution' => 'qhd'],
                'images' => [$this->img('monitor-workstation.jpg')],
            ],
            [
                'title' => 'ASUS TUF VG249Q 24" 144Hz',
                'category' => 'monitors',
                'brand' => 'ASUS',
                'basePrice' => 145,
                'attributes' => ['size_inch' => '24', 'refresh_hz' => '144', 'panel' => 'ips', 'resolution' => 'fhd'],
                'images' => [$this->img('monitor-dual.jpg')],
            ],
            [
                'title' => 'Gigabyte M27Q 27" 170Hz',
                'category' => 'monitors',
                'brand' => 'Gigabyte',
                'basePrice' => 230,
                'attributes' => ['size_inch' => '27', 'refresh_hz' => '165', 'panel' => 'ips', 'resolution' => 'qhd'],
                'images' => [$this->img('monitor-gaming.jpg')],
            ],
            [
                'title' => 'BenQ ZOWIE XL2546K 240Hz',
                'category' => 'monitors',
                'brand' => 'BenQ',
                'basePrice' => 310,
                'attributes' => ['size_inch' => '24', 'refresh_hz' => '240', 'panel' => 'other', 'resolution' => 'fhd'],
                'images' => [$this->img('monitor-dual.jpg')],
            ],
            [
                'title' => 'LG 32UN880 32" 4K Ergo',
                'category' => 'monitors',
                'brand' => 'LG',
                'basePrice' => 380,
                'attributes' => ['size_inch' => '32', 'refresh_hz' => '60', 'panel' => 'ips', 'resolution' => '4k'],
                'images' => [$this->img('monitor-lg.jpg')],
            ],
            [
                'title' => 'Samsung Odyssey OLED G8',
                'category' => 'monitors',
                'brand' => 'Samsung',
                'basePrice' => 520,
                'attributes' => ['size_inch' => '32', 'refresh_hz' => '240', 'panel' => 'oled', 'resolution' => 'qhd'],
                'images' => [$this->img('monitor-workstation.jpg')],
            ],
        ];
    }
}
