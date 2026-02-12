@extends('layouts.app')

@section('title', 'ダッシュボード')

@section('content')

{{-- KPIカード --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">総顧客数</div>
                        <div class="kpi-value">{{ number_format($kpi['customers']) }}</div>
                        <div class="kpi-unit">社</div>
                    </div>
                    <div class="kpi-icon" style="background-color: #EFF6FF; color: #2563EB">
                        <i class="bi bi-building"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">進行中の商談</div>
                        <div class="kpi-value">{{ number_format($kpi['deals_active']) }}</div>
                        <div class="kpi-unit">件</div>
                    </div>
                    <div class="kpi-icon" style="background-color: #FFF3E0; color: #FF8C00">
                        <i class="bi bi-briefcase"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">今月の成約</div>
                        <div class="kpi-value">{{ number_format($kpi['won_this_month']) }}</div>
                        <div class="kpi-unit">件</div>
                    </div>
                    <div class="kpi-icon" style="background-color: #ECFDF5; color: #10B981">
                        <i class="bi bi-trophy"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">今月の売上</div>
                        <div class="kpi-value">{{ number_format($kpi['revenue_this_month'] / 10000, 1) }}</div>
                        <div class="kpi-unit">万円</div>
                    </div>
                    <div class="kpi-icon" style="background-color: #FDF2F8; color: #DB2777">
                        <i class="bi bi-currency-yen"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 商談パイプライン & 期限タスク --}}
<div class="row g-3 mb-4">
    {{-- 商談パイプライン --}}
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2" style="color: var(--primary)"></i>
                商談パイプライン
            </div>
            <div class="card-body">
                @php
                    $statusColors = [
                        '新規' => ['bar' => '#94A3B8', 'bg' => '#F1F5F9', 'text' => '#475569'],
                        '提案' => ['bar' => '#3B82F6', 'bg' => '#EFF6FF', 'text' => '#1D4ED8'],
                        '交渉' => ['bar' => '#FF8C00', 'bg' => '#FFF3E0', 'text' => '#E67E00'],
                        '成約' => ['bar' => '#10B981', 'bg' => '#ECFDF5', 'text' => '#065F46'],
                        '失注' => ['bar' => '#EF4444', 'bg' => '#FEF2F2', 'text' => '#991B1B'],
                    ];
                    $totalDeals = $kpi['deals'] ?: 1;
                @endphp

                @foreach($statusColors as $status => $color)
                    @php
                        $count = $dealsByStatus[$status] ?? 0;
                        $percent = round($count / $totalDeals * 100);
                    @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge"
                                  style="background-color:{{ $color['bg'] }};
                                         color:{{ $color['text'] }}">
                                {{ $status }}
                            </span>
                            <span style="font-size:0.8rem; color:var(--text-muted)">
                                {{ $count }}件
                                @if($status !== '失注' && $status !== '成約')
                                    &nbsp;/&nbsp;
                                    ¥{{ number_format(($pipeline->firstWhere('status', $status)->total ?? 0) / 10000, 1) }}万円
                                @endif
                            </span>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: 4px; background-color: #F1F5F9">
                            <div class="progress-bar"
                                 style="width: {{ $percent }}%;
                                        background-color: {{ $color['bar'] }};
                                        border-radius: 4px;
                                        transition: width 0.6s ease">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 期限が近いタスク --}}
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-check2-square me-2" style="color: var(--primary)"></i>
                期限が近いタスク
            </div>
            <div class="card-body p-0">
                @forelse($upcomingTasks as $task)
                    <div class="task-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="task-title">{{ $task->title }}</div>
                            <span class="badge ms-2 flex-shrink-0"
                                  style="background-color:
                                    {{ $task->priority === '高' ? '#FEF2F2' : ($task->priority === '中' ? '#FFF3E0' : '#F1F5F9') }};
                                  color:
                                    {{ $task->priority === '高' ? '#991B1B' : ($task->priority === '中' ? '#E67E00' : '#475569') }}">
                                {{ $task->priority }}
                            </span>
                        </div>
                        <div class="task-meta">
                            @if($task->customer)
                                <i class="bi bi-building me-1"></i>{{ $task->customer->company_name }}
                            @endif
                            <span class="ms-2
                                {{ $task->due_date && $task->due_date->isPast() ? 'text-danger' : '' }}">
                                <i class="bi bi-calendar3 me-1"></i>
                                {{ $task->due_date ? $task->due_date->format('m/d') : '-' }}
                                @if($task->due_date && $task->due_date->isToday())
                                    <span class="badge bg-danger ms-1" style="font-size:0.65rem">今日</span>
                                @endif
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check-all d-block mb-1" style="font-size:1.5rem"></i>
                        期限が近いタスクはありません
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- 直近の活動履歴 & 今月の成約 --}}
<div class="row g-3">
    {{-- 直近の活動履歴 --}}
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-clock-history me-2" style="color: var(--primary)"></i>
                直近の活動履歴
            </div>
            <div class="card-body p-0">
                @php
                    $activityIcons = [
                        '訪問' => ['icon' => 'bi-person-walking', 'bg' => '#EFF6FF', 'color' => '#2563EB'],
                        '電話' => ['icon' => 'bi-telephone', 'bg' => '#ECFDF5', 'color' => '#10B981'],
                        'メール' => ['icon' => 'bi-envelope', 'bg' => '#FFF3E0', 'color' => '#FF8C00'],
                        'その他' => ['icon' => 'bi-three-dots', 'bg' => '#F1F5F9', 'color' => '#64748B'],
                    ];
                @endphp
                @forelse($recentActivities as $activity)
                    @php $icon = $activityIcons[$activity->type] ?? $activityIcons['その他']; @endphp
                    <div class="activity-item">
                        <div class="activity-icon"
                             style="background-color:{{ $icon['bg'] }};
                                    color:{{ $icon['color'] }}">
                            <i class="bi {{ $icon['icon'] }}"></i>
                        </div>
                        <div class="activity-body">
                            <div class="activity-subject">{{ $activity->subject }}</div>
                            <div class="activity-meta">
                                @if($activity->customer)
                                    <span><i class="bi bi-building me-1"></i>{{ $activity->customer->company_name }}</span>
                                @endif
                                <span class="ms-2">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    {{ $activity->activity_date->format('m/d') }}
                                </span>
                            </div>
                        </div>
                        <span class="badge flex-shrink-0"
                              style="background-color:{{ $icon['bg'] }};
                                     color:{{ $icon['color'] }}">
                            {{ $activity->type }}
                        </span>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">
                        活動履歴がありません
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- 今月の成約商談 --}}
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-trophy me-2" style="color: #10B981"></i>
                今月の成約商談
            </div>
            <div class="card-body p-0">
                @forelse($wonDeals as $deal)
                    <div class="won-item">
                        <div class="won-title">{{ $deal->title }}</div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span style="font-size:0.78rem; color:var(--text-muted)">
                                <i class="bi bi-building me-1"></i>
                                {{ $deal->customer->company_name ?? '-' }}
                            </span>
                            <span class="won-amount">
                                ¥{{ number_format($deal->amount) }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-trophy d-block mb-1" style="font-size:1.5rem; color:#D1D5DB"></i>
                        今月の成約はありません
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<style>
    /* KPIカード */
    .kpi-card .card-body { padding: 1.25rem; }
    .kpi-label {
        font-size: 0.75rem;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }
    .kpi-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1;
    }
    .kpi-unit {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }
    .kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    /* タスクアイテム */
    .task-item {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
    }
    .task-item:last-child { border-bottom: none; }
    .task-title {
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text-primary);
    }
    .task-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    /* 活動アイテム */
    .activity-item {
        display: flex;
        align-items: center;
        gap: 0.875rem;
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
    }
    .activity-item:last-child { border-bottom: none; }
    .activity-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    .activity-body { flex: 1; min-width: 0; }
    .activity-subject {
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .activity-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.2rem;
    }

    /* 成約アイテム */
    .won-item {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
    }
    .won-item:last-child { border-bottom: none; }
    .won-title {
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text-primary);
    }
    .won-amount {
        font-size: 0.9rem;
        font-weight: 700;
        color: #10B981;
    }
</style>
@endsection
