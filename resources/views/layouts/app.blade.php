<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>営業支援システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            min-height: 100vh;
            background-color: #343a40;
        }
        .sidebar a {
            color: #adb5bd;
            text-decoration: none;
            display: block;
            padding: 10px 20px;
        }
        .sidebar a:hover {
            color: #fff;
            background-color: #495057;
        }
        .sidebar .nav-title {
            color: #6c757d;
            font-size: 0.75rem;
            padding: 15px 20px 5px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <div class="sidebar" style="width: 220px; min-width: 220px;">
            <div class="p-3">
                <h5 class="text-white">営業支援システム</h5>
            </div>
            <hr class="border-secondary m-0">
            <div class="nav-title">メニュー</div>
            <a href="/"><i class="bi bi-house me-2"></i>ダッシュボード</a>
            <a href="{{ route('customers.index') }}"><i class="bi bi-building me-2"></i>顧客管理</a>
        </div>
        <div class="flex-grow-1">
            <nav class="navbar navbar-light bg-white border-bottom px-4">
                <span class="navbar-brand">@yield('title', '営業支援システム')</span>
            </nav>
            <div class="container-fluid p-4">
                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show">
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show">
                        {{ session('error') }}
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
