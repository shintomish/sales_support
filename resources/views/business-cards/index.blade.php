@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>名刺管理</h2>
    </div>

    @if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
    @endif

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>画像</th>
                            <th>会社名</th>
                            <th>氏名</th>
                            <th>役職</th>
                            <th>連絡先</th>
                            <th>ステータス</th>
                            <th>登録日</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($cards as $card)
                        <tr>
                            <td>
                                @if($card->image_path)
                                <img src="{{ asset('storage/' . $card->image_path) }}"
                                    alt="名刺画像"
                                    style="height: 60px; width: 90px; object-fit: cover;"
                                    class="rounded">
                                @else
                                <div style="height: 60px; width: 90px; background-color: #f0f0f0;"
                                    class="rounded d-flex align-items-center justify-content-center">
                                    <small class="text-muted">画像なし</small>
                                </div>
                                @endif
                            </td>
                            <td>
                                <div>{{ $card->company_name ?? '-' }}</div>
                                @if($card->customer)
                                <small class="text-primary">
                                    <a href="{{ route('customers.show', $card->customer) }}">
                                        顧客ID: {{ $card->customer->id }}
                                    </a>
                                </small>
                                @endif
                            </td>
                            <td>
                                <div>{{ $card->person_name ?? '-' }}</div>
                                @if($card->contact)
                                <small class="text-success">
                                    <a href="{{ route('contacts.show', $card->contact) }}">
                                        担当者ID: {{ $card->contact->id }}
                                    </a>
                                </small>
                                @endif
                            </td>
                            <td>{{ $card->position ?? '-' }}</td>
                            <td>
                                @if($card->email)
                                <div>{{ $card->email }}</div>
                                @endif
                                @if($card->mobile)
                                <small class="text-muted">{{ $card->mobile }}</small>
                                @elseif($card->phone)
                                <small class="text-muted">{{ $card->phone }}</small>
                                @endif
                            </td>
                            <td>
                                @if($card->status === 'registered')
                                <span class="badge bg-success">登録済み</span>
                                @elseif($card->status === 'processed')
                                <span class="badge bg-info">処理済み</span>
                                @else
                                <span class="badge bg-secondary">{{ $card->status }}</span>
                                @endif
                            </td>
                            <td>{{ $card->created_at->format('Y/m/d') }}</td>
                            <td>
                                <a href="{{ route('business-cards.show', $card) }}" class="btn btn-sm btn-info">詳細</a>
                                <a href="{{ route('business-cards.edit', $card) }}" class="btn btn-sm btn-primary">編集</a>
                                <form action="{{ route('business-cards.destroy', $card) }}"
                                    method="POST"
                                    class="d-inline"
                                    onsubmit="return confirm('本当に削除しますか？');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">削除</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                名刺が登録されていません
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $cards->links() }}
    </div>
</div>
@endsection
