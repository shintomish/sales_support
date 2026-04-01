<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\PublicProject;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    private const VALID_STATUSES = [
        'pending',
        'reviewing',
        'interview_scheduled',
        'interviewed',
        'offer',
        'accepted',
        'rejected',
        'withdrawn',
    ];

    private function formatApplication(Application $a): array
    {
        return [
            'id'                  => $a->id,
            'project_id'          => $a->project_id,
            'project_title'       => $a->project?->title,
            'engineer_id'         => $a->engineer_id,
            'engineer_name'       => $a->engineer?->name,
            'applied_by_user_id'  => $a->applied_by_user_id,
            'applied_by_name'     => $a->appliedByUser?->name,
            'message'             => $a->message,
            'proposed_unit_price' => $a->proposed_unit_price,
            'status'              => $a->status,
            'interview_date'      => $a->interview_date,
            'interview_memo'      => $a->interview_memo,
            'deal_id'             => $a->deal_id,
            'commission_rate'     => $a->commission_rate,
            'commission_amount'   => $a->commission_amount,
            'reviewed_at'         => $a->reviewed_at,
            'unread_message_count'=> $a->messages->where('is_read', false)->count(),
            'created_at'          => $a->created_at,
            'updated_at'          => $a->updated_at,
        ];
    }

    /**
     * 案件への応募一覧（掲載側が閲覧）
     */
    public function indexByProject(int $projectId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        PublicProject::where('tenant_id', $tenantId)->findOrFail($projectId);

        $applications = Application::with(['engineer', 'appliedByUser', 'messages'])
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $applications->map(fn(Application $a) => $this->formatApplication($a)),
        ]);
    }

    /**
     * 技術者の応募一覧（営業担当者が閲覧）
     */
    public function indexByEngineer(int $engineerId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $applications = Application::with(['project', 'appliedByUser', 'messages'])
            ->where('tenant_id', $tenantId)
            ->where('engineer_id', $engineerId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $applications->map(fn(Application $a) => $this->formatApplication($a)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $application = Application::with(['project.requiredSkills.skill', 'engineer.engineerSkills.skill', 'appliedByUser', 'messages.sender'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $this->formatApplication($application)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $v = $request->validate([
            'project_id'          => 'required|integer|exists:public_projects,id',
            'engineer_id'         => 'required|integer|exists:engineers,id',
            'message'             => 'nullable|string',
            'proposed_unit_price' => 'nullable|numeric|min:0',
        ]);

        // 案件がこのテナントのものか確認
        PublicProject::where('tenant_id', $tenantId)->findOrFail($v['project_id']);

        if (Application::where('project_id', $v['project_id'])->where('engineer_id', $v['engineer_id'])->exists()) {
            return response()->json(['message' => 'この技術者はすでにこの案件に応募済みです。'], 422);
        }

        $application = Application::create([
            'tenant_id'           => $tenantId,
            'project_id'          => $v['project_id'],
            'engineer_id'         => $v['engineer_id'],
            'applied_by_user_id'  => auth()->id(),
            'message'             => $v['message'] ?? null,
            'proposed_unit_price' => $v['proposed_unit_price'] ?? null,
            'status'              => 'pending',
        ]);

        // 応募数カウント更新
        PublicProject::where('id', $v['project_id'])->increment('applications_count');

        $application->load(['project', 'engineer', 'appliedByUser', 'messages']);

        return response()->json(['data' => $this->formatApplication($application)], 201);
    }

    /**
     * 選考ステータス更新
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $v = $request->validate([
            'status'           => 'required|in:' . implode(',', self::VALID_STATUSES),
            'interview_date'   => 'nullable|date',
            'interview_memo'   => 'nullable|string',
            'commission_rate'  => 'nullable|numeric|min:0|max:100',
            'commission_amount'=> 'nullable|numeric|min:0',
            'deal_id'          => 'nullable|integer|exists:deals,id',
        ]);

        $application = Application::where('tenant_id', $tenantId)->findOrFail($id);

        $application->update(array_filter([
            'status'            => $v['status'],
            'interview_date'    => $v['interview_date'] ?? null,
            'interview_memo'    => $v['interview_memo'] ?? null,
            'commission_rate'   => $v['commission_rate'] ?? null,
            'commission_amount' => $v['commission_amount'] ?? null,
            'deal_id'           => $v['deal_id'] ?? null,
            'reviewed_at'       => $application->reviewed_at ?? now(),
        ], fn($val) => $val !== null));

        $application->load(['project', 'engineer', 'appliedByUser', 'messages']);

        return response()->json(['data' => $this->formatApplication($application)]);
    }

    /**
     * メッセージ送信
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $senderId = auth()->id();

        $v = $request->validate([
            'receiver_user_id' => 'required|integer|exists:users,id',
            'content'          => 'required_without:file_path|nullable|string',
            'file_path'        => 'nullable|string|max:500',
        ]);

        $application = Application::where('tenant_id', $tenantId)->findOrFail($id);

        $message = Message::create([
            'tenant_id'        => $tenantId,
            'application_id'   => $application->id,
            'sender_user_id'   => $senderId,
            'receiver_user_id' => $v['receiver_user_id'],
            'content'          => $v['content'] ?? null,
            'file_path'        => $v['file_path'] ?? null,
        ]);

        $message->load('sender');

        return response()->json([
            'data' => [
                'id'               => $message->id,
                'application_id'   => $message->application_id,
                'sender_user_id'   => $message->sender_user_id,
                'sender_name'      => $message->sender?->name,
                'receiver_user_id' => $message->receiver_user_id,
                'content'          => $message->content,
                'file_path'        => $message->file_path,
                'is_read'          => $message->is_read,
                'created_at'       => $message->created_at,
            ],
        ], 201);
    }

    /**
     * メッセージ既読
     */
    public function readMessages(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();

        Application::where('tenant_id', $tenantId)->findOrFail($id);

        Message::where('application_id', $id)
            ->where('receiver_user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => '既読にしました。']);
    }
}
