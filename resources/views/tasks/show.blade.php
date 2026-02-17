@extends('layouts.app')

@section('title', 'タスク詳細')

@section('content')
@php
    $priorityStyle = match($task->priority) {
        '高' => ['bg' => '#FEF2F2', 'text' => '#991B1B'],
        '中' => ['bg' => '#FFF3E0', 'text' => '#E67E00'],
        '低' => ['bg' => '#F1F5F9', 'text' => '#475569'],
        default => ['bg' => '#F1F5F9', 'text' => '#475569'],
    };
    $statusStyle = match($task->status) {
        '完了'   => ['bg' => '#ECFDF5', 'text' => '#065F46'],
        '進行中' => ['bg' => '#EFF6FF', 'text' => '#1D4ED8'],
        default  => ['bg' => '#F1F5F9', 'text' => '#475569'],
    };
    $isOverdue = $task->due_date && $task->due_date->isPast() && $task->status !== '完了';
@endphp

<div class="page-header">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h4 style="margin:0">{{ $task->title }}</h4>
            <div class="d-flex gap-2 mt-1">
                <span class="badge"
                      style="background-color:{{ $priorityStyle['bg'] }};
                             color:{{ $priorityStyle['text'] }}">
                    優先度：{{ $task->priority }}
                </span>
                <span class="badge"
                      style="background-color:{{ $statusStyle['bg'] }};
                             color:{{ $statusStyle['text'] }}">
                    {{ $task->status }}
                </span>
                @if($isOverdue)
                    <span class="badge"
                          style="background-color:#FEF2F2; color:#991B1B">
                        期限超過
                    </span>
                @endif
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('tasks.edit', $task) }}" class="btn btn-warning">
            <i class="bi bi-pencil me-1"></i>編集
        </a>
        <a href="{{ route('tasks.index') }}" class="btn btn-outline-secondary">
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
                <div class="info-label">優先度</div>
                <div class="info-value">
                    <span class="badge"
                          style="background-color:{{ $priorityStyle['bg'] }};
                                 color:{{ $priorityStyle['text'] }}; font-size:0.85rem">
                        {{ $task->priority }}
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">ステータス</div>
                <div class="info-value">
                    <span class="badge"
                          style="background-color:{{ $statusStyle['bg'] }};
                                 color:{{ $statusStyle['text'] }}; font-size:0.85rem">
                        {{ $task->status }}
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">期限日</div>
                <div class="info-value"
                     style="{{ $isOverdue ? 'color:#EF4444; font-weight:600' : '' }}">
                    {{ $task->due_date ? $task->due_date->format('Y年m月d日') : '-' }}
                    @if($isOverdue)
                        <span class="badge ms-1"
                              style="background-color:#FEF2F2; color:#991B1B">期限超過</span>
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">顧客</div>
                <div class="info-value">
                    @if($task->customer)
                        <a href="{{ route('customers.show', $task->customer) }}"
                           class="text-decoration-none" style="color: var(--primary)">
                            {{ $task->customer->company_name }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">関連商談</div>
                <div class="info-value">
                    @if($task->deal)
                        <a href="{{ route('deals.show', $task->deal) }}"
                           class="text-decoration-none" style="color: var(--primary)">
                            {{ $task->deal->title }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">担当者</div>
                <div class="info-value">{{ $task->user->name ?? '-' }}</div>
            </div>
            @if($task->description)
                <div class="col-md-12">
                    <div class="info-label">詳細</div>
                    <div class="card" style="background-color:#F8FAFC; border-color:var(--border)">
                        <div class="card-body py-3"
                             style="white-space: pre-wrap; font-size:0.9rem">
                            {{ $task->description }}
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- ステータス変更ボタン --}}
@if($task->status !== '完了')
    <div class="card">
        <div class="card-body">
            <div class="d-flex gap-2 align-items-center">
                <span style="font-size:0.875rem; font-weight:600">
                    ステータスを変更：
                </span>
                @if($task->status === '未着手')
                    <button onclick="updateStatus({{ $task->id }}, '進行中')"
                            class="btn btn-sm"
                            style="background-color:#EFF6FF; color:#1D4ED8;
                                   border-color:#BFDBFE">
                        <i class="bi bi-play-circle me-1"></i>進行中にする
                    </button>
                @endif
                <button onclick="updateStatus({{ $task->id }}, '完了')"
                        class="btn btn-sm"
                        style="background-color:#ECFDF5; color:#065F46;
                               border-color:#A7F3D0">
                    <i class="bi bi-check-circle me-1"></i>完了にする
                </button>
            </div>
        </div>
    </div>

    <script>
    function updateStatus(taskId, status) {
        fetch(`/tasks/${taskId}/status`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ status })
        }).then(() => location.reload());
    }
    </script>
@endif
@endsection
