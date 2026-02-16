<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン - 営業支援システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Noto Sans JP', sans-serif;
            background: linear-gradient(135deg, #0F1C2E 0%, #1A2C42 50%, #0F1C2E 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at center, rgba(255,140,0,0.06) 0%, transparent 60%);
            pointer-events: none;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background-color: #FFFFFF;
            border-radius: 1rem;
            box-shadow: 0 25px 50px rgba(0,0,0,0.4);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(160deg, #0F1C2E 0%, #1A2C42 100%);
            padding: 2rem;
            text-align: center;
            position: relative;
        }
        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, transparent, #FF8C00, transparent);
        }
        /* .login-logo {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #FF8C00, #E67E00);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: #FFFFFF;
            box-shadow: 0 4px 15px rgba(255,140,0,0.4);
        } */

        /* アニメーション */
        .login-logo {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #FF8C00, #E67E00);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: #FFFFFF;
            box-shadow: 0 4px 15px rgba(255, 140, 0, 0.4);
            animation: logoPulse 2s ease-in-out infinite;
        }

        @keyframes logoPulse {
            0%   { transform: scale(1);    box-shadow: 0 4px 15px rgba(255,140,0,0.4); }
            50%  { transform: scale(1.08); box-shadow: 0 8px 25px rgba(255,140,0,0.7); }
            100% { transform: scale(1);    box-shadow: 0 4px 15px rgba(255,140,0,0.4); }
        }

        .login-header h4 {
            color: #FFFFFF;
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
            letter-spacing: 0.05em;
        }
        .login-header p {
            color: rgba(255,140,0,0.7);
            font-size: 0.7rem;
            margin: 0.25rem 0 0;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
        .login-body { padding: 2rem; }
        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748B;
            margin-bottom: 0.35rem;
        }
        .form-control {
            border: 1px solid #E2E8F0;
            border-radius: 0.5rem;
            padding: 0.6rem 0.875rem;
            font-size: 0.9rem;
            transition: all 0.15s ease;
        }
        .form-control:focus {
            border-color: #FF8C00;
            box-shadow: 0 0 0 3px rgba(255,140,0,0.15);
        }
        .input-group-text {
            background-color: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-right: none;
            color: #94A3B8;
            border-radius: 0.5rem 0 0 0.5rem;
        }
        .input-group .form-control {
            border-left: none;
            border-radius: 0 0.5rem 0.5rem 0;
        }
        .input-group:focus-within {
            box-shadow: 0 0 0 3px rgba(255,140,0,0.15);
            border-radius: 0.5rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #FF8C00, #E67E00);
            border: none;
            color: #1E293B;
            font-weight: 700;
            padding: 0.7rem;
            border-radius: 0.5rem;
            width: 100%;
            font-size: 0.95rem;
            letter-spacing: 0.05em;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(255,140,0,0.3);
            font-family: 'Noto Sans JP', sans-serif;
            cursor: pointer;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(255,140,0,0.4);
        }
        .alert-danger {
            background-color: #FEF2F2;
            border: none;
            border-radius: 0.5rem;
            color: #991B1B;
            font-size: 0.875rem;
            padding: 0.75rem 1rem;
        }
        .form-check-input:checked {
            background-color: #FF8C00;
            border-color: #FF8C00;
        }
        .form-check-label {
            font-size: 0.85rem;
            color: #64748B;
        }
        .login-footer {
            text-align: center;
            padding: 0 2rem 1.5rem;
            font-size: 0.75rem;
            color: #94A3B8;
        }

        /* 回転アニメーション */
        .login-logo i {
            animation: iconSpin 6s linear infinite;
            display: inline-block;
        }

        @keyframes iconSpin {
            0%   { transform: rotate(0deg);   }
            15%  { transform: rotate(20deg);  }
            30%  { transform: rotate(0deg);   }
            45%  { transform: rotate(-20deg); }
            60%  { transform: rotate(0deg);   }
            100% { transform: rotate(0deg);   }
        }

    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
            <h4>営業支援システム</h4>
            <p>Sales Support System</p>
        </div>
        <div class="login-body">
            @if($errors->any())
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    メールアドレスまたはパスワードが正しくありません。
                </div>
            @endif

            @if(session('status'))
                <div class="alert alert-success mb-3">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">メールアドレス</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-envelope"></i>
                        </span>
                        <input type="email" name="email"
                               class="form-control"
                               value="{{ old('email') }}"
                               placeholder="email@example.com"
                               required autofocus>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">パスワード</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input type="password" name="password"
                               class="form-control"
                               placeholder="••••••••"
                               required>
                    </div>
                </div>
                <div class="mb-4">
                    <div class="form-check">
                        <input type="checkbox" name="remember"
                               class="form-check-input" id="remember">
                        <label class="form-check-label" for="remember">
                            ログイン状態を保持する
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i>ログイン
                </button>
            </form>
        </div>
        <div class="login-footer">
            &copy; {{ date('Y') }} Aizensolution Co.,Ltd.
        </div>
    </div>
</body>
</html>