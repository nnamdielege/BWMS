<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $logs = AuditLog::with('user:id,name')
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['subject'] ?? null, fn ($q, $v) => $q->where('auditable_type', 'like', "%{$v}%"))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'user' => $log->user?->name,
                'event' => $log->event,
                'subject' => $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'meta' => $log->meta,
                'ip_address' => $log->ip_address,
            ]);

        return Inertia::render('admin/audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
        ]);
    }
}
