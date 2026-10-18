
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">
    <title>Administration - ZEMBOA</title>
</head>
<body>
    <h1>ZEMBOA ADMIN</h1>
    <p>Connexion à l'administration générale</p>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST"
          action="{{ route('admin.login.store') }}">
        @csrf

        <label for="email">Adresse e-mail</label>
        <input
            id="email"
            name="email"
            type="email"
            required
        >

        <label for="password">Mot de passe</label>
        <input
            id="password"
            name="password"
            type="password"
            required
        >

        <button type="submit">
            Se connecter
        </button>
    </form>
</body>
</html>
