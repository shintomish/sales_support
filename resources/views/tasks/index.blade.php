@extends('layouts.app')

@section('title', 'タスク管理')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-check2-square me-2" style="color: var(--primary)"></i>タスク一覧</h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">全 {{ $tasks->total() }} 件</p>
    </div>
    <a href="{{ route('tasks.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>新規登録
    </a>
</div>

{{-- 検索・フィルター --}}
<div class="card mb-4">
    <div class="card-body py-3">
        <form action="{{ route('tasks.index') }}" method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search"
                           class="form-control border-start-0"
                           placeholder="タイトル・会社名で検索"
                           value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">全ステータス</option>
                    @foreach(['未着手', '進行中', '完了'] as $s)
                        <option value="{{ $s }}"
                            {{ request('status') === $s ? 'selected' : '' }}>
                            {{ $s }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="priority" class="form-select">
                    <option value="">全優先度</option>
                    @foreach(['高', '中', '低'] as $p)
                        <option value="{{ $p }}"
                            {{ request('priority') === $p ? 'selected' : '' }}>
                            {{ $p }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check ms-2">
                    <input type="checkbox" name="my_tasks" value="1"
                           class="form-check-input" id="my_tasks"
                           {{ request('my_tasks') ? 'checked' : '' }}>
                    <label class="form-check-label" for="my_tasks"
                           style="font-size:0.875rem">
                        自分のタスク
                    </label>
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>検索
                </button>
                @if(request('search') || request('status') || request('priority') || request('my_tasks'))
                    <a href="{{ route('tasks.index') }}" class="btn btn-outline-secondary ms-1">
                        <i class="bi bi-x"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- タスク一覧テーブル --}}
<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>優先度</th>
                    <th>タイトル</th>
                    <th>ステータス</th>
                    <th>顧客</th>
                    <th>期限日</th>
                    <th>担当者</th>
                    <th class="text-center">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tasks as $task)
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
                        $isToday   = $task->due_date && $task->due_date->isToday();
                    @endphp
                    <tr class="{{ $task->status === '完了' ? 'opacity-75' : '' }}">
                        <td>
                            <span class="badge"
                                  style="background-color:{{ $priorityStyle['bg'] }};
                                         color:{{ $priorityStyle['text'] }}">
                                {{ $task->priority }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('tasks.show', $task) }}"
                               class="text-decoration-none fw-bold
                               {{ $task->status === '完了' ? 'text-decoration-line-through' : '' }}"
                               style="color: var(--primary)">
                                {{ $task->title }}
                            </a>
                            @if($task->description)
                                <div style="font-size:0.78rem; color:var(--text-muted)">
                                    {{ Str::limit($task->description, 40) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="badge"
                                  style="background-color:{{ $statusStyle['bg'] }};
                                         color:{{ $statusStyle['text'] }}">
                                {{ $task->status }}
                            </span>
                        </td>
                        <td style="font-size:0.85rem">
                            {{ $task->customer->company_name ?? '-' }}
                        </td>
                        <td>
                            @if($task->due_date)
                                <span style="font-size:0.85rem;
                                    color: {{ $isOverdue ? '#EF4444' : ($isToday ? '#FF8C00' : 'var(--text-muted)') }};
                                    font-weight: {{ $isOverdue || $isToday ? '600' : 'normal' }}">
                                    {{ $task->due_date->format('Y/m/d') }}
                                    @if($isToday)
                                        <span class="badge ms-1"
                                              style="background-color:#FFF3E0;
                                                     color:#E67E00; font-size:0.65rem">今日</span>
                                    @elseif($isOverdue)
                                        <span class="badge ms-1"
                                              style="background-color:#FEF2F2;
                                                     color:#991B1B; font-size:0.65rem">期限超過</span>
                                    @endif
                                </span>
                            @else
                                <span style="color:var(--text-muted)">-</span>
                            @endif
                        </td>
                        <td style="font-size:0.85rem">
                            {{ $task->user->name ?? '-' }}
                        </td>
                        <td class="text-center">
                            <a href="{{ route('tasks.show', $task) }}"
                               class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('tasks.edit', $task) }}"
                               class="btn btn-sm btn-outline-warning me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('tasks.destroy', $task) }}"
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
                            <span class="text-muted">タスクが登録されていません</span><br>
                            <a href="{{ route('tasks.create') }}"
                               class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-plus-circle me-1"></i>最初のタスクを登録する
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($tasks->hasPages())
    <div class="mt-3 d-flex justify-content-center">
        {{ $tasks->appends(request()->query())->links() }}
    </div>
@endif
@endsection
