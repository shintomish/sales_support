@extends('layouts.app')

@section('title', '商談詳細')

@section('content')
@php
    $statusColor = match($deal->status) {
        '成約' => ['bg' => '#ECFDF5', 'text' => '#065F46'],
        '失注' => ['bg' => '#FEF2F2', 'text' => '#991B1B'],
        '交渉' => ['bg' => '#FFF3E0', 'text' => '#E67E00'],
        '提案' => ['bg' => '#EFF6FF', 'text' => '#1D4ED8'],
        default => ['bg' => '#F1F5F9', 'text' => '#475569'],
    };
@endphp

<div class="page-header">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h4><i class="bi bi-briefcase me-2" style="color: var(--primary)"></i>
                {{ $deal->title }}
            </h4>
            <p class="text-muted mb-0" style="font-size:0.8rem">
                登録日: {{ $deal->created_at->format('Y年m月d日') }}
            </p>
        </div>
        <span class="badge"
              style="background-color:{{ $statusColor['bg'] }};
                     color:{{ $statusColor['text'] }};
                     font-size:0.875rem; padding:0.5em 1em">
            {{ $deal->status }}
        </span>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('deals.edit', $deal) }}" class="btn btn-warning">
            <i class="bi bi-pencil me-1"></i>編集
        </a>
        <a href="{{ route('deals.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>一覧に戻る
        </a>
    </div>
</div>

{{-- 基本情報 --}}
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-info-circle me-2" style="color: var(--primary)"></i>基本情報
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="info-label">顧客</div>
                <div class="info-value">
                    <a href="{{ route('customers.show', $deal->customer) }}"
                       class="text-decoration-none" style="color: var(--primary)">
                        {{ $deal->customer->company_name ?? '-' }}
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">担当者</div>
                <div class="info-value">{{ $deal->contact->name ?? '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">営業担当</div>
                <div class="info-value">{{ $deal->user->name ?? '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">予定金額</div>
                <div class="info-value fw-bold" style="font-size:1.1rem; color: var(--primary)">
                    ¥{{ number_format($deal->amount) }}
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">ステータス</div>
                <div class="info-value">
                    <span class="badge"
                          style="background-color:{{ $statusColor['bg'] }};
                                 color:{{ $statusColor['text'] }};
                                 font-size:0.85rem">
                        {{ $deal->status }}
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">成約確度</div>
                <div class="info-value">
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress"
                             style="width:100px; height:8px; border-radius:4px; background:#F1F5F9">
                            <div class="progress-bar"
                                 style="width:{{ $deal->probability }}%;
                                        background-color: var(--primary)">
                            </div>
                        </div>
                        <span class="fw-bold">{{ $deal->probability }}%</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">予定成約日</div>
                <div class="info-value">
                    {{ $deal->expected_close_date ? $deal->expected_close_date->format('Y年m月d日') : '-' }}
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">実際の成約日</div>
                <div class="info-value">
                    {{ $deal->actual_close_date ? $deal->actual_close_date->format('Y年m月d日') : '-' }}
                </div>
            </div>
            @if($deal->notes)
                <div class="col-md-12">
                    <div class="info-label">備考</div>
                    <div class="info-value">{{ $deal->notes }}</div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- 活動履歴 --}}
<div class="card">
    <div class="card-header">
        <i class="bi bi-clock-history me-2" style="color: var(--primary)"></i>
        活動履歴
        <span class="badge ms-1"
              style="background-color: var(--primary-light); color: var(--primary)">
            {{ $deal->activities->count() }}件
        </span>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>活動日</th>
                    <th>種別</th>
                    <th>件名</th>
                    <th>内容</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deal->activities as $activity)
                    <tr>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ $activity->activity_date->format('Y/m/d') }}
                        </td>
                        <td>
                            <span class="badge" style="background-color:#F1F5F9; color:#475569">
                                {{ $activity->type }}
                            </span>
                        </td>
                        <td class="fw-bold">{{ $activity->subject }}</td>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ Str::limit($activity->content, 50) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            活動履歴が登録されていません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
