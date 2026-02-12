@extends('layouts.app')

@section('title', '顧客詳細')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-building me-2" style="color: var(--primary)"></i>
            {{ $customer->company_name }}
        </h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">
            登録日: {{ $customer->created_at->format('Y年m月d日') }}
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('customers.edit', $customer) }}" class="btn btn-warning">
            <i class="bi bi-pencil me-1"></i>編集
        </a>
        <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>一覧に戻る
        </a>
    </div>
</div>

<!-- 基本情報 -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-info-circle me-2" style="color: var(--primary)"></i>基本情報
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="info-label">会社名</div>
                <div class="info-value">{{ $customer->company_name }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">業種</div>
                <div class="info-value">
                    @if($customer->industry)
                        <span class="badge"
                              style="background-color: var(--primary-light);
                                     color: var(--primary); font-size:0.85rem">
                            {{ $customer->industry }}
                        </span>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">従業員数</div>
                <div class="info-value">
                    {{ $customer->employee_count ? number_format($customer->employee_count) . '名' : '-' }}
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">電話番号</div>
                <div class="info-value">{{ $customer->phone ?? '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">住所</div>
                <div class="info-value">{{ $customer->address ?? '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">ウェブサイト</div>
                <div class="info-value">
                    @if($customer->website)
                        <a href="{{ $customer->website }}" target="_blank"
                           style="color: var(--primary)">
                            <i class="bi bi-box-arrow-up-right me-1"></i>
                            {{ $customer->website }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            @if($customer->notes)
                <div class="col-md-12">
                    <div class="info-label">備考</div>
                    <div class="info-value">{{ $customer->notes }}</div>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- 担当者一覧 -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-person me-2" style="color: var(--success)"></i>
            担当者
            <span class="badge ms-1"
                  style="background-color:#ECFDF5; color: var(--success)">
                {{ $customer->contacts->count() }}名
            </span>
        </span>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
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
                        <td class="fw-bold">{{ $contact->name }}</td>
                        <td>{{ $contact->department ?? '-' }}</td>
                        <td>{{ $contact->position ?? '-' }}</td>
                        <td>{{ $contact->email ?? '-' }}</td>
                        <td>{{ $contact->phone ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            担当者が登録されていません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- 商談一覧 -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-briefcase me-2" style="color: var(--warning)"></i>
            商談
            <span class="badge ms-1"
                  style="background-color:#FFFBEB; color: var(--warning)">
                {{ $customer->deals->count() }}件
            </span>
        </span>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
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
                        <td class="fw-bold">{{ $deal->title }}</td>
                        <td>¥{{ number_format($deal->amount) }}</td>
                        <td>
                            @php
                                $statusColor = match($deal->status) {
                                    '成約' => ['bg' => '#ECFDF5', 'text' => '#065F46'],
                                    '失注' => ['bg' => '#FEF2F2', 'text' => '#991B1B'],
                                    '交渉' => ['bg' => '#FFFBEB', 'text' => '#92400E'],
                                    '提案' => ['bg' => '#EFF6FF', 'text' => '#1E40AF'],
                                    default => ['bg' => '#F1F5F9', 'text' => '#475569'],
                                };
                            @endphp
                            <span class="badge"
                                  style="background-color:{{ $statusColor['bg'] }};
                                         color:{{ $statusColor['text'] }}">
                                {{ $deal->status }}
                            </span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress" style="width:60px; height:6px; border-radius:3px">
                                    <div class="progress-bar"
                                         style="width:{{ $deal->probability }}%;
                                                background-color: var(--primary)">
                                    </div>
                                </div>
                                <span style="font-size:0.8rem">{{ $deal->probability }}%</span>
                            </div>
                        </td>
                        <td>{{ $deal->expected_close_date ? $deal->expected_close_date->format('Y/m/d') : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            商談が登録されていません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
