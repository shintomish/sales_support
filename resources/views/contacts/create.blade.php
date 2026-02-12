@extends('layouts.app')

@section('title', '担当者登録')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-person-plus me-2" style="color: var(--primary)"></i>担当者登録</h4>
    </div>
    <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>一覧に戻る
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('contacts.store') }}" method="POST">
            @csrf
            @include('contacts._form', ['customerId' => $customerId ?? null])
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>登録する
                </button>
                <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary ms-2">
                    キャンセル
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
