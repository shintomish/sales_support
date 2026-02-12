@extends('layouts.app')

@section('title', '顧客編集')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>顧客編集</h4>
    <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>詳細に戻る
    </a>
</div>
<div class="card">
    <div class="card-body">
        <form action="{{ route('customers.update', $customer) }}" method="POST">
            @csrf
            @method('PUT')
            @include('customers._form')
            <div class="mt-4">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-1"></i>更新する
                </button>
                <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary ms-2">
                    キャンセル
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
