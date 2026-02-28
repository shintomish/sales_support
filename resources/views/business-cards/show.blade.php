@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>名刺詳細</h2>
        <div>
            <a href="{{ route('business-cards.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> 一覧に戻る
            </a>
            <a href="{{ route('business-cards.edit', $businessCard) }}" class="btn btn-primary">
                <i class="bi bi-pencil"></i> 編集
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="row">
        <!-- 名刺画像 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">名刺画像</h5>
                </div>
                <div class="card-body">
                    @if($businessCard->image_path)
                        <img src="{{ asset('storage/' . $businessCard->image_path) }}"
                             alt="名刺画像"
                             class="img-fluid rounded shadow cursor-pointer"
                             style="max-height: 400px; width: 100%; object-fit: contain;"
                             onclick="openImageModal(this.src)">
                    @else
                        <div class="bg-light p-5 text-center rounded">
                            <span class="text-muted">画像がありません</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- 基本情報 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">基本情報</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 120px;">会社名</th>
                            <td>{{ $businessCard->company_name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>氏名</th>
                            <td>{{ $businessCard->person_name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>部署</th>
                            <td>{{ $businessCard->department ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>役職</th>
                            <td>{{ $businessCard->position ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>ステータス</th>
                            <td>
                                @if($businessCard->status === 'registered')
                                    <span class="badge bg-success">登録済み</span>
                                @elseif($businessCard->status === 'processed')
                                    <span class="badge bg-info">処理済み</span>
                                @else
                                    <span class="badge bg-secondary">{{ $businessCard->status }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>登録日時</th>
                            <td>{{ $businessCard->created_at->format('Y年m月d日 H:i') }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- 連絡先情報 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">連絡先情報</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 120px;">郵便番号</th>
                            <td>{{ $businessCard->postal_code ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>住所</th>
                            <td>{{ $businessCard->address ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>電話</th>
                            <td>{{ $businessCard->phone ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>携帯</th>
                            <td>{{ $businessCard->mobile ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>FAX</th>
                            <td>{{ $businessCard->fax ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>メール</th>
                            <td>{{ $businessCard->email ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th>ウェブサイト</th>
                            <td>
                                @if($businessCard->website)
                                    <a href="{{ $businessCard->website }}" target="_blank">{{ $businessCard->website }}</a>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- 紐付け情報 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">紐付け情報</h5>
                </div>
                <div class="card-body">
                    @if($businessCard->customer)
                        <div class="mb-3 p-3 border-start border-primary border-4 bg-light">
                            <h6 class="text-muted mb-1">顧客</h6>
                            <div class="fw-bold">{{ $businessCard->customer->company_name }}</div>
                            <a href="{{ route('customers.show', $businessCard->customer) }}" class="btn btn-sm btn-outline-primary mt-2">
                                顧客詳細を見る <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    @else
                        <div class="text-muted">顧客に紐付けられていません</div>
                    @endif

                    @if($businessCard->contact)
                        <div class="p-3 border-start border-success border-4 bg-light">
                            <h6 class="text-muted mb-1">担当者</h6>
                            <div class="fw-bold">{{ $businessCard->contact->name }}</div>
                            <a href="{{ route('contacts.show', $businessCard->contact) }}" class="btn btn-sm btn-outline-success mt-2">
                                担当者詳細を見る <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    @else
                        <div class="text-muted">担当者に紐付けられていません</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- OCRテキスト -->
    @if($businessCard->ocr_text)
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">OCR抽出テキスト</h5>
            </div>
            <div class="card-body">
                <pre class="bg-light p-3 rounded" style="white-space: pre-wrap;">{{ $businessCard->ocr_text }}</pre>
            </div>
        </div>
    @endif
</div>

<!-- 画像拡大モーダル -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-0">
                <img id="modalImage" src="" alt="拡大画像" class="img-fluid w-100">
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openImageModal(src) {
    document.getElementById('modalImage').src = src;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}
</script>
@endpush
@endsection
