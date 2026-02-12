<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '営業支援システム')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS変数（ここを変えるだけでテーマ変更可能） ===== */
        :root {
            --primary:        #F59E0B;
            --primary-dark:   #D97706;
            --primary-light:  #FEF3C7;
            --success:        #10B981;
            --warning:        #F59E0B;
            --danger:         #EF4444;
            --info:           #06B6D4;
            --sidebar-bg:     #1A2235;
            --sidebar-hover:  #243048;
            --sidebar-text:   #64748B;
            --sidebar-active: #F59E0B;
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
            background-color: var(--sidebar-bg);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.5rem 1.25rem 1rem;
            border-bottom: 1px solid #2D3B55;
        }
        .sidebar-brand h5 {
            color: #FFFFFF;
            font-weight: 700;
            font-size: 1rem;
            margin: 0;
            letter-spacing: 0.05em;
        }
        .sidebar-brand p {
            color: var(--sidebar-text);
            font-size: 0.75rem;
            margin: 0.25rem 0 0;
        }
        .nav-section {
            padding: 1rem 0 0.5rem;
        }
        .nav-section-title {
            color: #475569;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 0 1.25rem 0.5rem;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--sidebar-text);
            text-decoration: none;
            padding: 0.6rem 1.25rem;
            transition: all 0.15s ease;
            font-size: 0.875rem;
            border-left: 3px solid transparent;
        }
        .sidebar-link:hover {
            color: var(--sidebar-active);
            background-color: var(--sidebar-hover);
            border-left-color: var(--primary);
        }
        .sidebar-link.active {
            color: var(--sidebar-active);
            background-color: var(--sidebar-hover);
            border-left-color: var(--primary);
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
            padding: 0 1.5rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
            background-color: #F8FAFC;
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
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
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
</body>
</html>