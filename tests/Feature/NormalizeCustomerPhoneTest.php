<?php

namespace Tests\Feature;

use App\Actions\Customers\NormalizeCustomerPhone;
use InvalidArgumentException;
use Tests\TestCase;

class NormalizeCustomerPhoneTest extends TestCase
{
    public function test_it_normalizes_cameroonian_phone_numbers(): void
    {
        $normalizer = new NormalizeCustomerPhone();

        $this->assertSame(
            '+237690000001',
            $normalizer->execute('690000001')
        );

        $this->assertSame(
            '+237690000001',
            $normalizer->execute('237690000001')
        );

        $this->assertSame(
            '+237690000001',
            $normalizer->execute('+237690000001')
        );

        $this->assertSame(
            '+237690000001',
            $normalizer->execute('690 000 001')
        );

        $this->assertSame(
            '+237690000001',
            $normalizer->execute('690-000-001')
        );
    }

    public function test_it_rejects_invalid_cameroonian_phone_number(): void
    {
        $normalizer = new NormalizeCustomerPhone();

        $this->expectException(InvalidArgumentException::class);

        $normalizer->execute('12345');
    }
}