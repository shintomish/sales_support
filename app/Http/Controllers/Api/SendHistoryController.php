<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailSendHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage   = $request->integer('per_page', 30);
        $sendType  = $request->input('send_type');   // proposal / bulk / matching_proposal
        $status    = $request->input('status');       // sent / failed
        $search    = $request->input('search');       // 宛先・件名で部分一致
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');

        $query = MailSendHistory::with([
                'projectMail:id,title,customer_name',
                'engineer:id,name',
                'publicProject:id,title',
                'sentBy:id,name',
            ])
            ->orderByDesc('sent_at');

        if ($sendType) {
            $query->where('send_type', $sendType);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('to_address', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('to_name', 'like', "%{$search}%");
            });
        }

        if ($dateFrom) {
            $query->where('sent_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo) {
            $query->where('sent_at', '<=', $dateTo . ' 23:59:59');
        }

        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(fn($h) => [
            'id'                 => $h->id,
            'send_type'          => $h->send_type,
            'status'             => $h->status,
            'to_address'         => $h->to_address,
            'to_name'            => $h->to_name,
            'subject'            => $h->subject,
            'sent_at'            => $h->sent_at?->toIso8601String(),
            'sent_by'            => $h->sentBy?->name,
            'error_message'      => $h->error_message,
            // 紐づき情報
            'project_mail_id'    => $h->project_mail_id,
            'project_mail_title' => $h->projectMail?->title,
            'engineer_id'        => $h->engineer_id,
            'engineer_name'      => $h->engineer?->name,
            'public_project_id'  => $h->public_project_id,
            'public_project_title' => $h->publicProject?->title,
        ]);

        return response()->json($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $h = MailSendHistory::with([
            'projectMail:id,title,customer_name',
            'engineer:id,name',
            'publicProject:id,title',
            'sentBy:id,name',
        ])->findOrFail($id);

        return response()->json([
            'id'                   => $h->id,
            'send_type'            => $h->send_type,
            'status'               => $h->status,
            'to_address'           => $h->to_address,
            'to_name'              => $h->to_name,
            'subject'              => $h->subject,
            'body'                 => $h->body,
            'sent_at'              => $h->sent_at?->toIso8601String(),
            'sent_by'              => $h->sentBy?->name,
            'error_message'        => $h->error_message,
            'project_mail_id'      => $h->project_mail_id,
            'project_mail_title'   => $h->projectMail?->title,
            'engineer_id'          => $h->engineer_id,
            'engineer_name'        => $h->engineer?->name,
            'public_project_id'    => $h->public_project_id,
            'public_project_title' => $h->publicProject?->title,
        ]);
    }
}
