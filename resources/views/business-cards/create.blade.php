@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>名刺アップロード</h2>
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

    @if(session('errors'))
        <div class="alert alert-warning">
            <strong>エラー詳細:</strong>
            <ul class="mb-0 mt-2">
                @foreach(session('errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">名刺画像を選択</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('business-cards.store') }}" method="POST" enctype="multipart/form-data" id="uploadForm">
                        @csrf

                        <!-- ドラッグ&ドロップエリア -->
                        <div id="dropArea" class="border border-2 border-dashed rounded p-5 text-center mb-4"
                             style="background-color: #f8f9fa; cursor: pointer;">
                            <i class="bi bi-cloud-upload" style="font-size: 3rem; color: #6c757d;"></i>
                            <p class="mt-3 mb-2">
                                <strong>ここに名刺画像をドラッグ&ドロップ</strong><br>
                                <span class="text-muted">または</span>
                            </p>
                            <label for="fileInput" class="btn btn-primary">
                                <i class="bi bi-file-earmark-image"></i> ファイルを選択
                            </label>
                            <input type="file"
                                   id="fileInput"
                                   name="images[]"
                                   multiple
                                   accept="image/jpeg,image/png,image/jpg"
                                   style="display: none;">
                            <p class="text-muted small mt-3 mb-0">
                                対応形式: JPEG, PNG, JPG（最大10MB、複数選択可）
                            </p>
                        </div>

                        <!-- プレビューエリア -->
                        <div id="previewArea" class="row g-3 mb-4" style="display: none;">
                            <!-- プレビュー画像がここに表示される -->
                        </div>

                        <!-- アップロードボタン -->
                        <div class="d-flex justify-content-between">
                            <button type="button" id="clearBtn" class="btn btn-outline-secondary" style="display: none;">
                                <i class="bi bi-x-circle"></i> クリア
                            </button>
                            <button type="submit" id="submitBtn" class="btn btn-primary btn-lg ms-auto" style="display: none;">
                                <i class="bi bi-upload"></i> <span id="fileCount">0</span>件の名刺をアップロード
                            </button>
                        </div>
                        <!-- プログレスバー（追加） -->
                        <div id="progressArea" style="display: none;" class="mt-4">
                            <p class="text-center mb-2" id="progressText">アップロード中...</p>
                            <div class="progress" style="height: 25px;">
                                <div id="progressBar"
                                    class="progress-bar progress-bar-striped progress-bar-animated"
                                    role="progressbar"
                                    style="width: 0%;">0%</div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 使い方ガイド -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> 使い方</h6>
                </div>
                <div class="card-body">
                    <ol class="mb-0">
                        <li>名刺の画像ファイルを選択またはドラッグ&ドロップ</li>
                        <li>複数枚を一度にアップロード可能</li>
                        <li>自動的にOCR処理が実行され、顧客・担当者が登録されます</li>
                        <li>処理完了後、名刺一覧画面で確認できます</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {

    const dropArea = document.getElementById('dropArea');
    const fileInput = document.getElementById('fileInput');
    const previewArea = document.getElementById('previewArea');
    const submitBtn = document.getElementById('submitBtn');
    const clearBtn = document.getElementById('clearBtn');
    const fileCount = document.getElementById('fileCount');

    let selectedFiles = [];

    // ドラッグ&ドロップイベント
    dropArea.addEventListener('click', (e) => {
        if (e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT') {
            fileInput.click();
        }
    });

    // ドラッグ関連はdocumentレベルでも防ぐ
    document.addEventListener('dragover', (e) => e.preventDefault());
    document.addEventListener('drop', (e) => e.preventDefault());

    dropArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropArea.style.backgroundColor = '#e9ecef';
    });

    dropArea.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropArea.style.backgroundColor = '#f8f9fa';
    });

    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropArea.style.backgroundColor = '#f8f9fa';
        handleFiles(e.dataTransfer.files);
    });

    // ファイル選択イベント
    fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
    });

    // クリアボタン
    clearBtn.addEventListener('click', () => {
        selectedFiles = [];
        fileInput.value = '';
        updatePreview();
    });

    // フォーム送信前にファイルをセット
    document.getElementById('uploadForm').addEventListener('submit', (e) => {
        e.preventDefault();

        if (selectedFiles.length === 0) {
            alert('ファイルを選択してください');
            return;
        }

        const dataTransfer = new DataTransfer();
        selectedFiles.forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;

        // UI切り替え
        submitBtn.disabled = true;
        clearBtn.disabled = true;
        document.getElementById('progressArea').style.display = 'block';

        // プログレスバーをXHRで送信
        const formData = new FormData(document.getElementById('uploadForm'));
        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                const bar = document.getElementById('progressBar');
                bar.style.width = percent + '%';
                bar.textContent = percent + '%';
                document.getElementById('progressText').textContent =
                    `アップロード中... ${percent}%`;
            }
        });

        xhr.upload.addEventListener('load', () => {
            const bar = document.getElementById('progressBar');
            bar.style.width = '100%';
            bar.textContent = '100%';
            document.getElementById('progressText').textContent =
                'OCR処理中... しばらくお待ちください';
            // アニメーションを維持してOCR中であることを示す
        });

        xhr.addEventListener('load', () => {
            if (xhr.status === 200 || xhr.status === 302) {
                window.location.href = "{{ route('business-cards.index') }}";
            } else {
                alert('アップロードに失敗しました（status: ' + xhr.status + '）');
                submitBtn.disabled = false;
                clearBtn.disabled = false;
                document.getElementById('progressArea').style.display = 'none';
            }
        });

        xhr.addEventListener('error', () => {
            alert('アップロードに失敗しました');
            submitBtn.disabled = false;
            clearBtn.disabled = false;
            document.getElementById('progressArea').style.display = 'none';
        });

        xhr.open('POST', document.getElementById('uploadForm').action);
        xhr.send(formData);
    });

    // ファイル処理
    function handleFiles(files) {
        const newFiles = Array.from(files).filter(file => {
            if (!file.type.match('image/(jpeg|png|jpg)')) {
                alert(`${file.name} は対応していない形式です`);
                return false;
            }
            if (file.size > 10 * 1024 * 1024) {
                alert(`${file.name} は10MBを超えています`);
                return false;
            }
            return true;
        });

        selectedFiles = [...selectedFiles, ...newFiles];
        updatePreview();
    }

    // プレビュー更新
    function updatePreview() {
        previewArea.innerHTML = '';

        if (selectedFiles.length === 0) {
            previewArea.style.display = 'none';
            submitBtn.style.display = 'none';
            clearBtn.style.display = 'none';
            return;
        }

        previewArea.style.display = 'flex';
        submitBtn.style.display = 'block';
        clearBtn.style.display = 'block';
        fileCount.textContent = selectedFiles.length;

        selectedFiles.forEach((file, index) => {
            const col = document.createElement('div');
            col.className = 'col-md-3';

            const card = document.createElement('div');
            card.className = 'card';

            const reader = new FileReader();
            reader.onload = (e) => {
                card.innerHTML = `
                    <img src="${e.target.result}" class="card-img-top" style="height: 150px; object-fit: cover;">
                    <div class="card-body p-2">
                        <small class="text-muted d-block text-truncate">${file.name}</small>
                        <small class="text-muted">${(file.size / 1024).toFixed(1)} KB</small>
                        <button type="button" class="btn btn-sm btn-danger w-100 mt-2" onclick="removeFile(${index})">
                            <i class="bi bi-trash"></i> 削除
                        </button>
                    </div>
                `;
            };
            reader.readAsDataURL(file);

            col.appendChild(card);
            previewArea.appendChild(col);
        });
    }

    // ファイル削除
    function removeFile(index) {
        selectedFiles.splice(index, 1);
        updatePreview();
    }
}
</script>
@endpush
@endsection
