@extends('layouts.app')

@section('title', '活動履歴')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-clock-history me-2" style="color: var(--primary)"></i>活動履歴</h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">全 {{ $activities->total() }} 件</p>
    </div>
    <a href="{{ route('activities.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>新規登録
    </a>
</div>

{{-- 検索・フィルター --}}
<div class="card mb-4">
    <div class="card-body py-3">
        <form action="{{ route('activities.index') }}" method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search"
                           class="form-control border-start-0"
                           placeholder="件名・内容・会社名で検索"
                           value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="">全種別</option>
                    @foreach(['訪問', '電話', 'メール', 'その他'] as $type)
                        <option value="{{ $type }}"
                            {{ request('type') === $type ? 'selected' : '' }}>
                            {{ $type }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
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
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control"
                       value="{{ request('date_from') }}" placeholder="開始日">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control"
                       value="{{ request('date_to') }}" placeholder="終了日">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>検索
                </button>
                @if(request('search') || request('type') || request('customer_id') || request('date_from') || request('date_to'))
                    <a href="{{ route('activities.index') }}" class="btn btn-outline-secondary ms-1">
                        <i class="bi bi-x"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- 活動履歴一覧 --}}
<div class="card">
    <div class="card-body p-0">
        @php
            $typeStyles = [
                '訪問'  => ['icon' => 'bi-person-walking', 'bg' => '#EFF6FF', 'color' => '#2563EB'],
                '電話'  => ['icon' => 'bi-telephone',      'bg' => '#ECFDF5', 'color' => '#10B981'],
                'メール' => ['icon' => 'bi-envelope',       'bg' => '#FFF3E0', 'color' => '#FF8C00'],
                'その他' => ['icon' => 'bi-three-dots',     'bg' => '#F1F5F9', 'color' => '#64748B'],
            ];
        @endphp
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>活動日</th>
                    <th>種別</th>
                    <th>件名</th>
                    <th>顧客</th>
                    <th>担当者</th>
                    <th>関連商談</th>
                    <th class="text-center">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($activities as $activity)
                    @php $style = $typeStyles[$activity->type] ?? $typeStyles['その他']; @endphp
                    <tr>
                        <td style="font-size:0.85rem; color:var(--text-muted); white-space:nowrap">
                            {{ $activity->activity_date->format('Y/m/d') }}
                        </td>
                        <td>
                            <span class="badge"
                                  style="background-color:{{ $style['bg'] }};
                                         color:{{ $style['color'] }}">
                                <i class="bi {{ $style['icon'] }} me-1"></i>{{ $activity->type }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('activities.show', $activity) }}"
                               class="text-decoration-none fw-bold"
                               style="color: var(--primary)">
                                {{ $activity->subject }}
                            </a>
                        </td>
                        <td style="font-size:0.85rem">
                            {{ $activity->customer->company_name ?? '-' }}
                        </td>
                        <td style="font-size:0.85rem">
                            {{ $activity->contact->name ?? '-' }}
                        </td>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ $activity->deal->title ?? '-' }}
                        </td>
                        <td class="text-center">
                            <a href="{{ route('activities.show', $activity) }}"
                               class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('activities.edit', $activity) }}"
                               class="btn btn-sm btn-outline-warning me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('activities.destroy', $activity) }}"
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
                            <span class="text-muted">活動履歴が登録されていません</span><br>
                            <a href="{{ route('activities.create') }}"
                               class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-plus-circle me-1"></i>最初の活動を登録する
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($activities->hasPages())
    <div class="mt-3 d-flex justify-content-center">
        {{ $activities->appends(request()->query())->links() }}
    </div>
@endif
@endsection
