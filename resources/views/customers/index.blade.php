@extends('layouts.app')

@section('title', '顧客一覧')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-building me-2"></i>顧客一覧</h4>
    <a href="{{ route('customers.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>新規登録
    </a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form action="{{ route('customers.index') }}" method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="会社名・業種で検索" value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-search me-1"></i>検索
                </button>
            </div>
            @if(request('search'))
                <div class="col-md-2">
                    <a href="{{ route('customers.index') }}" class="btn btn-outline-danger w-100">
                        <i class="bi bi-x-circle me-1"></i>クリア
                    </a>
                </div>
            @endif
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
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
                               class="text-decoration-none fw-bold">
                                {{ $customer->company_name }}
                            </a>
                        </td>
                        <td>{{ $customer->industry ?? '-' }}</td>
                        <td>{{ $customer->employee_count ? number_format($customer->employee_count) . '名' : '-' }}</td>
                        <td>{{ $customer->phone ?? '-' }}</td>
                        <td>{{ $customer->created_at->format('Y/m/d') }}</td>
                        <td class="text-center">
                            <a href="{{ route('customers.show', $customer) }}"
                               class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('customers.edit', $customer) }}"
                               class="btn btn-sm btn-outline-warning me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('customers.destroy', $customer) }}"
                                  method="POST" class="d-inline"
                                  onsubmit="return confirm('削除してもよろしいですか？')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            顧客が登録されていません。
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
