<?php

session_start();

// Redirect logic
if (isset($_SESSION['role'])) {
    // RBAC: both roles share the same dashboard.php now — the page
    // itself adapts based on permissions, so there's no separate
    // staff/dashboard.php to redirect to anymore.
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff') {
        header('Location: /CAPS/admin/dashboard/frontend/dashboard.php');
        exit;
    }
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if (isset($_GET['error'])) {
    $errors = [
        'invalid'      => 'Invalid credentials. Please try again.',
        'locked'       => 'Too many failed attempts. Account locked for 15 minutes.',
        'csrf'         => 'Invalid request. Please try again.',
        'unauthorized' => 'You are not authorized to access that page.',
        'session'      => 'Your session has expired. Please log in again.',
        'blocked'      => 'This staff account is blocked. Please contact the barangay administrator.',
        'ended'        => 'This staff assignment has ended. Please contact the barangay administrator.',
    ];

    $error = $errors[$_GET['error']] ?? 'An error occurred. Please try again.';
}

$success = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Barangay Binanang 2nd</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            overflow: hidden;
            background: linear-gradient(rgba(239,244,250,0.92), rgba(239,244,250,0.92)), url('../binang%202nd.jpg');
            background-size: cover;
            background-position: center;
        }

        .wave-wrapper {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 230px;
            overflow: hidden;
            z-index: 1;
        }

        .wave1, .wave2, .wave3 {
            position: absolute;
            width: 120%;
            left: -10%;
            border-radius: 50%;
            transform-origin: center;
        }

        /* Animated Wave Movement */

        @keyframes waveMove1 {
            0% {
                transform: translateX(0) translateY(0);
            }
            50% {
                transform: translateX(-35px) translateY(10px);
            }
            100% {
                transform: translateX(0) translateY(0);
            }
        }

        @keyframes waveMove2 {
            0% {
                transform: translateX(0) translateY(0);
            }
            50% {
                transform: translateX(40px) translateY(-8px);
            }
            100% {
                transform: translateX(0) translateY(0);
            }
        }

        @keyframes waveMove3 {
            0% {
                transform: translateX(0) translateY(0);
            }
            50% {
                transform: translateX(-25px) translateY(6px);
            }
            100% {
                transform: translateX(0) translateY(0);
            }
        }

        .wave1 {
            height: 220px;
            background: #1d63da;
            bottom: -55px;
            opacity: 0.8;
            animation: waveMove1 7s ease-in-out infinite;
        }

        .wave2 {
            height: 190px;
            background: #1451be;
            bottom: -80px;
            opacity: 0.9;
            animation: waveMove2 9s ease-in-out infinite;
        }

        .wave3 {
            height: 140px;
            background: #0c3e90;
            bottom: -95px;
            animation: waveMove3 11s ease-in-out infinite;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 28px !important;
            box-shadow: 0 20px 50px rgba(0,0,0,0.10);
            z-index: 10;
            position: relative;
        }

        .input-field {
            transition: all 0.2s ease;
            border-radius: 14px !important;
        }

        .input-field:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(29, 99, 218, 0.1);
            border-color: #1d63da !important;
        }

        .btn-primary {
            background: #1d63da;
            transition: 0.3s ease;
            border-radius: 14px !important;
        }

        .btn-primary:hover {
            background: #104db8;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(29, 99, 218, 0.2);
        }

        .divider-blue {
            width: 85px;
            height: 4px;
            background: #1d63da;
            margin: 20px auto;
            border-radius: 50px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease both;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10 antialiased relative">

    <div class="wave-wrapper">
        <div class="wave1"></div>
        <div class="wave2"></div>
        <div class="wave3"></div>
    </div>

    <div class="w-full max-w-lg relative z-10 animate-fade-in-up">

        <div class="glass-card overflow-hidden">

            <div class="px-8 pt-10 text-center">
                <div class="inline-block mb-4">
                    <img src="../barangaylogo.png" alt="Barangay Logo" class="w-32 h-auto mx-auto">
                </div>

                <h1 class="text-2xl font-bold text-[#102d76] tracking-tight uppercase">Barangay Biñang 2nd</h1>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">Bocaue, Bulacan</p>

                <div class="divider-blue"></div>

                <h2 class="text-[#16408d] font-bold text-xl tracking-tight uppercase">Admin & Staff Login</h2>
                <p class="text-slate-400 text-sm mt-1">Please login to continue</p>
            </div>

            <?php if (isset($error) && $error): ?>
            <div class="mx-8 mt-5 flex items-start gap-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-3 text-sm font-medium">
                <span class="material-symbols-outlined text-rose-500 text-xl mt-0.5">error</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <?php if (isset($success) && $success): ?>
            <div class="mx-8 mt-5 flex items-start gap-3 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl px-4 py-3 text-sm font-medium">
                <span class="material-symbols-outlined text-emerald-500 text-xl mt-0.5">check_circle</span>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <div class="px-8 py-7">
                <form method="POST" action="auth_login.php" id="loginForm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                    <div class="mb-5">
                        <label for="identifier" class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">
                            Username / Email
                        </label>

                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-[20px] pointer-events-none">person</span>

                            <input
                                type="text"
                                id="identifier"
                                name="identifier"
                                class="input-field w-full pl-12 pr-4 py-4 bg-white border border-slate-200 text-slate-800 text-sm placeholder-slate-400"
                                placeholder="Enter your username"
                                value="<?php echo isset($_GET['pre']) ? htmlspecialchars($_GET['pre']) : ''; ?>"
                                required
                            >
                        </div>

                        <p id="identifierError" class="hidden text-xs text-rose-500 mt-1.5 font-medium"></p>
                    </div>

                    <div class="mb-5">
                        <label for="password" class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">
                            Password
                        </label>

                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-[20px] pointer-events-none">lock</span>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="input-field w-full pl-12 pr-12 py-4 bg-white border border-slate-200 text-slate-800 text-sm placeholder-slate-400"
                                placeholder="Enter your password"
                                required
                            >

                            <button type="button" id="togglePassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-600 transition-colors"
                                tabindex="-1">
                                <span class="material-symbols-outlined text-[20px]" id="eyeIcon">visibility_off</span>
                            </button>
                        </div>

                        <p id="passwordError" class="hidden text-xs text-rose-500 mt-1.5 font-medium"></p>
                    </div>

                    <div class="flex items-center justify-between mb-6 px-1">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="remember_me" id="rememberMe"
                                class="w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500">
                            <span class="text-sm text-slate-600 font-medium">Remember me for 7 days</span>
                        </label>
                    </div>

                    <button type="submit" id="loginBtn"
                        class="btn-primary w-full text-white font-bold py-4 text-sm tracking-wide flex items-center justify-center gap-2 uppercase">
                        <span id="btnText">Sign In</span>
                        <span id="btnIcon" class="material-symbols-outlined text-[18px]">lock</span>

                        <svg id="btnSpinner" class="hidden w-5 h-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </button>
                </form>
            </div>

            <div class="px-8 pb-8">
                <div class="flex items-center justify-center gap-3 text-sm text-slate-400 border-t border-slate-100 pt-6">
                    <span class="material-symbols-outlined text-[22px] text-blue-600">shield_with_heart</span>
                    <span class="font-medium">Secure Login System</span>
                </div>
            </div>
        </div>

<p class="text-center text-xs text-white mt-8 font-medium">
    ©<?php echo date('Y'); ?> Barangay Biñang 2nd &middot; Bocaue, Bulacan
</p>
    </div>

    <script>
        const toggleBtn = document.getElementById('togglePassword');
        const passwordField = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        toggleBtn.addEventListener('click', () => {
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeIcon.textContent = 'visibility';
            } else {
                passwordField.type = 'password';
                eyeIcon.textContent = 'visibility_off';
            }
        });

        const form = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');
        const btnSpinner = document.getElementById('btnSpinner');

        form.addEventListener('submit', function(e) {
            let valid = true;

            const identifier = document.getElementById('identifier').value.trim();
            const password = document.getElementById('password').value;

            const identifierError = document.getElementById('identifierError');
            const passwordError = document.getElementById('passwordError');

            identifierError.classList.add('hidden');
            passwordError.classList.add('hidden');

            if (!identifier) {
                identifierError.textContent = 'Please enter your username or email address.';
                identifierError.classList.remove('hidden');
                valid = false;
            }

            if (!password) {
                passwordError.textContent = 'Please enter your password.';
                passwordError.classList.remove('hidden');
                valid = false;
            }

            if (valid) {
                loginBtn.disabled = true;
                btnText.textContent = 'Signing In...';
                btnIcon.classList.add('hidden');
                btnSpinner.classList.remove('hidden');
            } else {
                e.preventDefault();
            }
        });
    </script>

</body>
</html>