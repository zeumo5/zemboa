<?php
namespace Tests\Feature;


use Illuminate\Support\Facades\App;
use Tests\TestCase;

class UiLocalizationTest extends TestCase
{
    public function test_french_translations_work(): void
    {
        App::setLocale('fr');

        $this->assertSame(
            'Tableau de bord',
            __('ui.dashboard')
        );

        $this->assertSame(
            'Commandes',
            __('ui.orders')
        );

        $this->assertSame(
            'Point de vente',
            __('ui.point_of_sale')
        );

        $this->assertSame(
            'Enregistrer',
            __('ui.save')
        );
    }

    public function test_english_translations_work(): void
    {
        App::setLocale('en');

        $this->assertSame(
            'Dashboard',
            __('ui.dashboard')
        );

        $this->assertSame(
            'Orders',
            __('ui.orders')
        );

        $this->assertSame(
            'Point of Sale',
            __('ui.point_of_sale')
        );

        $this->assertSame(
            'Save',
            __('ui.save')
        );
    }

    public function test_switching_language_updates_translations(): void
    {
        App::setLocale('fr');

        $this->assertSame(
            'Factures',
            __('ui.invoices')
        );

        App::setLocale('en');

        $this->assertSame(
            'Invoices',
            __('ui.invoices')
        );

        App::setLocale('fr');

        $this->assertSame(
            'Factures',
            __('ui.invoices')
        );
    }

    public function test_both_languages_have_the_same_translation_keys(): void
    {
        $french = require lang_path('fr/ui.php');
        $english = require lang_path('en/ui.php');

        $this->assertIsArray($french);
        $this->assertIsArray($english);

        $frenchKeys = array_keys($french);
        $englishKeys = array_keys($english);

        sort($frenchKeys);
        sort($englishKeys);

        $this->assertSame(
            $frenchKeys,
            $englishKeys
        );

        foreach ([$french, $english] as $translations) {
            foreach ($translations as $translation) {
                $this->assertIsString($translation);
                $this->assertNotSame('', trim($translation));
            }
        }
    }
}
