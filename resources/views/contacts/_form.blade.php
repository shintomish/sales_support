<div class="row g-3">
    {{-- 顧客選択 --}}
    <div class="col-md-6">
        <label class="form-label">顧客 <span class="text-danger">*</span></label>
        <select name="customer_id"
                class="form-select @error('customer_id') is-invalid @enderror">
            <option value="">顧客を選択してください</option>
            @foreach($customers as $customer)
                <option value="{{ $customer->id }}"
                    {{ old('customer_id', $contact->customer_id ?? $customerId ?? '') == $customer->id ? 'selected' : '' }}>
                    {{ $customer->company_name }}
                </option>
            @endforeach
        </select>
        @error('customer_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 氏名 --}}
    <div class="col-md-6">
        <label class="form-label">氏名 <span class="text-danger">*</span></label>
        <input type="text" name="name"
               class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $contact->name ?? '') }}"
               placeholder="例：山田 太郎">
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 部署 --}}
    <div class="col-md-6">
        <label class="form-label">部署</label>
        <input type="text" name="department"
               class="form-control @error('department') is-invalid @enderror"
               value="{{ old('department', $contact->department ?? '') }}"
               placeholder="例：営業部">
        @error('department')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 役職 --}}
    <div class="col-md-6">
        <label class="form-label">役職</label>
        <input type="text" name="position"
               class="form-control @error('position') is-invalid @enderror"
               value="{{ old('position', $contact->position ?? '') }}"
               placeholder="例：部長">
        @error('position')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- メールアドレス --}}
    <div class="col-md-6">
        <label class="form-label">メールアドレス</label>
        <div class="input-group">
            <span class="input-group-text">
                <i class="bi bi-envelope"></i>
            </span>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email', $contact->email ?? '') }}"
                   placeholder="例：yamada@example.com">
        </div>
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 電話番号 --}}
    <div class="col-md-6">
        <label class="form-label">電話番号</label>
        <div class="input-group">
            <span class="input-group-text">
                <i class="bi bi-telephone"></i>
            </span>
            <input type="text" name="phone"
                   class="form-control @error('phone') is-invalid @enderror"
                   value="{{ old('phone', $contact->phone ?? '') }}"
                   placeholder="例：03-1234-5678">
        </div>
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- 備考 --}}
    <div class="col-md-12">
        <label class="form-label">備考</label>
        <textarea name="notes" rows="3"
                  class="form-control @error('notes') is-invalid @enderror"
                  placeholder="備考を入力してください">{{ old('notes', $contact->notes ?? '') }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
