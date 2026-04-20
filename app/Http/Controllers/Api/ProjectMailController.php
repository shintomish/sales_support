<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProposalMail;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\ProjectMailSource;
use App\Services\ClaudeService;
use App\Services\ProjectMailMatchingService;
use App\Services\ProjectMailScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProjectMailController extends Controller
{
    public function __construct(
        private ProjectMailScoringService  $scoringService,
        private ProjectMailMatchingService $matchingService,
        private ClaudeService              $claudeService,
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage   = $request->integer('per_page', 30);
        $status    = $request->input('status');    // new / review / proposed / interview / won / lost / excluded
        $scoreMin  = $request->integer('score_min', 0);
        $scoreMax  = $request->integer('score_max', 100);
        $search    = $request->string('search');

        $query = ProjectMailSource::with(['email:id,subject,from_name,from_address,body_text,received_at'])
            ->whereBetween('score', [$scoreMin, $scoreMax])
            ->orderByDesc('received_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            // デフォルト: excluded は除外
            $query->whereNotIn('status', ['excluded']);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('work_location', 'like', "%{$search}%")
                  ->orWhereHas('email', fn($eq) =>
                      $eq->where('subject', 'like', "%{$search}%")
                         ->orWhere('from_name', 'like', "%{$search}%")
                  );
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // 詳細（元メール・添付含む）
    public function show(int $id)
    {
        $pms = ProjectMailSource::with([
            'email.attachments',
        ])->findOrFail($id);

        return response()->json($pms);
    }

    // 抽出情報の手動修正
    public function update(Request $request, int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);

        $v = $request->validate([
            'customer_name'    => 'nullable|string|max:200',
            'sales_contact'    => 'nullable|string|max:100',
            'phone'            => 'nullable|string|max:50',
            'title'            => 'nullable|string|max:300',
            'required_skills'  => 'nullable|array',
            'required_skills.*'=> 'string|max:100',
            'preferred_skills' => 'nullable|array',
            'preferred_skills.*'=> 'string|max:100',
            'process'          => 'nullable|array',
            'process.*'        => 'string|max:100',
            'work_location'    => 'nullable|string|max:200',
            'remote_ok'        => 'nullable|boolean',
            'unit_price_min'   => 'nullable|numeric|min:0',
            'unit_price_max'   => 'nullable|numeric|min:0',
            'age_limit'        => 'nullable|string|max:50',
            'nationality_ok'   => 'nullable|boolean',
            'contract_type'    => 'nullable|string|max:50',
            'start_date'       => 'nullable|string|max:50',
            'supply_chain'     => 'nullable|integer|min:1|max:9',
        ]);

        $pms->update($v);

        return response()->json($pms->fresh());
    }

    // ステータス変更
    public function updateStatus(Request $request, int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);

        $v = $request->validate([
            'status'      => 'required|in:new,review,proposed,interview,won,lost,excluded',
            'lost_reason' => 'nullable|string',
        ]);

        $pms->update($v);

        return response()->json($pms->fresh());
    }

    // 再スコアリング（手動トリガー）
    public function rescore(int $id)
    {
        $pms = ProjectMailSource::with('email')->findOrFail($id);

        try {
            $updated = $this->scoringService->score($pms->email);
            return response()->json($updated->fresh());
        } catch (\Exception $e) {
            Log::error("Rescore failed email_id={$pms->email_id}: " . $e->getMessage());
            return response()->json(['message' => '再スコアリングに失敗しました'], 500);
        }
    }

    // 既存レコードを全件再スコアリング＋再抽出（バッチ処理対応）
    public function rescoreAll(Request $request): JsonResponse
    {
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        $batchSize = 300;
        $offset    = $request->integer('offset', 0);
        $count     = $this->scoringService->rescoreAll($batchSize, $offset);
        $total     = ProjectMailSource::whereNotNull('email_id')->count();
        $remaining = max(0, $total - ($offset + $count));

        return response()->json([
            'message'   => "{$count}件を再スコアリングしました",
            'count'     => $count,
            'remaining' => $remaining,
            'offset'    => $offset + $count,
        ]);
    }

    // 既存レコードの抽出情報を一括再計算（バッチ処理対応）
    public function reextractAll(Request $request): JsonResponse
    {
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        $batchSize = 300;
        $offset    = $request->integer('offset', 0);
        $count     = $this->scoringService->reextractAll($batchSize, $offset);
        $total     = ProjectMailSource::whereNotNull('email_id')->count();
        $remaining = max(0, $total - ($offset + $count));

        return response()->json([
            'message'   => "{$count}件の抽出情報を更新しました",
            'count'     => $count,
            'remaining' => $remaining,
            'offset'    => $offset + $count,
        ]);
    }

    /**
     * 提案メール草稿を生成
     * POST /v1/project-mails/{id}/generate-proposal
     */
    public function generateProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail     = ProjectMailSource::with('email')->where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate(['engineer_id' => 'required|integer']);

        $engineer = \App\Models\Engineer::with(['profile', 'engineerSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($v['engineer_id']);

        $mailData = [
            'title'           => $mail->title,
            'email_subject'   => $mail->email?->subject,
            'from_address'    => $mail->email?->from_address,
            'from_name'       => $mail->email?->from_name,
            'sales_contact'   => $mail->sales_contact,
            'required_skills' => $mail->required_skills ?? [],
            'work_location'   => $mail->work_location,
            'unit_price_min'  => $mail->unit_price_min,
            'unit_price_max'  => $mail->unit_price_max,
        ];

        $engineerData = [
            'name'                    => $engineer->name,
            'age'                     => $engineer->age,
            'affiliation'             => $engineer->affiliation,
            'availability_status'     => $engineer->profile?->availability_status,
            'available_from'          => $engineer->profile?->available_from,
            'desired_unit_price_min'  => $engineer->profile?->desired_unit_price_min,
            'desired_unit_price_max'  => $engineer->profile?->desired_unit_price_max,
            'skills' => $engineer->engineerSkills->map(fn($es) => [
                'name'             => $es->skill?->name,
                'experience_years' => $es->experience_years,
            ])->values()->toArray(),
        ];

        try {
            $draft = $this->claudeService->generateProposal($mailData, $engineerData);
            return response()->json($draft);
        } catch (\Exception $e) {
            Log::error("generateProposal failed mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール生成に失敗しました'], 500);
        }
    }

    /**
     * 提案メール送信
     * POST /v1/project-mails/{id}/send-proposal
     */
    public function sendProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'to'      => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body'    => 'required|string',
        ]);

        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'       => $tenantId,
            'send_type'       => 'proposal',
            'project_mail_id' => $id,
            'user_id'         => $userId,
            'subject'         => $v['subject'],
            'body'            => $v['body'],
            'total_count'     => 1,
            'success_count'   => 0,
            'failed_count'    => 0,
            'sent_at'         => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        try {
            Mail::to($v['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, [], $messageId));
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'sent',
                'ses_message_id' => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);
            Log::info("提案メール送信 project_mail_id={$id} to={$v['to']}");
            return response()->json(['message' => '送信しました']);
        } catch (\Exception $e) {
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'failed',
                'ses_message_id' => $messageId,
                'error_message'  => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);
            Log::error("提案メール送信失敗 project_mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール送信に失敗しました'], 500);
        }
    }

    /**
     * 一斉配信
     * POST /v1/project-mails/{id}/send-bulk
     */
    public function sendBulk(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'recipients'         => 'required|array|min:1|max:100',
            'recipients.*.to'   => 'required|email',
            'recipients.*.name' => 'nullable|string|max:200',
            'subject'            => 'required|string|max:500',
            'body'               => 'required|string',
        ]);

        $sent        = 0;
        $failed      = [];
        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'       => $tenantId,
            'send_type'       => 'bulk',
            'project_mail_id' => $id,
            'user_id'         => $userId,
            'subject'         => $v['subject'],
            'body'            => $v['body'],
            'total_count'     => count($v['recipients']),
            'success_count'   => 0,
            'failed_count'    => 0,
            'sent_at'         => now(),
        ]);

        foreach ($v['recipients'] as $recipient) {
            $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
            try {
                Mail::to($recipient['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, [], $messageId));
                DeliverySendHistory::create([
                    'tenant_id'      => $tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $recipient['to'],
                    'name'           => $recipient['name'] ?? null,
                    'status'         => 'sent',
                    'ses_message_id' => $messageId,
                ]);
                $sent++;
            } catch (\Exception $e) {
                DeliverySendHistory::create([
                    'tenant_id'      => $tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $recipient['to'],
                    'name'           => $recipient['name'] ?? null,
                    'status'         => 'failed',
                    'ses_message_id' => $messageId,
                    'error_message'  => $e->getMessage(),
                ]);
                Log::error("一斉配信失敗 project_mail_id={$id} to={$recipient['to']}: " . $e->getMessage());
                $failed[] = $recipient['to'];
            }
        }

        $campaign->update([
            'success_count' => $sent,
            'failed_count'  => count($failed),
        ]);

        Log::info("一斉配信完了 project_mail_id={$id} sent={$sent} failed=" . count($failed));

        return response()->json([
            'message' => "{$sent}件送信しました",
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }

    /**
     * 案件メールに対するマッチング技術者一覧
     * GET /v1/project-mails/{id}/matched-engineers
     */
    public function matchedEngineers(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $results = $this->matchingService->matchEngineers($mail, 20);

        return response()->json([
            'data' => $results->map(fn($r) => [
                'engineer_id'         => $r['engineer']->id,
                'engineer_name'       => $r['engineer']->name,
                'email'               => $r['engineer']->email,
                'affiliation'         => $r['engineer']->affiliation,
                'affiliation_contact' => $r['engineer']->affiliation_contact,
                'affiliation_email'   => $r['engineer']->affiliation_email,
                'affiliation_type'    => $r['engineer']->affiliation_type,
                'engineer_mail_source_id' => $r['engineer']->engineer_mail_source_id,
                'age'                 => $r['engineer']->age,
                'score'            => $r['score'],
                'breakdown'        => $r['breakdown'],
                'reasons'          => $r['reasons'],
                'availability_status'    => $r['engineer']->profile?->availability_status,
                'available_from'         => $r['engineer']->profile?->available_from,
                'work_style'             => $r['engineer']->profile?->work_style,
                'desired_unit_price_min' => $r['engineer']->profile?->desired_unit_price_min,
                'desired_unit_price_max' => $r['engineer']->profile?->desired_unit_price_max,
                'skills' => $r['engineer']->engineerSkills->map(fn($es) => [
                    'name'             => $es->skill?->name,
                    'experience_years' => $es->experience_years,
                ])->values(),
            ]),
        ]);
    }

    private function replyToAddress(): string
    {
        return config('mail.reply_to.address', config('mail.from.address')) ?? '';
    }
}
