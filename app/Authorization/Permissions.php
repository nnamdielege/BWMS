<?php

namespace App\Authorization;

/**
 * Single source of truth for permission names and the default role matrix.
 * The seeder syncs the database to this file; never create permissions ad hoc.
 */
final class Permissions
{
    public const USERS_VIEW = 'users.view';

    public const USERS_INVITE = 'users.invite';

    public const USERS_MANAGE = 'users.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_MANAGE = 'products.manage';

    public const LOCATIONS_VIEW = 'locations.view';

    public const LOCATIONS_MANAGE = 'locations.manage';

    public const STOCK_VIEW = 'stock.view';

    public const STOCK_ADJUST = 'stock.adjust';

    public const STOCK_TRANSFER = 'stock.transfer';

    public const STOCKTAKES_COUNT = 'stocktakes.count';

    public const STOCKTAKES_MANAGE = 'stocktakes.manage';

    public const SUPPLIERS_VIEW = 'suppliers.view';

    public const SUPPLIERS_MANAGE = 'suppliers.manage';

    public const PURCHASE_ORDERS_VIEW = 'purchase_orders.view';

    public const PURCHASE_ORDERS_MANAGE = 'purchase_orders.manage';

    public const RECEIVING_PERFORM = 'receiving.perform';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_MANAGE = 'orders.manage';

    public const PICKING_PERFORM = 'picking.perform';

    public const SYNC_VIEW = 'sync.view';

    public const SYNC_MANAGE = 'sync.manage';

    public const REPORTS_VIEW = 'reports.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_WAREHOUSE_MANAGER = 'warehouse_manager';

    public const ROLE_WAREHOUSE_STAFF = 'warehouse_staff';

    public const ROLE_PURCHASING = 'purchasing';

    public const ROLE_READ_ONLY = 'read_only';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_filter(
            (new \ReflectionClass(self::class))->getConstants(),
            fn ($value, $name) => ! str_starts_with($name, 'ROLE_'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** @return array<string, list<string>> role => permissions */
    public static function roleMatrix(): array
    {
        $view = [
            self::PRODUCTS_VIEW, self::LOCATIONS_VIEW, self::STOCK_VIEW, self::SUPPLIERS_VIEW,
            self::PURCHASE_ORDERS_VIEW, self::ORDERS_VIEW, self::SYNC_VIEW, self::REPORTS_VIEW,
        ];

        return [
            self::ROLE_ADMIN => self::all(),
            self::ROLE_WAREHOUSE_MANAGER => [
                ...$view,
                self::USERS_VIEW, self::AUDIT_VIEW,
                self::PRODUCTS_MANAGE, self::LOCATIONS_MANAGE,
                self::STOCK_ADJUST, self::STOCK_TRANSFER, self::STOCKTAKES_COUNT, self::STOCKTAKES_MANAGE,
                self::SUPPLIERS_MANAGE, self::PURCHASE_ORDERS_MANAGE, self::RECEIVING_PERFORM,
                self::ORDERS_MANAGE, self::PICKING_PERFORM, self::SYNC_MANAGE,
            ],
            self::ROLE_WAREHOUSE_STAFF => [
                self::PRODUCTS_VIEW, self::LOCATIONS_VIEW, self::STOCK_VIEW, self::PURCHASE_ORDERS_VIEW,
                self::ORDERS_VIEW, self::STOCK_TRANSFER, self::STOCKTAKES_COUNT, self::RECEIVING_PERFORM,
                self::PICKING_PERFORM,
            ],
            self::ROLE_PURCHASING => [
                ...$view,
                self::SUPPLIERS_MANAGE, self::PURCHASE_ORDERS_MANAGE,
            ],
            self::ROLE_READ_ONLY => $view,
        ];
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::roleMatrix());
    }
}
