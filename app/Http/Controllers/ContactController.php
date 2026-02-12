<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Customer;
use App\Http\Requests\ContactRequest;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    // 担当者一覧
    public function index(Request $request)
    {
        $query = Contact::with('customer');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('department', 'like', '%' . $request->search . '%')
                  ->orWhere('position', 'like', '%' . $request->search . '%')
                  ->orWhereHas('customer', function($q) use ($request) {
                      $q->where('company_name', 'like', '%' . $request->search . '%');
                  });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $contacts  = $query->orderBy('created_at', 'desc')->paginate(10);
        $customers = Customer::orderBy('company_name')->get();

        return view('contacts.index', compact('contacts', 'customers'));
    }

    // 担当者詳細
    public function show(Contact $contact)
    {
        $contact->load(['customer', 'deals', 'activities']);
        return view('contacts.show', compact('contact'));
    }

    // 担当者登録フォーム
    public function create(Request $request)
    {
        $customers  = Customer::orderBy('company_name')->get();
        $customerId = $request->customer_id;
        return view('contacts.create', compact('customers', 'customerId'));
    }

    // 担当者登録処理
    public function store(ContactRequest $request)
    {
        Contact::create($request->validated());

        return redirect()->route('contacts.index')
                         ->with('success', '担当者を登録しました。');
    }

    // 担当者編集フォーム
    public function edit(Contact $contact)
    {
        $customers = Customer::orderBy('company_name')->get();
        return view('contacts.edit', compact('contact', 'customers'));
    }

    // 担当者更新処理
    public function update(ContactRequest $request, Contact $contact)
    {
        $contact->update($request->validated());

        return redirect()->route('contacts.show', $contact)
                         ->with('success', '担当者情報を更新しました。');
    }

    // 担当者削除処理
    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()->route('contacts.index')
                         ->with('success', '担当者を削除しました。');
    }
}