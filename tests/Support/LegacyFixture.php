<?php

namespace Tests\Support;

use App\Support\DatabaseSafety;
use PDO;

/**
 * A tiny legacy BWMS database for bwms:migrate-legacy tests, built through a
 * separate PDO handle so its DDL never commits the test's transaction. Columns
 * follow the production dump (audit 9.1), trimmed to what the importer reads.
 */
trait LegacyFixture
{
    protected string $legacyDb = 'bwms_legacy_test';

    /** The fixture database is dropped and recreated: it must be a *_test name (DatabaseSafety). */
    private function assertFixtureDbIsDisposable(): void
    {
        if (! str_ends_with($this->legacyDb, DatabaseSafety::SUFFIX)) {
            throw new \RuntimeException("Refusing to drop {$this->legacyDb}: fixture databases must end in _test.");
        }
    }

    protected function legacyPdo(?string $db = null): PDO
    {
        $c = config('database.connections.'.config('database.default'));
        $dsn = "mysql:host={$c['host']};port={$c['port']}".($db ? ";dbname={$db}" : '').';charset=utf8mb4';

        return new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** Recreates the fixture DB and points the `legacy` connection at it. */
    protected function buildLegacyFixture(?callable $tweak = null): void
    {
        $this->assertFixtureDbIsDisposable();
        $this->legacyPdo()->exec("DROP DATABASE IF EXISTS {$this->legacyDb}; CREATE DATABASE {$this->legacyDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = $this->legacyPdo($this->legacyDb);

        $ts = 'created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL';
        $pdo->exec("CREATE TABLE product_categories (id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(255), slug VARCHAR(255) NULL, parent_id BIGINT UNSIGNED NULL, is_active TINYINT(1), deleted_at TIMESTAMP NULL, {$ts})");
        $pdo->exec("CREATE TABLE products (id BIGINT UNSIGNED PRIMARY KEY, ordermentum_variant_id CHAR(36) NULL, ordermentum_product_id CHAR(36) NULL, sku VARCHAR(255), ordermentum_sku VARCHAR(255) NULL,
            barcode VARCHAR(255) NULL, name VARCHAR(255), description TEXT NULL, category_id BIGINT UNSIGNED, cost DECIMAL(10,2), price DECIMAL(10,2), unit_of_measure VARCHAR(50),
            reorder_point INT NULL, reorder_quantity INT NULL, is_active TINYINT(1), deleted_at TIMESTAMP NULL, {$ts})");
        foreach (['suppliers' => 'supplier_code VARCHAR(255), company_name VARCHAR(255), contact_name VARCHAR(255)',
            'customers' => 'customer_code VARCHAR(255), company_name VARCHAR(255), name VARCHAR(255)'] as $table => $cols) {
            $pdo->exec("CREATE TABLE {$table} (id BIGINT UNSIGNED PRIMARY KEY, {$cols}, email VARCHAR(255), phone VARCHAR(50) NULL, mobile VARCHAR(50) NULL, address TEXT, city VARCHAR(100),
                state VARCHAR(100), postal_code VARCHAR(20), country VARCHAR(100), tax_id VARCHAR(50) NULL, payment_terms VARCHAR(100) NULL, credit_limit DECIMAL(15,2) NULL,
                notes TEXT NULL, is_active TINYINT(1), deleted_at TIMESTAMP NULL, {$ts})");
        }
        $pdo->exec("CREATE TABLE warehouses (id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(255), deleted_at TIMESTAMP NULL, {$ts})");
        $pdo->exec("CREATE TABLE purchase_orders (id BIGINT UNSIGNED PRIMARY KEY, order_number VARCHAR(255), supplier_id BIGINT UNSIGNED, warehouse_id BIGINT UNSIGNED, order_date DATE,
            expected_date DATE NULL, received_date DATE NULL, status ENUM('draft','pending','received','cancelled'), subtotal DECIMAL(15,2), tax_rate DECIMAL(5,2), tax DECIMAL(15,2),
            shipping DECIMAL(15,2), discount DECIMAL(15,2), total DECIMAL(15,2), notes TEXT NULL, deleted_at TIMESTAMP NULL, {$ts})");
        $pdo->exec("CREATE TABLE purchase_order_items (id BIGINT UNSIGNED PRIMARY KEY, purchase_order_id BIGINT UNSIGNED, product_id BIGINT UNSIGNED, quantity INT, received_quantity INT,
            unit_price DECIMAL(15,2), discount DECIMAL(15,2), subtotal DECIMAL(15,2), {$ts})");
        $pdo->exec("CREATE TABLE inventory (id BIGINT UNSIGNED PRIMARY KEY, product_id BIGINT UNSIGNED, warehouse_id BIGINT UNSIGNED, quantity_on_hand INT, bin_location VARCHAR(255) NULL, {$ts})");
        $pdo->exec("CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(255), email VARCHAR(255), role TEXT NULL, is_active TINYINT(1), password VARCHAR(255), {$ts})");

        $now = "'2026-06-01 10:00:00'";
        $pdo->exec("INSERT INTO product_categories VALUES (1,'Syrups','syrups',NULL,1,NULL,{$now},{$now}), (2,'Mixers',NULL,NULL,1,NULL,{$now},{$now})");
        $pdo->exec("INSERT INTO products VALUES
            (1,'aaaaaaaa-0000-0000-0000-000000000001','pppppppp-0000-0000-0000-000000000001','DASH-VAN','DASH-VAN-OM',NULL,'Dash Vanilla 750ml','Vanilla syrup',1,4.50,9.90,'unit',10,24,1,NULL,{$now},{$now}),
            (2,'aaaaaaaa-0000-0000-0000-000000000002','pppppppp-0000-0000-0000-000000000002','dash-car ','DASH-CAR',' 9300000000019','Dash Caramel 750ml',NULL,1,0.00,9.90,'EA',NULL,NULL,1,NULL,{$now},{$now}),
            (3,NULL,NULL,'TONIC-1','',NULL,'Tonic 200ml',NULL,2,1.10,0,'',NULL,NULL,1,NULL,{$now},{$now}),
            (4,'aaaaaaaa-0000-0000-0000-000000000004',NULL,'OLD-1',NULL,NULL,'Discontinued',NULL,2,2.00,3.00,'unit',NULL,NULL,0,'2026-05-01 00:00:00',{$now},{$now})");
        $pdo->exec("INSERT INTO inventory VALUES (1,1,1,25,'A-1-1',{$now},{$now}), (2,2,1,-809,'A-1-2',{$now},{$now}), (3,3,1,0,NULL,{$now},{$now})");
        $pdo->exec("INSERT INTO suppliers VALUES
            (1,'SUP1','Syrup Co','Sam','orders@syrup.test','07 1111 1111','0400 000 000','1 Lane','Brisbane','QLD','4000','Australia','12 345','30 days',5000,NULL,1,NULL,{$now},{$now}),
            (2,'SUP2','Mixer Co','Max','mix@mixer.test',NULL,NULL,'2 Road','Clontarf','QLD','4019','Australia',NULL,NULL,NULL,'Slow',1,NULL,{$now},{$now})");
        $pdo->exec("INSERT INTO customers VALUES (1,'CUST1','Cafe One','Cathy','cathy@cafe.test','07 2222 2222',NULL,'3 St','Redcliffe','QLD','4020','Australia',NULL,'7 days',NULL,NULL,1,NULL,{$now},{$now})");
        $pdo->exec("INSERT INTO warehouses VALUES (1,'Main',NULL,{$now},{$now}), (2,'Old','2026-01-01 00:00:00',{$now},{$now})");
        $pdo->exec("INSERT INTO purchase_orders VALUES
            (1,'PO-20260101-0001',1,1,'2026-01-01','2026-01-08',NULL,'received',90.00,10,9.00,0,0,99.00,NULL,NULL,{$now},'2026-01-09 09:00:00'),
            (2,'PO-20260201-0002',1,1,'2026-02-01','2026-02-08',NULL,'pending',45.00,10,4.50,5.00,0,60.00,'Rush',NULL,{$now},{$now}),
            (3,'PO-20260301-0003',2,2,'2026-03-01',NULL,NULL,'cancelled',11.00,0,0,0,0,11.00,NULL,NULL,{$now},{$now})");
        $pdo->exec("INSERT INTO purchase_order_items VALUES
            (1,1,1,10,0,4.50,0,45.00,{$now},{$now}), (2,1,2,5,0,9.00,0,45.00,{$now},{$now}),
            (3,2,1,10,0,4.50,0,45.00,{$now},{$now}),
            (4,3,3,10,0,1.10,0,11.00,{$now},{$now})");
        $pdo->exec("INSERT INTO users VALUES (1,'Old Admin','admin@legacy.test','admin',1,'x',{$now},{$now})");

        if ($tweak) {
            $tweak($pdo);
        }

        $c = config('database.connections.'.config('database.default'));
        config(['database.connections.legacy' => array_merge(config('database.connections.legacy'), [
            'host' => $c['host'], 'port' => $c['port'], 'database' => $this->legacyDb, 'username' => $c['username'], 'password' => $c['password'],
        ])]);
        app('db')->purge('legacy');
    }

    protected function dropLegacyFixture(): void
    {
        $this->assertFixtureDbIsDisposable();
        app('db')->purge('legacy');
        $this->legacyPdo()->exec("DROP DATABASE IF EXISTS {$this->legacyDb}");
    }
}
