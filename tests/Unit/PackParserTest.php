<?php

namespace Tests\Unit;

use App\Services\Catalogue\Import\PackParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PackParserTest extends TestCase
{
    /** Names as they are on the live Ordermentum catalogue, plus the brief's example. */
    public static function packs(): array
    {
        return [
            'brief example' => ['Carton 12 x 1L', 'Carton', 12, '1L bottle'],
            'size first' => ['Strawberry Matcha Giddy Fizz Soda 330ml x 12', 'Case', 12, '330ml bottle'],
            'quantity first, no spaces' => ['Simple Green Smoothie 12x325mL', 'Case', 12, '325ml bottle'],
            'in brackets' => ['Milk Lab Coconut Milk (8x1lt)', 'Case', 8, '1L bottle'],
            'capital X' => ['Coco Coast Lychee Coconut Water (500ml X 24) CocoCoast', 'Case', 24, '500ml bottle'],
            'decimal size' => ['Aussie Pure Water 1.5lt x6', 'Case', 6, '1.5L bottle'],
            'carton named as CTN' => ['Salam Chai Vanilla 1kg x12 (CTN)', 'Carton', 12, '1kg pack'],
            'grams' => ['KOJA Plant Protein Bar Peanut Fudge 45g x16', 'Case', 16, '45g bar'],
            '"free" in a name is not a deal' => ['Milk Lab Lactose Free (12x1lt)', 'Case', 12, '1L bottle'],
            'a named can' => ['Soda 330ml x 12 can', 'Case', 12, '330ml can'],
            'one of' => ['Filter 1 x 1L', 'Each', 1, '1L bottle'],
            'pack word and count' => ['Carton of 24', 'Carton', 24, 'each'],
        ];
    }

    #[DataProvider('packs')]
    public function test_explicit_packs_are_read(string $text, string $name, int $size, string $baseUnit)
    {
        $pack = (new PackParser)->parse($text);

        $this->assertTrue($pack['sure'], (string) $pack['why']);
        $this->assertSame([$name, $size, $baseUnit], [$pack['pack_name'], $pack['pack_size'], $pack['base_unit']]);
    }

    public static function unsure(): array
    {
        return [
            'nothing to go on' => ['MISCOMI11468'],
            'pack of a retail count' => ['Tea Bags Green Pk100 YV'],
            'deal' => ['Good Water 9+1 bonus'],
            'promotion' => ['Good Water PROMO (T&C apply)'],
            'bundle spaced' => ['Ok Coco Intro Offer - 3 + 1'],
            'quantity without a size' => ['Tea x 6'],
            'two quantities' => ['Water 2 x 12 x 330ml'],
            'two quantities, size first' => ['Water 330ml x 12 x 2'],
            'two sizes' => ['Syrup 750ml 1L'],
            'silly quantity' => ['Water 9999 x 1L'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unsure')]
    public function test_anything_else_is_unsure_with_a_reason_and_no_value(string $text)
    {
        $pack = (new PackParser)->parse($text);

        $this->assertFalse($pack['sure']);
        $this->assertNull($pack['pack_size']);
        $this->assertNull($pack['pack_name']);
        $this->assertNotEmpty($pack['why']);
    }

    public function test_a_lone_size_is_only_a_suggestion()
    {
        $pack = (new PackParser)->parse('Ground Control VNA Syrup 750ml');

        $this->assertFalse($pack['sure']);
        $this->assertNull($pack['pack_size'], 'Never applied without a person');
        $this->assertSame(['pack_name' => 'Each', 'pack_size' => 1, 'base_unit' => '750ml bottle'], $pack['suggested']);
    }
}
