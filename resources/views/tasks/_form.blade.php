<div class="row g-3">
    {{-- タイトル --}}
    <div class="col-md-12">
        <label class="form-label">タイトル <span class="text-danger">*</span></label>
        <input type="text" name="title"
               class="form-control @error('title') is-invalid @enderror"
               value="{{ old('title', $task->title ?? '') }}"
               placeholder="例：提案書の作成">
        @error('title')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 優先度 --}}
    <div class="col-md-6">
        <label class="form-label">優先度 <span class="text-danger">*</span></label>
        <div class="d-flex gap-2 mt-1">
            @foreach(['高' => ['bg' => '#FEF2F2', 'color' => '#991B1B', 'border' => '#EF4444'],
                      '中' => ['bg' => '#FFF3E0', 'color' => '#E67E00', 'border' => '#FF8C00'],
                      '低' => ['bg' => '#F1F5F9', 'color' => '#475569', 'border' => '#94A3B8']] as $p => $style)
                <label class="priority-label">
                    <input type="radio" name="priority" value="{{ $p }}"
                           class="d-none priority-radio"
                           data-bg="{{ $style['bg'] }}"
                           data-color="{{ $style['color'] }}"
                           data-border="{{ $style['border'] }}"
                           {{ old('priority', $task->priority ?? '中') === $p ? 'checked' : '' }}>
                    <span class="priority-btn" style="
                        {{ old('priority', $task->priority ?? '中') === $p
                            ? "background-color:{$style['bg']}; color:{$style['color']}; border-color:{$style['border']}; font-weight:600;"
                            : '' }}">
                        {{ $p }}
                    </span>
                </label>
            @endforeach
        </div>
        @error('priority')
            <div class="text-danger" style="font-size:0.875rem">{{ $message }}</div>
        @enderror
    </div>

    {{-- ステータス --}}
    <div class="col-md-6">
        <label class="form-label">ステータス <span class="text-danger">*</span></label>
        <div class="d-flex gap-2 mt-1">
            @foreach(['未着手', '進行中', '完了'] as $s)
                <label class="status-label">
                    <input type="radio" name="status" value="{{ $s }}"
                           class="d-none status-radio"
                           {{ old('status', $task->status ?? '未着手') === $s ? 'checked' : '' }}>
                    <span class="status-btn">{{ $s }}</span>
                </label>
            @endforeach
        </div>
        @error('status')
            <div class="text-danger" style="font-size:0.875rem">{{ $message }}</div>
        @enderror
    </div>

    {{-- 期限日 --}}
    <div class="col-md-6">
        <label class="form-label">期限日</label>
        <input type="date" name="due_date"
               class="form-control @error('due_date') is-invalid @enderror"
               value="{{ old('due_date', isset($task->due_date) ? $task->due_date->format('Y-m-d') : '') }}">
        @error('due_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 顧客選択 --}}
    <div class="col-md-6">
        <label class="form-label">顧客</label>
        <select name="customer_id" id="customer_id"
                class="form-select @error('customer_id') is-invalid @enderror">
            <option value="">顧客を選択（任意）</option>
            @foreach($customers as $customer)
                <option value="{{ $customer->id }}"
                    {{ old('customer_id', $task->customer_id ?? $customerId ?? '') == $customer->id ? 'selected' : '' }}>
                    {{ $customer->company_name }}
                </option>
            @endforeach
        </select>
        @error('customer_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 商談選択 --}}
    <div class="col-md-12">
        <label class="form-label">関連商談</label>
        <select name="deal_id" id="deal_id"
                class="form-select @error('deal_id') is-invalid @enderror">
            <option value="">商談を選択（任意）</option>
            @foreach($deals as $deal)
                <option value="{{ $deal->id }}"
                    {{ old('deal_id', $task->deal_id ?? '') == $deal->id ? 'selected' : '' }}>
                    {{ $deal->title }}
                </option>
            @endforeach
        </select>
        @error('deal_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 詳細 --}}
    <div class="col-md-12">
        <label class="form-label">詳細</label>
        <textarea name="description" rows="4"
                  class="form-control @error('description') is-invalid @enderror"
                  placeholder="タスクの詳細を入力してください">{{ old('description', $task->description ?? '') }}</textarea>
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<style>
    .priority-btn, .status-btn {
        display: inline-flex;
        align-items: center;
        padding: 0.4rem 1rem;
        border: 1px solid var(--border);
        border-radius: 0.5rem;
        cursor: pointer;
        font-size: 0.875rem;
        color: var(--text-muted);
        background-color: #FFFFFF;
        transition: all 0.15s ease;
        user-select: none;
    }
    .priority-btn:hover, .status-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    .status-radio:checked + .status-btn {
        background-color: var(--primary);
        border-color: var(--primary);
        color: #1E293B;
        font-weight: 600;
    }
</style>

<script>
// 優先度ボタンのスタイル切り替え
document.querySelectorAll('.priority-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.priority-btn').forEach(btn => {
            btn.style.backgroundColor = '';
            btn.style.color = '';
            btn.style.borderColor = '';
            btn.style.fontWeight = '';
        });
        if (this.checked) {
            const btn = this.nextElementSibling;
            btn.style.backgroundColor = this.dataset.bg;
            btn.style.color = this.dataset.color;
            btn.style.borderColor = this.dataset.border;
            btn.style.fontWeight = '600';
        }
    });
});

// 顧客選択時に商談をAjaxで取得
document.getElementById('customer_id').addEventListener('change', function() {
    const customerId = this.value;
    const dealSelect = document.getElementById('deal_id');
    dealSelect.innerHTML = '<option value="">商談を選択（任意）</option>';

    if (customerId) {
        fetch(`/api/customer-data?customer_id=${customerId}`)
            .then(r => r.json())
            .then(data => {
                data.deals.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.id;
                    opt.textContent = d.title;
                    dealSelect.appendChild(opt);
                });
            });
    }
});
</script>
