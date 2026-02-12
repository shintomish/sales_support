@extends('layouts.app')

@section('title', '商談管理')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-briefcase me-2" style="color: var(--primary)"></i>商談一覧</h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">全 {{ $deals->total() }} 件</p>
    </div>
    <a href="{{ route('deals.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>新規登録
    </a>
</div>

{{-- 検索・フィルター --}}
<div class="card mb-4">
    <div class="card-body py-3">
        <form action="{{ route('deals.index') }}" method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search"
                           class="form-control border-start-0"
                           placeholder="商談名・会社名で検索"
                           value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">全ステータス</option>
                    @foreach(['新規', '提案', '交渉', '成約', '失注'] as $status)
                        <option value="{{ $status }}"
                            {{ request('status') === $status ? 'selected' : '' }}>
                            {{ $status }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>検索
                </button>
                @if(request('search') || request('status'))
                    <a href="{{ route('deals.index') }}" class="btn btn-outline-secondary ms-1">
                        <i class="bi bi-x"></i> クリア
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- 商談一覧テーブル --}}
<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>商談名</th>
                    <th>顧客</th>
                    <th>金額</th>
                    <th>ステータス</th>
                    <th>成約確度</th>
                    <th>予定成約日</th>
                    <th class="text-center">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deals as $deal)
                    @php
                        $statusColor = match($deal->status) {
                            '成約' => ['bg' => '#ECFDF5', 'text' => '#065F46'],
                            '失注' => ['bg' => '#FEF2F2', 'text' => '#991B1B'],
                            '交渉' => ['bg' => '#FFF3E0', 'text' => '#E67E00'],
                            '提案' => ['bg' => '#EFF6FF', 'text' => '#1D4ED8'],
                            default => ['bg' => '#F1F5F9', 'text' => '#475569'],
                        };
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('deals.show', $deal) }}"
                               class="text-decoration-none fw-bold"
                               style="color: var(--primary)">
                                {{ $deal->title }}
                            </a>
                        </td>
                        <td>{{ $deal->customer->company_name ?? '-' }}</td>
                        <td class="fw-bold">¥{{ number_format($deal->amount) }}</td>
                        <td>
                            <span class="badge"
                                  style="background-color:{{ $statusColor['bg'] }};
                                         color:{{ $statusColor['text'] }}">
                                {{ $deal->status }}
                            </span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress"
                                     style="width:60px; height:6px; border-radius:3px; background:#F1F5F9">
                                    <div class="progress-bar"
                                         style="width:{{ $deal->probability }}%;
                                                background-color: var(--primary)">
                                    </div>
                                </div>
                                <span style="font-size:0.8rem">{{ $deal->probability }}%</span>
                            </div>
                        </td>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ $deal->expected_close_date ? $deal->expected_close_date->format('Y/m/d') : '-' }}
                        </td>
                        <td class="text-center">
                            <a href="{{ route('deals.show', $deal) }}"
                               class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('deals.edit', $deal) }}"
                               class="btn btn-sm btn-outline-warning me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('deals.destroy', $deal) }}"
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
                            <span class="text-muted">商談が登録されていません</span><br>
                            <a href="{{ route('deals.create') }}" class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-plus-circle me-1"></i>最初の商談を登録する
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($deals->hasPages())
    <div class="mt-3 d-flex justify-content-center">
        {{ $deals->appends(request()->query())->links() }}
    </div>
@endif
@endsection
