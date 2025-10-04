<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Poppins', sans-serif;
        }

        .home-container {
            position: relative;
            width: 100%;
            height: 100vh; /* full screen */
            overflow: hidden;
        }

        .home-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover; /* make it cover screen */
            z-index: 0;
        }

        .overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5); /* dark overlay */
            z-index: 1;
        }

        .video-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 12px;
            padding: 2rem;
            color: #fff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
        }

        .login-card h2 {
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .login-card input[type="email"],
        .login-card input[type="password"],
        .login-card input[type="text"] {
            background-color: rgba(255,255,255,0.95);
            color: #000;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 10px;
            width: 100%;
            margin-top: 6px;
        }

        .login-card input::placeholder {
            color: #555;
        }

        .login-card label {
            font-size: 14px;
            color: #fff;
        }

        .login-card .remember-me {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
            color: #fff;
        }

        .login-card button {
            background: #2563eb;
            color: #fff;
            font-weight: 600;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 16px;
            width: 100%;
            transition: background 0.2s;
        }

        .login-card button:hover {
            background: #1e4ed8;
        }
    </style>
</head>
<body>
    <div class="home-container">
        <video autoplay muted loop playsinline>
            <source src="https://static.vecteezy.com/system/resources/previews/044/301/371/mp4/glowing-neon-mathematics-formulas-flying-chaotically-on-black-background-seamless-loop-animation-concept-of-exact-science-and-education-video.mp4" type="video/mp4">
        </video>

        <div class="overlay"></div>

        <div class="video-content">
            <div class="login-card">
                <h2>{{ __('Login') }}</h2>

                <!-- Session Status -->
                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <!-- Email -->
                    <div>
                        <x-input-label for="email" :value="__('Email')" />
                        <x-text-input id="email" type="email" name="email"
                                      :value="old('email')" required autofocus autocomplete="username" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div class="mt-4">
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input id="password" type="password" name="password"
                                      required autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <!-- Remember -->
                    <div class="remember-me">
                        <input id="remember_me" type="checkbox" name="remember">
                        <label for="remember_me">{{ __('Remember me') }}</label>
                    </div>

                    <div class="mt-4">
                        <button type="submit">{{ __('Log in') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
