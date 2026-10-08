<?php

namespace App\Actions\Customers;

use InvalidArgumentException;

class NormalizeCustomerPhone
{
    public function execute(string $phone): string
    {
        // Supprime les espaces, tirets, parenthèses, etc.
        $phone = preg_replace('/[^\d+]/', '', trim($phone));

        // Exemple : 690000001
        if (preg_match('/^6\d{8}$/', $phone)) {
            return '+237'.$phone;
        }

        // Exemple : 237690000001
        if (preg_match('/^2376\d{8}$/', $phone)) {
            return '+'.$phone;
        }

        // Exemple : +237690000001
        if (preg_match('/^\+2376\d{8}$/', $phone)) {
            return $phone;
        }

        throw new InvalidArgumentException(
            'Le numéro de téléphone camerounais est invalide.'
        );
    }
}