@extends('layouts.app')

@section('title', '顧客登録')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>顧客登録</h4>
    <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>一覧に戻る
    </a>
</div>
<div class="card">
    <div class="card-body">
        <form action="{{ route('customers.store') }}" method="POST">
            @csrf
            @include('customers._form')
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>登録する
                </button>
                <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary ms-2">
                    キャンセル
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
