@extends('layouts.app')

@section('title', 'タスク編集')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-pencil me-2" style="color: var(--primary)"></i>タスク編集</h4>
    </div>
    <a href="{{ route('tasks.show', $task) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>詳細に戻る
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('tasks.update', $task) }}" method="POST">
            @csrf
            @method('PUT')
            @include('tasks._form', ['customerId' => null])
            <div class="mt-4">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-1"></i>更新する
                </button>
                <a href="{{ route('tasks.show', $task) }}" class="btn btn-outline-secondary ms-2">
                    キャンセル
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
