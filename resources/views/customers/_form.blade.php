<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-bold">会社名 <span class="text-danger">*</span></label>
        <input type="text" name="company_name"
               class="form-control @error('company_name') is-invalid @enderror"
               value="{{ old('company_name', $customer->company_name ?? '') }}"
               placeholder="例：株式会社サンプル">
        @error('company_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">業種</label>
        <input type="text" name="industry"
               class="form-control @error('industry') is-invalid @enderror"
               value="{{ old('industry', $customer->industry ?? '') }}"
               placeholder="例：製造業、IT・通信">
        @error('industry')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">従業員数</label>
        <input type="number" name="employee_count"
               class="form-control @error('employee_count') is-invalid @enderror"
               value="{{ old('employee_count', $customer->employee_count ?? '') }}"
               placeholder="例：100">
        @error('employee_count')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">電話番号</label>
        <input type="text" name="phone"
               class="form-control @error('phone') is-invalid @enderror"
               value="{{ old('phone', $customer->phone ?? '') }}"
               placeholder="例：03-1234-5678">
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-12">
        <label class="form-label fw-bold">住所</label>
        <input type="text" name="address"
               class="form-control @error('address') is-invalid @enderror"
               value="{{ old('address', $customer->address ?? '') }}"
               placeholder="例：東京都千代田区丸の内1-1-1">
        @error('address')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-12">
        <label class="form-label fw-bold">ウェブサイト</label>
        <input type="url" name="website"
               class="form-control @error('website') is-invalid @enderror"
               value="{{ old('website', $customer->website ?? '') }}"
               placeholder="例：https://example.com">
        @error('website')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-12">
        <label class="form-label fw-bold">備考</label>
        <textarea name="notes" rows="4"
                  class="form-control @error('notes') is-invalid @enderror"
                  placeholder="備考を入力してください">{{ old('notes', $customer->notes ?? '') }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
