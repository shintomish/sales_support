<div class="row g-3">
    {{-- 活動日 --}}
    <div class="col-md-6">
        <label class="form-label">活動日 <span class="text-danger">*</span></label>
        <input type="date" name="activity_date"
               class="form-control @error('activity_date') is-invalid @enderror"
               value="{{ old('activity_date', isset($activity->activity_date)
                   ? $activity->activity_date->format('Y-m-d')
                   : now()->format('Y-m-d')) }}">
        @error('activity_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 活動種別 --}}
    <div class="col-md-6">
        <label class="form-label">活動種別 <span class="text-danger">*</span></label>
        <div class="d-flex gap-2 flex-wrap mt-1">
            @foreach(['訪問', '電話', 'メール', 'その他'] as $type)
                @php
                    $typeIcons = [
                        '訪問'  => 'bi-person-walking',
                        '電話'  => 'bi-telephone',
                        'メール' => 'bi-envelope',
                        'その他' => 'bi-three-dots',
                    ];
                @endphp
                <label class="type-label">
                    <input type="radio" name="type" value="{{ $type }}"
                           class="d-none type-radio"
                           {{ old('type', $activity->type ?? '訪問') === $type ? 'checked' : '' }}>
                    <span class="type-btn">
                        <i class="bi {{ $typeIcons[$type] }} me-1"></i>{{ $type }}
                    </span>
                </label>
            @endforeach
        </div>
        @error('type')
            <div class="text-danger" style="font-size:0.875rem">{{ $message }}</div>
        @enderror
    </div>

    {{-- 顧客選択 --}}
    <div class="col-md-6">
        <label class="form-label">顧客 <span class="text-danger">*</span></label>
        <select name="customer_id" id="customer_id"
                class="form-select @error('customer_id') is-invalid @enderror">
            <option value="">顧客を選択してください</option>
            @foreach($customers as $customer)
                <option value="{{ $customer->id }}"
                    {{ old('customer_id', $activity->customer_id ?? $customerId ?? '') == $customer->id ? 'selected' : '' }}>
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
                    {{ old('contact_id', $activity->contact_id ?? '') == $contact->id ? 'selected' : '' }}>
                    {{ $contact->name }}{{ $contact->position ? '（' . $contact->position . '）' : '' }}
                </option>
            @endforeach
        </select>
        @error('contact_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 商談選択 --}}
    <div class="col-md-12">
        <label class="form-label">関連商談</label>
        <select name="deal_id" id="deal_id"
                class="form-select @error('deal_id') is-invalid @enderror">
            <option value="">商談を選択してください（任意）</option>
            @foreach($deals as $deal)
                <option value="{{ $deal->id }}"
                    {{ old('deal_id', $activity->deal_id ?? '') == $deal->id ? 'selected' : '' }}>
                    {{ $deal->title }}
                </option>
            @endforeach
        </select>
        @error('deal_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 件名 --}}
    <div class="col-md-12">
        <label class="form-label">件名 <span class="text-danger">*</span></label>
        <input type="text" name="subject"
               class="form-control @error('subject') is-invalid @enderror"
               value="{{ old('subject', $activity->subject ?? '') }}"
               placeholder="例：新システム提案のヒアリング">
        @error('subject')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 内容 --}}
    <div class="col-md-12">
        <label class="form-label">内容</label>
        <textarea name="content" rows="5"
                  class="form-control @error('content') is-invalid @enderror"
                  placeholder="活動内容の詳細を入力してください">{{ old('content', $activity->content ?? '') }}</textarea>
        @error('content')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<style>
    .type-btn {
        display: inline-flex;
        align-items: center;
        padding: 0.4rem 0.875rem;
        border: 1px solid var(--border);
        border-radius: 0.5rem;
        cursor: pointer;
        font-size: 0.875rem;
        color: var(--text-muted);
        background-color: #FFFFFF;
        transition: all 0.15s ease;
        user-select: none;
    }
    .type-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    .type-radio:checked + .type-btn {
        background-color: var(--primary);
        border-color: var(--primary);
        color: #1E293B;
        font-weight: 600;
    }
</style>

<script>
document.getElementById('customer_id').addEventListener('change', function() {
    const customerId = this.value;
    const contactSelect = document.getElementById('contact_id');
    const dealSelect    = document.getElementById('deal_id');

    contactSelect.innerHTML = '<option value="">担当者を選択してください</option>';
    dealSelect.innerHTML    = '<option value="">商談を選択してください（任意）</option>';

    if (customerId) {
        fetch(`/api/customer-data?customer_id=${customerId}`)
            .then(r => r.json())
            .then(data => {
                data.contacts.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name + (c.position ? `（${c.position}）` : '');
                    contactSelect.appendChild(opt);
                });
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
