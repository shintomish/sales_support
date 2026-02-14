@extends('layouts.app')

@section('title', '活動履歴詳細')

@section('content')
@php
    $typeStyles = [
        '訪問'  => ['icon' => 'bi-person-walking', 'bg' => '#EFF6FF', 'color' => '#2563EB'],
        '電話'  => ['icon' => 'bi-telephone',      'bg' => '#ECFDF5', 'color' => '#10B981'],
        'メール' => ['icon' => 'bi-envelope',       'bg' => '#FFF3E0', 'color' => '#FF8C00'],
        'その他' => ['icon' => 'bi-three-dots',     'bg' => '#F1F5F9', 'color' => '#64748B'],
    ];
    $style = $typeStyles[$activity->type] ?? $typeStyles['その他'];
@endphp

<div class="page-header">
    <div class="d-flex align-items-center gap-3">
        <div class="activity-type-icon"
             style="background-color:{{ $style['bg'] }};
                    color:{{ $style['color'] }};
                    width:48px; height:48px; border-radius:50%;
                    display:flex; align-items:center; justify-content:center;
                    font-size:1.25rem; flex-shrink:0">
            <i class="bi {{ $style['icon'] }}"></i>
        </div>
        <div>
            <h4 style="margin:0">{{ $activity->subject }}</h4>
            <p class="text-muted mb-0" style="font-size:0.8rem">
                <span class="badge me-2"
                      style="background-color:{{ $style['bg'] }};
                             color:{{ $style['color'] }}">
                    {{ $activity->type }}
                </span>
                {{ $activity->activity_date->format('Y年m月d日') }}
            </p>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('activities.edit', $activity) }}" class="btn btn-warning">
            <i class="bi bi-pencil me-1"></i>編集
        </a>
        <a href="{{ route('activities.index') }}" class="btn btn-outline-secondary">
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
                    <a href="{{ route('customers.show', $activity->customer) }}"
                       class="text-decoration-none" style="color: var(--primary)">
                        {{ $activity->customer->company_name ?? '-' }}
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">担当者</div>
                <div class="info-value">
                    @if($activity->contact)
                        <a href="{{ route('contacts.show', $activity->contact) }}"
                           class="text-decoration-none" style="color: var(--primary)">
                            {{ $activity->contact->name }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">関連商談</div>
                <div class="info-value">
                    @if($activity->deal)
                        <a href="{{ route('deals.show', $activity->deal) }}"
                           class="text-decoration-none" style="color: var(--primary)">
                            {{ $activity->deal->title }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">活動種別</div>
                <div class="info-value">
                    <span class="badge"
                          style="background-color:{{ $style['bg'] }};
                                 color:{{ $style['color'] }};
                                 font-size:0.85rem">
                        <i class="bi {{ $style['icon'] }} me-1"></i>{{ $activity->type }}
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">活動日</div>
                <div class="info-value">
                    {{ $activity->activity_date->format('Y年m月d日') }}
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">営業担当</div>
                <div class="info-value">{{ $activity->user->name ?? '-' }}</div>
            </div>
            <div class="col-md-12">
                <div class="info-label">件名</div>
                <div class="info-value fw-bold">{{ $activity->subject }}</div>
            </div>
            @if($activity->content)
                <div class="col-md-12">
                    <div class="info-label">内容</div>
                    <div class="card" style="background-color:#F8FAFC; border-color:var(--border)">
                        <div class="card-body py-3" style="white-space: pre-wrap; font-size:0.9rem">
                            {{ $activity->content }}
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
