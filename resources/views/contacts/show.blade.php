@extends('layouts.app')

@section('title', '担当者詳細')

@section('content')
<div class="page-header">
    <div>
        <h4><i class="bi bi-person me-2" style="color: var(--primary)"></i>
            {{ $contact->name }}
        </h4>
        <p class="text-muted mb-0" style="font-size:0.8rem">
            <a href="{{ route('customers.show', $contact->customer) }}"
               class="text-decoration-none" style="color: var(--text-muted)">
                <i class="bi bi-building me-1"></i>{{ $contact->customer->company_name ?? '-' }}
            </a>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('contacts.edit', $contact) }}" class="btn btn-warning">
            <i class="bi bi-pencil me-1"></i>編集
        </a>
        <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary">
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
                <div class="info-label">氏名</div>
                <div class="info-value fw-bold">{{ $contact->name }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">会社名</div>
                <div class="info-value">
                    <a href="{{ route('customers.show', $contact->customer) }}"
                       class="text-decoration-none" style="color: var(--primary)">
                        {{ $contact->customer->company_name ?? '-' }}
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">部署</div>
                <div class="info-value">{{ $contact->department ?? '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">役職</div>
                <div class="info-value">
                    @if($contact->position)
                        <span class="badge"
                              style="background-color: var(--primary-light);
                                     color: var(--primary); font-size:0.85rem">
                            {{ $contact->position }}
                        </span>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">メールアドレス</div>
                <div class="info-value">
                    @if($contact->email)
                        <a href="mailto:{{ $contact->email }}"
                           style="color: var(--primary)">
                            <i class="bi bi-envelope me-1"></i>{{ $contact->email }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">電話番号</div>
                <div class="info-value">
                    @if($contact->phone)
                        <a href="tel:{{ $contact->phone }}"
                           style="color: var(--primary)">
                            <i class="bi bi-telephone me-1"></i>{{ $contact->phone }}
                        </a>
                    @else
                        -
                    @endif
                </div>
            </div>
            @if($contact->notes)
                <div class="col-md-12">
                    <div class="info-label">備考</div>
                    <div class="info-value">{{ $contact->notes }}</div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- 関連商談 --}}
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-briefcase me-2" style="color: var(--primary)"></i>
        関連商談
        <span class="badge ms-1"
              style="background-color: var(--primary-light); color: var(--primary)">
            {{ $contact->deals->count() }}件
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
                @forelse($contact->deals as $deal)
                    @php
                        $statusColor = match($deal->status) {
                            '成約' => ['bg' => '#ECFDF5', 'text' => '#065F46'],
                            '失注' => ['bg' => '#FEF2F2', 'text' => '#991B1B'],
                            '交渉' => ['bg' => '#FFF3E0', 'text' => '#E67E00'],
                            '提案' => ['bg' => '#EFF6FF', 'text' => '#1D4ED8'],
                            default => ['bg' => '#F1F5F9', 'text' => '#475569'],
                        };
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('deals.show', $deal) }}"
                               class="text-decoration-none fw-bold"
                               style="color: var(--primary)">
                                {{ $deal->title }}
                            </a>
                        </td>
                        <td class="fw-bold">¥{{ number_format($deal->amount) }}</td>
                        <td>
                            <span class="badge"
                                  style="background-color:{{ $statusColor['bg'] }};
                                         color:{{ $statusColor['text'] }}">
                                {{ $deal->status }}
                            </span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress"
                                     style="width:60px; height:6px; border-radius:3px; background:#F1F5F9">
                                    <div class="progress-bar"
                                         style="width:{{ $deal->probability }}%;
                                                background-color: var(--primary)">
                                    </div>
                                </div>
                                <span style="font-size:0.8rem">{{ $deal->probability }}%</span>
                            </div>
                        </td>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ $deal->expected_close_date
                                ? $deal->expected_close_date->format('Y/m/d')
                                : '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            関連する商談がありません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- 活動履歴 --}}
<div class="card">
    <div class="card-header">
        <i class="bi bi-clock-history me-2" style="color: var(--primary)"></i>
        活動履歴
        <span class="badge ms-1"
              style="background-color: var(--primary-light); color: var(--primary)">
            {{ $contact->activities->count() }}件
        </span>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>活動日</th>
                    <th>種別</th>
                    <th>件名</th>
                    <th>内容</th>
                </tr>
            </thead>
            <tbody>
                @forelse($contact->activities as $activity)
                    <tr>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ $activity->activity_date->format('Y/m/d') }}
                        </td>
                        <td>
                            <span class="badge"
                                  style="background-color:#F1F5F9; color:#475569">
                                {{ $activity->type }}
                            </span>
                        </td>
                        <td class="fw-bold">{{ $activity->subject }}</td>
                        <td style="font-size:0.85rem; color:var(--text-muted)">
                            {{ Str::limit($activity->content, 50) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            活動履歴が登録されていません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
