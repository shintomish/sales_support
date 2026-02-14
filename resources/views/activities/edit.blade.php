@extends('layouts.app')

@section('title', '活動履歴編集')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-pencil me-2" style="color: var(--primary)"></i>活動履歴編集</h4>
    </div>
    <a href="{{ route('activities.show', $activity) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>詳細に戻る
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('activities.update', $activity) }}" method="POST">
            @csrf
            @method('PUT')
            @include('activities._form', ['customerId' => null])
            <div class="mt-4">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-1"></i>更新する
                </button>
                <a href="{{ route('activities.show', $activity) }}"
                   class="btn btn-outline-secondary ms-2">
                    キャンセル
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
