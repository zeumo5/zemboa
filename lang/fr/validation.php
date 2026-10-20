
<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',

    'between' => [
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier :attribute doit faire entre :min et :max kilo-octets.',
    ],

    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'file' => 'Le fichier :attribute ne doit pas dépasser :max kilo-octets.',
    ],

    'min' => [
        'numeric' => 'Le champ :attribute doit être au moins égal à :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le fichier :attribute doit faire au moins :min kilo-octets.',
    ],

    'attributes' => [
        'locale' => 'langue',
        'preferred_locale' => 'langue préférée',
        'default_locale' => 'langue par défaut',
        'document_locale' => 'langue des documents',
        'invoice_print_format' => 'format de facture',
        'receipt_print_format' => 'format du reçu',
        'amount' => 'montant',
        'reference' => 'référence',
        'reason' => 'motif',
        'email' => 'adresse e-mail',
        'password' => 'mot de passe',
    ],
];
