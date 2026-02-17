<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Customer;
use App\Models\Deal;
use App\Http\Requests\TaskRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    // タスク一覧
    public function index(Request $request)
    {
        $query = Task::with(['customer', 'deal', 'user']);

        // 検索
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhereHas('customer', function($q) use ($request) {
                      $q->where('company_name', 'like', '%' . $request->search . '%');
                  });
        }

        // ステータスフィルター
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 優先度フィルター
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // 自分のタスクのみ
        if ($request->filled('my_tasks')) {
            $query->where('user_id', Auth::id());
        }

        $tasks = $query->orderByRaw("FIELD(priority, '高', '中', '低')")
                       ->orderBy('due_date', 'asc')
                       ->paginate(15);

        return view('tasks.index', compact('tasks'));
    }

    // タスク詳細
    public function show(Task $task)
    {
        $task->load(['customer', 'deal', 'user']);
        return view('tasks.show', compact('task'));
    }

    // タスク登録フォーム
    public function create(Request $request)
    {
        $customers = Customer::orderBy('company_name')->get();
        $deals     = collect();

        if ($request->filled('customer_id')) {
            $deals = Deal::where('customer_id', $request->customer_id)
                         ->whereNotIn('status', ['成約', '失注'])
                         ->get();
        }

        $customerId = $request->customer_id;

        return view('tasks.create', compact('customers', 'deals', 'customerId'));
    }

    // タスク登録処理
    public function store(TaskRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();

        Task::create($data);

        return redirect()->route('tasks.index')
                         ->with('success', 'タスクを登録しました。');
    }

    // タスク編集フォーム
    public function edit(Task $task)
    {
        $customers = Customer::orderBy('company_name')->get();
        $deals     = Deal::where('customer_id', $task->customer_id)
                         ->whereNotIn('status', ['成約', '失注'])
                         ->get();

        return view('tasks.edit', compact('task', 'customers', 'deals'));
    }

    // タスク更新処理
    public function update(TaskRequest $request, Task $task)
    {
        $task->update($request->validated());

        return redirect()->route('tasks.show', $task)
                         ->with('success', 'タスクを更新しました。');
    }

    // タスク削除処理
    public function destroy(Task $task)
    {
        $task->delete();

        return redirect()->route('tasks.index')
                         ->with('success', 'タスクを削除しました。');
    }

    // ステータスをAjaxで更新
    public function updateStatus(Request $request, Task $task)
    {
        $task->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }
}
