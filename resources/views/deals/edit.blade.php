@extends('layouts.app')

@section('title', '商談編集')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-pencil me-2" style="color: var(--primary)"></i>商談編集</h4>
    </div>
    <a href="{{ route('deals.show', $deal) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>詳細に戻る
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('deals.update', $deal) }}" method="POST">
            @csrf
            @method('PUT')
            @include('deals._form')
            <div class="mt-4">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-1"></i>更新する
                </button>
                <a href="{{ route('deals.show', $deal) }}" class="btn btn-outline-secondary ms-2">
                    キャンセル
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
