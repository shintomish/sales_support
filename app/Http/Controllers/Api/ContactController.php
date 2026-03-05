<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Http\Resources\ContactResource;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $contacts = Contact::with('customer')
            ->when($request->search, fn($q, $s) =>
                $q->where('name', 'like', "%{$s}%")
                ->orWhere('department', 'like', "%{$s}%")
                ->orWhere('position', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->customer_id, fn($q, $id) =>
                $q->where('customer_id', $id)
            )
            ->paginate(20);
        return ContactResource::collection($contacts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'name'        => 'required|string|max:255',
            'department'  => 'nullable|string|max:100', // ★ 追加
            'position'    => 'nullable|string|max:100',
            'email'       => 'nullable|email|max:255',
            'phone'       => 'nullable|string|max:20',
            'notes'       => 'nullable|string',          // ★ 追加
        ]);
        $contact = Contact::create($validated);
        return new ContactResource($contact);
    }

    public function show(Contact $contact)
    {
        $contact->load(['customer', 'deals', 'activities']); // ★ eager load追加
        return new ContactResource($contact);
    }

    public function update(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'name'        => 'required|string|max:255',
            'department'  => 'nullable|string|max:100', // ★ 追加
            'position'    => 'nullable|string|max:100',
            'email'       => 'nullable|email|max:255',
            'phone'       => 'nullable|string|max:20',
            'notes'       => 'nullable|string',          // ★ 追加
        ]);
        $contact->update($validated);
        return new ContactResource($contact);
    }
    public function destroy(Contact $contact)
    {
        $contact->delete();
        return response()->json(null, 204);
    }
}
