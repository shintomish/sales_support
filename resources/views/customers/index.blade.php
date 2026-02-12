@extends('layouts.app')

@section('title', '顧客管理')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-building me-2" style="color: var(--primary)"></i>顧客一覧</h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">
            全 {{ $customers->total() }} 件
        </p>
    </div>
    <a href="{{ route('customers.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>新規登録
    </a>
</div>

<!-- 検索フォーム -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form action="{{ route('customers.index') }}" method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search"
                           class="form-control border-start-0"
                           placeholder="会社名・業種で検索"
                           value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">検索</button>
                @if(request('search'))
                    <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary ms-1">
                        <i class="bi bi-x"></i> クリア
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

<!-- 顧客一覧テーブル -->
<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>会社名</th>
                    <th>業種</th>
                    <th>従業員数</th>
                    <th>電話番号</th>
                    <th>登録日</th>
                    <th class="text-center">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td>
                            <a href="{{ route('customers.show', $customer) }}"
                               class="text-decoration-none fw-600"
                               style="color: var(--primary); font-weight:600">
                                {{ $customer->company_name }}
                            </a>
                        </td>
                        <td>
                            @if($customer->industry)
                                <span class="badge"
                                      style="background-color: var(--primary-light);
                                             color: var(--primary)">
                                    {{ $customer->industry }}
                                </span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>{{ $customer->employee_count ? number_format($customer->employee_count) . '名' : '-' }}</td>
                        <td>{{ $customer->phone ?? '-' }}</td>
                        <td>
                            <span style="color: var(--text-muted); font-size:0.8rem">
                                {{ $customer->created_at->format('Y/m/d') }}
                            </span>
                        </td>
                        <td class="text-center">
                            <a href="{{ route('customers.show', $customer) }}"
                               class="btn btn-sm btn-outline-primary me-1"
                               title="詳細">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('customers.edit', $customer) }}"
                               class="btn btn-sm btn-outline-warning me-1"
                               title="編集">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('customers.destroy', $customer) }}"
                                  method="POST" class="d-inline"
                                  onsubmit="return confirm('削除してもよろしいですか？')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="削除">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <i class="bi bi-inbox display-6 d-block mb-2 text-muted"></i>
                            <span class="text-muted">顧客が登録されていません</span><br>
                            <a href="{{ route('customers.create') }}"
                               class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-plus-circle me-1"></i>最初の顧客を登録する
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($customers->hasPages())
    <div class="mt-3 d-flex justify-content-center">
        {{ $customers->appends(request()->query())->links() }}
    </div>
@endif
@endsection
