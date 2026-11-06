<?php

namespace App\Support;

use App\Authorization\Permissions;
use Illuminate\Http\Request;

/** The handheld screens' top bar: title, Back, and the menu the person's permissions allow. */
final class WarehouseNav
{
    /** @return array{title: string, back: string, links: list<array{title: string, href: string}>} */
    public static function for(Request $request, string $title, string $back): array
    {
        $links = [
            ['title' => 'Dashboard', 'href' => route('dashboard'), 'permission' => null],
            ['title' => 'Pick queue', 'href' => route('pick.queue'), 'permission' => Permissions::PICKING_PERFORM],
            ['title' => 'Pick lists (desktop)', 'href' => route('pick-lists.index'), 'permission' => Permissions::PICKING_PERFORM],
            ['title' => 'Orders', 'href' => route('orders.index'), 'permission' => Permissions::ORDERS_VIEW],
            ['title' => 'Capture barcodes', 'href' => route('barcode-capture.index'), 'permission' => Permissions::PRODUCTS_MANAGE],
        ];

        return [
            'title' => $title,
            'back' => $back,
            'links' => collect($links)
                ->filter(fn (array $link) => $link['permission'] === null || $request->user()->can($link['permission']))
                ->map(fn (array $link) => ['title' => $link['title'], 'href' => $link['href']])
                ->values()->all(),
        ];
    }
}
