
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">
    <title>Dashboard Admin - ZEMBOA</title>
</head>
<body>
    <h1>Administration ZEMBOA</h1>

    <p>Bienvenue, {{ $admin->name }}.</p>

    <p>
        Votre espace d'administration
        est maintenant accessible.
    </p>

    <form method="POST"
          action="{{ route('admin.logout') }}">
        @csrf

        <button type="submit">
            Se déconnecter
        </button>
    </form>
</body>
</html>
