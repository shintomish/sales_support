<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '営業支援システム')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS変数 ===== */
        :root {
            --primary:        #FF8C00;
            --primary-dark:   #E67E00;
            --primary-light:  #FFF3E0;
            --success:        #10B981;
            --warning:        #FF8C00;
            --danger:         #EF4444;
            --info:           #06B6D4;
            --sidebar-bg:     #0F1C2E;
            --sidebar-hover:  #1A2E45;
            --sidebar-text:   #64748B;
            --sidebar-active: #FF8C00;
            --body-bg:        #F1F5F9;
            --card-bg:        #FFFFFF;
            --text-primary:   #1E293B;
            --text-muted:     #64748B;
            --border:         #E2E8F0;
        }

        /* ===== 基本設定 ===== */
        * { box-sizing: border-box; }
        body {
            font-family: 'Noto Sans JP', sans-serif;
            background-color: var(--body-bg);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        /* ===== サイドバー ===== */
        .sidebar {
            width: 240px;
            min-width: 240px;
            min-height: 100vh;
            background: linear-gradient(
                160deg,
                #0F1C2E 0%,
                #162436 40%,
                #1A2C42 70%,
                #0F1C2E 100%
            );
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
        }

        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 200px;
            background: linear-gradient(
                180deg,
                rgba(255, 140, 0, 0.08) 0%,
                transparent 100%
            );
            pointer-events: none;
        }

        .sidebar-brand {
            padding: 1.5rem 1.25rem 1rem;
            border-bottom: 1px solid rgba(255, 140, 0, 0.2);
            position: relative;
        }

        .sidebar-brand::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 1.25rem;
            width: 40px;
            height: 2px;
            background: linear-gradient(90deg, #FF8C00, transparent);
        }

        .sidebar-brand h5 {
            color: #FFFFFF;
            font-weight: 700;
            font-size: 1rem;
            margin: 0;
            letter-spacing: 0.05em;
        }

        .sidebar-brand p {
            color: rgba(255, 140, 0, 0.6);
            font-size: 0.7rem;
            margin: 0.25rem 0 0;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .nav-section {
            padding: 1rem 0 0.5rem;
            position: relative;
        }

        .nav-section-title {
            color: rgba(255, 255, 255, 0.2);
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            padding: 0 1.25rem 0.5rem;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #7A90A8;
            text-decoration: none;
            padding: 0.65rem 1.25rem;
            transition: all 0.2s ease;
            font-size: 0.875rem;
            border-left: 3px solid transparent;
            position: relative;
        }

        .sidebar-link:hover {
            color: #FFFFFF;
            background: linear-gradient(
                90deg,
                rgba(255, 140, 0, 0.12) 0%,
                transparent 100%
            );
            border-left-color: var(--primary);
        }

        .sidebar-link.active {
            color: #FFFFFF;
            background: linear-gradient(
                90deg,
                rgba(255, 140, 0, 0.18) 0%,
                transparent 100%
            );
            border-left-color: var(--primary);
        }

        .sidebar-link.active::after {
            content: '';
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--primary);
            box-shadow: 0 0 8px rgba(255, 140, 0, 0.8);
        }

        .sidebar-link i {
            font-size: 1rem;
            width: 1.25rem;
            text-align: center;
        }

        /* ===== メインコンテンツ ===== */
        .main-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        /* ===== トップナビ ===== */
        .topnav {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border);
            border-left: 3px solid var(--primary);
            padding: 0 1.5rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .topnav-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .topnav-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .topnav-date {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        /* ===== コンテンツエリア ===== */
        .content-area {
            padding: 1.5rem;
            flex: 1;
        }

        /* ===== カード ===== */
        .card {
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            background-color: var(--card-bg);
        }

        .card-header {
            border-bottom: 1px solid var(--border);
            border-radius: 0.75rem 0.75rem 0 0 !important;
            padding: 1rem 1.25rem;
            background-color: #F8FAFC;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* ===== テーブル ===== */
        .table {
            font-size: 0.875rem;
        }

        .table thead th {
            background-color: #F8FAFC;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1rem;
        }

        .table tbody td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text-primary);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:hover {
            background-color: #FFF8F0;
        }

        /* ===== ボタン ===== */
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            color: #1E293B;
            font-weight: 600;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #1E293B;
        }

        .btn {
            border-radius: 0.5rem;
            font-size: 0.875rem;
        }

        .btn-sm {
            padding: 0.25rem 0.6rem;
            font-size: 0.8rem;
        }

        .btn-outline-secondary {
            color: var(--text-muted);
            border-color: var(--border);
        }

        .btn-outline-secondary:hover {
            background-color: #F1F5F9;
            color: var(--text-primary);
            border-color: var(--border);
        }

        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary);
            color: #1E293B;
        }

        .btn-outline-warning {
            color: var(--warning);
            border-color: var(--warning);
        }

        .btn-outline-warning:hover {
            background-color: var(--warning);
            color: #1E293B;
        }

        .btn-outline-danger {
            color: var(--danger);
            border-color: var(--danger);
        }

        .btn-outline-danger:hover {
            background-color: var(--danger);
            color: #FFFFFF;
        }

        /* ===== バッジ ===== */
        .badge {
            font-weight: 500;
            font-size: 0.75rem;
            padding: 0.35em 0.7em;
            border-radius: 0.375rem;
        }

        /* ===== フォーム ===== */
        .form-control, .form-select {
            background-color: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            color: var(--text-primary);
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control::placeholder {
            color: #94A3B8;
        }

        .form-control:focus, .form-select:focus {
            background-color: #FFFFFF;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.15);
            color: var(--text-primary);
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
        }

        .input-group-text {
            background-color: #FFFFFF;
            border-color: var(--border);
            color: #94A3B8;
        }

        /* ===== ページヘッダー ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-header h4 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            padding-left: 0.75rem;
            border-left: 4px solid var(--primary);
        }

        /* ===== アラート ===== */
        .alert {
            border-radius: 0.75rem;
            border: none;
            font-size: 0.875rem;
            padding: 0.875rem 1.25rem;
        }

        .alert-success {
            background-color: #ECFDF5;
            color: #065F46;
        }

        .alert-danger {
            background-color: #FEF2F2;
            color: #991B1B;
        }

        /* ===== ページネーション ===== */
        .pagination {
            font-size: 0.875rem;
        }

        .page-link {
            color: var(--primary);
            border-radius: 0.375rem;
            margin: 0 2px;
            border-color: var(--border);
            background-color: var(--card-bg);
        }

        .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            color: #1E293B;
        }

        /* ===== 詳細ページ情報グリッド ===== */
        .info-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 0.9rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        /* ===== ログアウトボタン ===== */
        .logout-btn {
            width: 100%;
            background: none;
            border: 1px solid rgba(255, 140, 0, 0.3);
            color: #64748B;
            border-radius: 0.5rem;
            padding: 0.4rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: 'Noto Sans JP', sans-serif;
        }

        .logout-btn:hover {
            border-color: #FF8C00;
            color: #FF8C00;
            background-color: rgba(255, 140, 0, 0.05);
        }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- サイドバー -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h5><i class="bi bi-graph-up-arrow me-2"></i>営業支援システム</h5>
            <p>Sales Support System</p>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">メインメニュー</div>
            <a href="/" class="sidebar-link {{ request()->is('/') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i>ダッシュボード
            </a>
            <a href="{{ route('customers.index') }}"
               class="sidebar-link {{ request()->is('customers*') ? 'active' : '' }}">
                <i class="bi bi-building"></i>顧客管理
            </a>
            <a href="{{ route('contacts.index') }}"
                class="sidebar-link {{ request()->is('contacts*') ? 'active' : '' }}">
                <i class="bi bi-person"></i>担当者管理
            </a>
            <a href="{{ route('deals.index') }}"
                class="sidebar-link {{ request()->is('deals*') ? 'active' : '' }}">
                <i class="bi bi-briefcase"></i>商談管理
            </a>
            <a href="{{ route('activities.index') }}"
                class="sidebar-link {{ request()->is('activities*') ? 'active' : '' }}">
                <i class="bi bi-clock-history"></i>活動履歴
            </a>
            <a href="{{ route('tasks.index') }}"
            class="sidebar-link {{ request()->is('tasks*') ? 'active' : '' }}">
                <i class="bi bi-check2-square"></i>タスク管理
            </a>
            <a href="{{ route('business-cards.index') }}"
            class="sidebar-link {{ request()->is('business-cards*') ? 'active' : '' }}">
                <i class="bi bi-credit-card-2-front"></i>名刺管理
            </a>
        </div>
        <!-- {{-- ユーザー情報・ログアウト --}} -->
        <div style="position: absolute; bottom: 0; left: 0; right: 0;
                    padding: 1rem 1.25rem;
                    border-top: 1px solid rgba(255,140,0,0.2);
                    background: linear-gradient(160deg, #0F1C2E 0%, #1A2C42 100%);">
            <div style="color: #94A3B8; font-size: 0.8rem; margin-bottom: 0.5rem;
                        white-space: nowrap; overflow: hidden; text-overflow: ellipsis">
                <i class="bi bi-person-circle me-2"></i>
                {{ Auth::user()->name }}
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="logout-btn">
                    <i class="bi bi-box-arrow-left me-1"></i>ログアウト
                </button>
            </form>
        </div>
    </div>

    <!-- メインコンテンツ -->
    <div class="main-content">
        <!-- トップナビ -->
        <div class="topnav">
            <div class="topnav-title">@yield('title', '営業支援システム')</div>
            <div class="topnav-right">
                <span class="topnav-date">
                    <i class="bi bi-calendar3 me-1"></i>
                    {{ now()->format('Y年m月d日') }}
                </span>
            </div>
        </div>

        <!-- コンテンツエリア -->
        <div class="content-area">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @yield('content')
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
