<div class="row g-3">
    {{-- 顧客選択 --}}
    <div class="col-md-6">
        <label class="form-label">顧客 <span class="text-danger">*</span></label>
        <select name="customer_id" id="customer_id"
                class="form-select @error('customer_id') is-invalid @enderror">
            <option value="">顧客を選択してください</option>
            @foreach($customers as $customer)
                <option value="{{ $customer->id }}"
                    {{ old('customer_id', $deal->customer_id ?? '') == $customer->id ? 'selected' : '' }}>
                    {{ $customer->company_name }}
                </option>
            @endforeach
        </select>
        @error('customer_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 担当者選択 --}}
    <div class="col-md-6">
        <label class="form-label">担当者</label>
        <select name="contact_id" id="contact_id"
                class="form-select @error('contact_id') is-invalid @enderror">
            <option value="">担当者を選択してください</option>
            @foreach($contacts as $contact)
                <option value="{{ $contact->id }}"
                    {{ old('contact_id', $deal->contact_id ?? '') == $contact->id ? 'selected' : '' }}>
                    {{ $contact->name }}
                    {{ $contact->position ? '（' . $contact->position . '）' : '' }}
                </option>
            @endforeach
        </select>
        @error('contact_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 商談名 --}}
    <div class="col-md-12">
        <label class="form-label">商談名 <span class="text-danger">*</span></label>
        <input type="text" name="title"
               class="form-control @error('title') is-invalid @enderror"
               value="{{ old('title', $deal->title ?? '') }}"
               placeholder="例：新システム導入案件">
        @error('title')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 予定金額 --}}
    <div class="col-md-6">
        <label class="form-label">予定金額 <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text">¥</span>
            <input type="number" name="amount"
                   class="form-control @error('amount') is-invalid @enderror"
                   value="{{ old('amount', $deal->amount ?? '') }}"
                   placeholder="例：5000000" min="0">
        </div>
        @error('amount')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- ステータス --}}
    <div class="col-md-6">
        <label class="form-label">ステータス <span class="text-danger">*</span></label>
        <select name="status" class="form-select @error('status') is-invalid @enderror">
            @foreach(['新規', '提案', '交渉', '成約', '失注'] as $status)
                <option value="{{ $status }}"
                    {{ old('status', $deal->status ?? '新規') === $status ? 'selected' : '' }}>
                    {{ $status }}
                </option>
            @endforeach
        </select>
        @error('status')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 成約確度 --}}
    <div class="col-md-6">
        <label class="form-label">
            成約確度: <span id="probability-value" class="fw-bold" style="color: var(--primary)">
                {{ old('probability', $deal->probability ?? 0) }}%
            </span>
        </label>
        <input type="range" name="probability" id="probability"
               class="form-range"
               min="0" max="100" step="10"
               value="{{ old('probability', $deal->probability ?? 0) }}"
               oninput="document.getElementById('probability-value').textContent = this.value + '%'">
        <div class="d-flex justify-content-between" style="font-size:0.7rem; color:var(--text-muted)">
            <span>0%</span><span>50%</span><span>100%</span>
        </div>
    </div>

    {{-- 予定成約日 --}}
    <div class="col-md-6">
        <label class="form-label">予定成約日</label>
        <input type="date" name="expected_close_date"
               class="form-control @error('expected_close_date') is-invalid @enderror"
               value="{{ old('expected_close_date', isset($deal->expected_close_date) ? $deal->expected_close_date->format('Y-m-d') : '') }}">
        @error('expected_close_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 実際の成約日 --}}
    <div class="col-md-6">
        <label class="form-label">実際の成約日</label>
        <input type="date" name="actual_close_date"
               class="form-control @error('actual_close_date') is-invalid @enderror"
               value="{{ old('actual_close_date', isset($deal->actual_close_date) ? $deal->actual_close_date->format('Y-m-d') : '') }}">
        @error('actual_close_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 備考 --}}
    <div class="col-md-12">
        <label class="form-label">備考</label>
        <textarea name="notes" rows="4"
                  class="form-control @error('notes') is-invalid @enderror"
                  placeholder="備考を入力してください">{{ old('notes', $deal->notes ?? '') }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

{{-- 顧客選択時に担当者をAjaxで取得 --}}
<script>
document.getElementById('customer_id').addEventListener('change', function() {
    const customerId = this.value;
    const contactSelect = document.getElementById('contact_id');

    contactSelect.innerHTML = '<option value="">担当者を選択してください</option>';

    if (customerId) {
        fetch(`/api/contacts?customer_id=${customerId}`)
            .then(response => response.json())
            .then(contacts => {
                contacts.forEach(contact => {
                    const option = document.createElement('option');
                    option.value = contact.id;
                    option.textContent = contact.name + (contact.position ? `（${contact.position}）` : '');
                    contactSelect.appendChild(option);
                });
            });
    }
});
</script>
