@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>名刺情報編集</h2>
        <a href="{{ route('business-cards.index') }}" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> 一覧に戻る
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('business-cards.update', $businessCard) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="row">
            <!-- 名刺画像プレビュー -->
            @if($businessCard->image_path)
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">名刺画像</h5>
                        </div>
                        <div class="card-body">
                            <img src="{{ asset('storage/' . $businessCard->image_path) }}"
                                 alt="名刺画像"
                                 class="img-fluid rounded shadow"
                                 style="max-height: 300px; width: 100%; object-fit: contain;">
                        </div>
                    </div>
                </div>
            @endif

            <!-- 基本情報 -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">基本情報</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">会社名</label>
                            <input type="text"
                                   name="company_name"
                                   value="{{ old('company_name', $businessCard->company_name) }}"
                                   class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">氏名</label>
                            <input type="text"
                                   name="person_name"
                                   value="{{ old('person_name', $businessCard->person_name) }}"
                                   class="form-control">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">部署</label>
                                <input type="text"
                                       name="department"
                                       value="{{ old('department', $businessCard->department) }}"
                                       class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">役職</label>
                                <input type="text"
                                       name="position"
                                       value="{{ old('position', $businessCard->position) }}"
                                       class="form-control">
                            </div>
                        </div>
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
                        <div class="mb-3">
                            <label class="form-label">郵便番号</label>
                            <input type="text"
                                   name="postal_code"
                                   value="{{ old('postal_code', $businessCard->postal_code) }}"
                                   class="form-control"
                                   placeholder="000-0000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">住所</label>
                            <input type="text"
                                   name="address"
                                   value="{{ old('address', $businessCard->address) }}"
                                   class="form-control">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">電話</label>
                                <input type="text"
                                       name="phone"
                                       value="{{ old('phone', $businessCard->phone) }}"
                                       class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">携帯</label>
                                <input type="text"
                                       name="mobile"
                                       value="{{ old('mobile', $businessCard->mobile) }}"
                                       class="form-control">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">FAX</label>
                            <input type="text"
                                   name="fax"
                                   value="{{ old('fax', $businessCard->fax) }}"
                                   class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">メールアドレス</label>
                            <input type="email"
                                   name="email"
                                   value="{{ old('email', $businessCard->email) }}"
                                   class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ウェブサイト</label>
                            <input type="url"
                                   name="website"
                                   value="{{ old('website', $businessCard->website) }}"
                                   class="form-control"
                                   placeholder="https://">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ボタン -->
        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ route('business-cards.show', $businessCard) }}" class="btn btn-secondary">
                キャンセル
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> 更新する
            </button>
        </div>
    </form>
</div>
@endsection
