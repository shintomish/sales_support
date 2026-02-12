@extends('layouts.app')

@section('title', '顧客詳細')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-building me-2"></i>{{ $customer->company_name }}</h4>
    <div>
        <a href="{{ route('customers.edit', $customer) }}" class="btn btn-warning me-2">
            <i class="bi bi-pencil me-1"></i>編集
        </a>
        <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>一覧に戻る
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-info-circle me-2"></i>基本情報
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="text-muted small">会社名</label>
                <p class="fw-bold">{{ $customer->company_name }}</p>
            </div>
            <div class="col-md-6">
                <label class="text-muted small">業種</label>
                <p>{{ $customer->industry ?? '-' }}</p>
            </div>
            <div class="col-md-6">
                <label class="text-muted small">従業員数</label>
                <p>{{ $customer->employee_count ? number_format($customer->employee_count) . '名' : '-' }}</p>
            </div>
            <div class="col-md-6">
                <label class="text-muted small">電話番号</label>
                <p>{{ $customer->phone ?? '-' }}</p>
            </div>
            <div class="col-md-6">
                <label class="text-muted small">住所</label>
                <p>{{ $customer->address ?? '-' }}</p>
            </div>
            <div class="col-md-6">
                <label class="text-muted small">ウェブサイト</label>
                <p>
                    @if($customer->website)
                        <a href="{{ $customer->website }}" target="_blank">{{ $customer->website }}</a>
                    @else
                        -
                    @endif
                </p>
            </div>
            <div class="col-md-12">
                <label class="text-muted small">備考</label>
                <p>{{ $customer->notes ?? '-' }}</p>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-person me-2"></i>担当者 ({{ $customer->contacts->count() }}名)
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>氏名</th>
                    <th>部署</th>
                    <th>役職</th>
                    <th>メール</th>
                    <th>電話番号</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customer->contacts as $contact)
                    <tr>
                        <td>{{ $contact->name }}</td>
                        <td>{{ $contact->department ?? '-' }}</td>
                        <td>{{ $contact->position ?? '-' }}</td>
                        <td>{{ $contact->email ?? '-' }}</td>
                        <td>{{ $contact->phone ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">
                            担当者が登録されていません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-briefcase me-2"></i>商談 ({{ $customer->deals->count() }}件)
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>商談名</th>
                    <th>金額</th>
                    <th>ステータス</th>
                    <th>成約確度</th>
                    <th>予定成約日</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customer->deals as $deal)
                    <tr>
                        <td>{{ $deal->title }}</td>
                        <td>¥{{ number_format($deal->amount) }}</td>
                        <td>
                            <span class="badge
                                @if($deal->status === '成約') bg-success
                                @elseif($deal->status === '失注') bg-danger
                                @elseif($deal->status === '交渉') bg-warning text-dark
                                @elseif($deal->status === '提案') bg-info text-dark
                                @else bg-secondary
                                @endif">
                                {{ $deal->status }}
                            </span>
                        </td>
                        <td>{{ $deal->probability }}%</td>
                        <td>{{ $deal->expected_close_date ? $deal->expected_close_date->format('Y/m/d') : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">
                            商談が登録されていません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
