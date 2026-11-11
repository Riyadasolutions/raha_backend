<?php

namespace App\Support;

/** Integer halalas (SAR × 100), same rules as front quotation_money.ts. */
class Money
{
    public static function lineAmounts(float $quantity, int $unitPrice, int $discount, float $taxRate): array
    {
        $gross = (int) round($quantity * $unitPrice);
        $discount = max(0, min($discount, $gross));
        $net = $gross - $discount;
        $tax = (int) round($net * $taxRate / 100);

        return ['gross' => $gross, 'discount' => $discount, 'net' => $net, 'tax' => $tax, 'total' => $net + $tax];
    }

    public static function sar(int $halalas): string
    {
        return number_format($halalas / 100, 2).' ر.س';
    }
}
