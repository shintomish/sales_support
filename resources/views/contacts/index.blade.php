@extends('layouts.app')

@section('title', '担当者管理')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-person me-2" style="color: var(--primary)"></i>担当者一覧</h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">全 {{ $contacts->total() }} 件</p>
    </div>
    <a href="{{ route('contacts.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>新規登録
    </a>
</div>

{{-- 検索・フィルター --}}
<div class="card mb-4">
    <div class="card-body py-3">
        <form action="{{ route('contacts.index') }}" method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search"
                           class="form-control border-start-0"
                           placeholder="氏名・部署・役職・会社名で検索"
                           value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-3">
                <select name="customer_id" class="form-select">
                    <option value="">全顧客</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}"
                            {{ request('customer_id') == $customer->id ? 'selected' : '' }}>
                            {{ $customer->company_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>検索
                </button>
                @if(request('search') || request('customer_id'))
                    <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary ms-1">
                        <i class="bi bi-x"></i> クリア
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- 担当者一覧テーブル --}}
<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>会社名</th>
                    <th>部署</th>
                    <th>役職</th>
                    <th>メール</th>
                    <th>電話番号</th>
                    <th class="text-center">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($contacts as $contact)
                    <tr>
                        <td>
                            <a href="{{ route('contacts.show', $contact) }}"
                               class="text-decoration-none fw-bold"
                               style="color: var(--primary)">
                                {{ $contact->name }}
                            </a>
                        </td>
                        <td>
                            <a href="{{ route('customers.show', $contact->customer) }}"
                               class="text-decoration-none"
                               style="color: var(--text-muted); font-size:0.85rem">
                                {{ $contact->customer->company_name ?? '-' }}
                            </a>
                        </td>
                        <td>{{ $contact->department ?? '-' }}</td>
                        <td>
                            @if($contact->position)
                                <span class="badge"
                                      style="background-color: var(--primary-light);
                                             color: var(--primary)">
                                    {{ $contact->position }}
                                </span>
                            @else
                                -
                            @endif
                        </td>
                        <td style="font-size:0.85rem">{{ $contact->email ?? '-' }}</td>
                        <td style="font-size:0.85rem">{{ $contact->phone ?? '-' }}</td>
                        <td class="text-center">
                            <a href="{{ route('contacts.show', $contact) }}"
                               class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('contacts.edit', $contact) }}"
                               class="btn btn-sm btn-outline-warning me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('contacts.destroy', $contact) }}"
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
                        <td colspan="7" class="text-center py-5">
                            <i class="bi bi-inbox display-6 d-block mb-2 text-muted"></i>
                            <span class="text-muted">担当者が登録されていません</span><br>
                            <a href="{{ route('contacts.create') }}"
                               class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-plus-circle me-1"></i>最初の担当者を登録する
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($contacts->hasPages())
    <div class="mt-3 d-flex justify-content-center">
        {{ $contacts->appends(request()->query())->links() }}
    </div>
@endif
@endsection
