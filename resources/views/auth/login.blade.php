
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion | ZEMBOA</title>
</head>
<body>
    <h1>Connexion à ZEMBOA</h1>
    <p>Accédez à votre espace professionnel.</p>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label for="email">Adresse e-mail</label>
        <input
            id="email"
            type="email"
            name="email"
            value="{{ old('email') }}"
            required
        >

        <label for="password">Mot de passe</label>
        <input
            id="password"
            type="password"
            name="password"
            required
        >

        <button type="submit">Se connecter</button>
    </form>
</body>
</html>
