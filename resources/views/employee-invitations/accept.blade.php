
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activation du compte - ZEMBOA</title>
</head>
<body>
    <h1>Bienvenue sur ZEMBOA</h1>

    <p>Définissez votre mot de passe pour activer votre compte.</p>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST"
          action="{{ route('employee.invitation.accept', ['token' => $token]) }}">
        @csrf

        <input type="hidden" name="email" value="{{ $email }}">

        <label for="password">Mot de passe</label>
        <input
            id="password"
            type="password"
            name="password"
            minlength="12"
            autocomplete="new-password"
            required
        >

        <label for="password_confirmation">
            Confirmer le mot de passe
        </label>
        <input
            id="password_confirmation"
            type="password"
            name="password_confirmation"
            minlength="12"
            autocomplete="new-password"
            required
        >

        <button type="submit">Activer mon compte</button>
    </form>
</body>
</html>
