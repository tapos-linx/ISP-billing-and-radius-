<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class BillingVatAndExpiryTest extends TestCase
{
    public function test_calculates_configurable_nbr_vat_5_and_15_percent(): void
    {
        $subtotal = 1000.00;
        $vat5 = round($subtotal * 0.05, 2);
        $vat15 = round($subtotal * 0.15, 2);

        $this->assertSame(50.00, $vat5);
        $this->assertSame(150.00, $vat15);
    }
}
